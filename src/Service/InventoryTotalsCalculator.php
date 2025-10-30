<?php

namespace Drupal\material_inventory_totals\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;

/**
 * Provides helpers to maintain cached inventory totals on materials.
 */
class InventoryTotalsCalculator {

  /**
   * Node storage handler.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected NodeStorageInterface $nodeStorage;

  /**
   * Material inventory storage handler.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $inventoryStorage;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * State storage, used for cron progress.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Lock backend.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected LockBackendInterface $lock;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs the calculator service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, StateInterface $state, LockBackendInterface $lock, LoggerChannelInterface $logger) {
    $this->nodeStorage = $entity_type_manager->getStorage('node');
    $this->inventoryStorage = $entity_type_manager->getStorage('material_inventory');
    $this->database = $database;
    $this->state = $state;
    $this->lock = $lock;
    $this->logger = $logger;
  }

  /**
   * Applies a delta to a material's cached totals.
   *
   * @param int $material_id
   *   The material node ID.
   * @param int $delta
   *   The quantity change to apply (positive adds stock, negative removes).
   */
  public function applyDelta(int $material_id, int $delta): void {
    if ($delta === 0) {
      return;
    }

    if (!$this->acquireLock($material_id)) {
      $this->logger->warning('Unable to acquire inventory lock for material @nid; update skipped.', ['@nid' => $material_id]);
      return;
    }

    try {
      $material = $this->loadMaterial($material_id);
      if (!$material) {
        return;
      }

      $stored_count = (int) $material->get('field_material_inventory_count')->value;
      $new_count = $stored_count + $delta;

      $this->persistTotals($material, $new_count);
    }
    finally {
      $this->releaseLock($material_id);
    }
  }

  /**
   * Fully recalculates inventory totals for a single material.
   *
   * @param int $material_id
   *   The material node ID.
   * @param bool $persist
   *   Whether to save the recalculated values back to the node.
   *
   * @return array
   *   Summary data keyed by:
   *     - stored_count: Original stored count (int|null).
   *     - stored_value: Original stored value (string|null).
   *     - calculated_count: Calculated count from adjustments (int).
   *     - calculated_value: Calculated value based on sales cost (string).
   *     - mismatch: TRUE if stored values differed from calculated values.
   */
  public function recalculate(int $material_id, bool $persist = TRUE): array {
    if (!$this->acquireLock($material_id)) {
      $this->logger->warning('Unable to acquire inventory lock for material @nid during rebuild; skipping.', ['@nid' => $material_id]);
      return [];
    }

    try {
      $material = $this->loadMaterial($material_id);
      if (!$material) {
        return [];
      }

      $stored_count = $material->get('field_material_inventory_count')->value;
      $stored_value = $material->get('field_material_inventory_value')->value;

      $calculated_count = $this->calculateQuantityFromAdjustments($material_id);
      $calculated_value = $this->formatInventoryValue($material, $calculated_count);

      $mismatch = ((string) $stored_count !== (string) $calculated_count) || ((string) $stored_value !== (string) $calculated_value);

      if ($persist && $mismatch) {
        $this->persistTotals($material, $calculated_count);
      }

      return [
        'stored_count' => isset($stored_count) ? (int) $stored_count : NULL,
        'stored_value' => $stored_value !== NULL ? $stored_value : NULL,
        'calculated_count' => $calculated_count,
        'calculated_value' => $calculated_value,
        'mismatch' => $mismatch,
      ];
    }
    finally {
      $this->releaseLock($material_id);
    }
  }

  /**
   * Runs a rolling consistency check across materials.
   *
   * @param int $limit
   *   How many material nodes to process this run.
   *
   * @return array
   *   Recalculation summaries keyed by node ID.
   */
  public function runConsistencyCheck(int $limit = 20): array {
    if ($limit <= 0) {
      return [];
    }

    $last_processed = (int) $this->state->get('material_inventory_totals.last_checked_nid', 0);

    $query = $this->nodeStorage->getQuery()
      ->condition('type', 'material')
      ->condition('nid', $last_processed, '>')
      ->sort('nid', 'ASC')
      ->range(0, $limit)
      ->accessCheck(FALSE);

    $material_ids = $query->execute();

    if (empty($material_ids)) {
      // Reset pointer and try from the beginning next time.
      $this->state->set('material_inventory_totals.last_checked_nid', 0);
      return [];
    }

    $summaries = [];
    foreach ($material_ids as $nid) {
      $summary = $this->recalculate((int) $nid);
      $summaries[$nid] = $summary;

      if (!empty($summary['mismatch'])) {
        $this->logger->warning('Inventory mismatch detected for material @nid during consistency check. Stored count @stored, recalculated @actual.', [
          '@nid' => $nid,
          '@stored' => $summary['stored_count'],
          '@actual' => $summary['calculated_count'],
        ]);
      }
    }

    $this->state->set('material_inventory_totals.last_checked_nid', max($material_ids));

    return $summaries;
  }

  /**
   * Returns the logger used by the service.
   */
  public function getLogger(): LoggerChannelInterface {
    return $this->logger;
  }

  /**
   * Loads a material node by ID.
   */
  protected function loadMaterial(int $material_id): ?NodeInterface {
    /** @var \Drupal\node\NodeInterface|null $material */
    $material = $this->nodeStorage->load($material_id);
    if (!$material || $material->bundle() !== 'material') {
      $this->logger->warning('Attempted to update inventory for unknown material node @nid.', [
        '@nid' => $material_id,
      ]);
      return NULL;
    }

    if (!$material->hasField('field_material_inventory_count') || !$material->hasField('field_material_inventory_value')) {
      $this->logger->warning('Material @nid is missing required inventory fields; skipping inventory total update.', [
        '@nid' => $material_id,
      ]);
      return NULL;
    }

    if (!$material->hasField('field_material_sales_cost')) {
      $this->logger->warning('Material @nid is missing sales cost field; inventory value will default to zero.', [
        '@nid' => $material_id,
      ]);
    }
    return $material;
  }

  /**
   * Persists calculated totals to the material node.
   */
  protected function persistTotals(NodeInterface $material, int $count): void {
    $formatted_value = $this->formatInventoryValue($material, $count);

    $material->set('field_material_inventory_count', $count);
    $material->set('field_material_inventory_value', $formatted_value);

    // Only save when values actually change to avoid unnecessary revisions.
    if ($material->isDirty()) {
      $material->save();
    }
  }

  /**
   * Calculates quantity total for a material using adjustment entities.
   */
  protected function calculateQuantityFromAdjustments(int $material_id): int {
    $query = $this->database->select('material_inventory__field_inventory_quantity_change', 'qty');
    $query->addExpression('COALESCE(SUM(qty.field_inventory_quantity_change_value), 0)', 'quantity_total');
    $query->join('material_inventory__field_inventory_ref_material', 'ref', 'qty.entity_id = ref.entity_id AND qty.langcode = ref.langcode');
    $query->join('material_inventory_field_data', 'base', 'qty.entity_id = base.id AND qty.langcode = base.langcode');
    $query->condition('base.type', 'inventory_adjustment');
    $query->condition('qty.deleted', 0);
    $query->condition('ref.deleted', 0);
    $query->condition('base.deleted', 0);
    $query->condition('ref.field_inventory_ref_material_target_id', $material_id);

    $result = $query->execute()->fetchField();
    return (int) $result;
  }

  /**
   * Formats the inventory value based on sales cost and quantity.
   */
  protected function formatInventoryValue(NodeInterface $material, int $quantity): string {
    if (!$material->hasField('field_material_sales_cost')) {
      return number_format(0, 2, '.', '');
    }

    $sales_cost = $material->get('field_material_sales_cost')->value;
    if ($sales_cost === NULL || $sales_cost === '') {
      return '0.00';
    }

    $number = (float) $sales_cost * $quantity;
    return number_format((float) $number, 2, '.', '');
  }

  /**
   * Attempts to acquire an exclusive lock for a material inventory update.
   */
  protected function acquireLock(int $material_id): bool {
    return $this->lock->acquire($this->getLockName($material_id), 30.0);
  }

  /**
   * Releases a previously acquired lock.
   */
  protected function releaseLock(int $material_id): void {
    $this->lock->release($this->getLockName($material_id));
  }

  /**
   * Builds the lock name for a material ID.
   */
  protected function getLockName(int $material_id): string {
    return 'material_inventory_totals:' . $material_id;
  }
}
