<?php

namespace Drupal\material_inventory_totals\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\material_inventory_totals\CountReasons;
use Drupal\material_inventory_totals\Service\InventoryTotalsCalculator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST API endpoints for the mobile inventory app.
 */
class InventoryApiController extends ControllerBase {

  /**
   * The inventory totals calculator.
   *
   * @var \Drupal\material_inventory_totals\Service\InventoryTotalsCalculator
   */
  protected InventoryTotalsCalculator $calculator;

  /**
   * Constructs the controller.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    InventoryTotalsCalculator $calculator,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->calculator = $calculator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('material_inventory_totals.calculator'),
    );
  }

  /**
   * GET /api/v1/inventory/item/{nid}
   *
   * Returns material details for the inventory app.
   */
  public function getItem(int $nid): JsonResponse {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node || $node->bundle() !== 'material') {
      return new JsonResponse(['error' => 'Material not found.'], 404);
    }

    if (!$node->access('view', $this->currentUser())) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $last_inventoried = NULL;
    if ($node->hasField('field_material_last_inventoried') && !$node->get('field_material_last_inventoried')->isEmpty()) {
      $ts = (int) $node->get('field_material_last_inventoried')->value;
      $last_inventoried = \Drupal::service('date.formatter')->format($ts, 'custom', 'M j, Y g:i a');
    }

    $summary = $this->calculator->recalculate($nid, FALSE);
    $current_count = $summary['calculated_count'] ?? (int) $node->get('field_material_inventory_count')->value;

    // Resolve the image URL from field_material_image.
    $image_url = NULL;
    if ($node->hasField('field_material_image') && !$node->get('field_material_image')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $node->get('field_material_image')->entity;
      if ($file) {
        $image_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      }
    }

    // Resolve the canonical alias (falls back to /node/{nid}).
    $alias = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $nid);
    $drupal_url = \Drupal::request()->getSchemeAndHttpHost() . $alias;

    // Source/supplier link for reorder requests.
    $source_url = NULL;
    if ($node->hasField('field_material_source') && !$node->get('field_material_source')->isEmpty()) {
      $source_url = $node->get('field_material_source')->uri;
    }

    return new JsonResponse([
      'nid'               => $nid,
      'title'             => $node->label(),
      'unit'              => $node->get('field_material_unit')->value ?? '',
      'current_count'     => $current_count,
      'backstock_location' => $node->get('field_material_backstock')->value ?: NULL,
      'last_inventoried'  => $last_inventoried,
      'image_url'         => $image_url,
      'drupal_url'        => $drupal_url,
      'source_url'        => $source_url,
      // Choices a client must offer when the count comes in above
      // current_count; send the chosen value back as positive_reason.
      'positive_reasons'  => self::positiveReasonsPayload(),
    ]);
  }

  /**
   * POST /api/v1/inventory/count
   *
   * Accepts a physical count total, records the delta as an inventory
   * adjustment, and stamps the last-inventoried date on the material.
   *
   * Request body (JSON): { nid, total_count, memo?, positive_reason? }
   *
   * positive_reason is one of the values listed in the item's
   * positive_reasons and says why a count came in above the system count:
   * a restock nobody logged, or stock that was already here. Clients that
   * omit it on a positive count get the legacy unclassified reason.
   * Response (JSON):     { success, new_count, delta, message }
   */
  public function submitCount(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    $nid = isset($data['nid']) ? (int) $data['nid'] : NULL;
    $total_count = isset($data['total_count']) ? (int) $data['total_count'] : NULL;
    $memo = $data['memo'] ?? '';
    $positive_reason = isset($data['positive_reason']) && $data['positive_reason'] !== '' ? (string) $data['positive_reason'] : NULL;

    if (!$nid || $total_count === NULL) {
      return new JsonResponse(['error' => 'Missing required fields: nid and total_count.'], 400);
    }

    if ($total_count < 0) {
      return new JsonResponse(['error' => 'total_count cannot be negative.'], 400);
    }

    if ($positive_reason !== NULL && !isset(CountReasons::positiveChoices()[$positive_reason])) {
      return new JsonResponse([
        'error' => 'Unknown positive_reason.',
        'allowed' => array_keys(CountReasons::positiveChoices()),
      ], 400);
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node || $node->bundle() !== 'material') {
      return new JsonResponse(['error' => 'Material not found.'], 404);
    }

    if (!$node->access('view', $this->currentUser())) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $summary = $this->calculator->recalculate($nid, FALSE);
    $current_count = $summary['calculated_count'] ?? (int) $node->get('field_material_inventory_count')->value;
    $delta = $total_count - $current_count;

    $reason = CountReasons::forDelta($delta, $positive_reason);

    try {
      $adjustment = $this->entityTypeManager->getStorage('material_inventory')->create([
        'type'                          => 'inventory_adjustment',
        'field_inventory_ref_material'  => $nid,
        'field_inventory_quantity_change' => $delta,
        'field_inventory_change_reason' => $reason,
        'field_inventory_change_memo'   => $memo,
      ]);
      // Saving the adjustment triggers material_inventory_totals_entity_insert(),
      // which applies the delta and stamps field_material_last_inventoried on
      // the material for physical-count reasons like these.
      $adjustment->save();
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Failed to save: ' . $e->getMessage()], 500);
    }

    if ($delta === 0) {
      $message = 'Count verified — no change.';
    }
    elseif ($reason === CountReasons::RESTOCK_UNLOGGED) {
      $message = sprintf('Count updated: +%d recorded as a restock that wasn\'t logged.', $delta);
    }
    elseif ($reason === CountReasons::COUNT_FOUND) {
      $message = sprintf('Count updated: +%d recorded as a count correction (found).', $delta);
    }
    elseif ($delta > 0) {
      $message = sprintf('Count updated. Positive correction: +%d.', $delta);
    }
    else {
      $message = sprintf('Count updated. Shrinkage: %d.', $delta);
    }

    return new JsonResponse([
      'success'   => TRUE,
      'new_count' => $total_count,
      'delta'     => $delta,
      'reason'    => $reason,
      'message'   => $message,
    ]);
  }

  /**
   * Lists the positive-count reasons for API clients.
   */
  protected static function positiveReasonsPayload(): array {
    $out = [];
    foreach (CountReasons::positiveChoices() as $value => $choice) {
      $out[] = [
        'value' => $value,
        'label' => (string) $choice['label'],
        'help' => (string) $choice['help'],
      ];
    }
    return $out;
  }

}
