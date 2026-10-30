<?php

namespace Drupal\material_inventory_totals\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\material_inventory_totals\CountReasons;
use Drupal\material_inventory_totals\InventoryFlagRules;
use Drupal\node\NodeInterface;

/**
 * Runs a store inventory: start, complete (flag), resolve flags, finalize.
 *
 * Counts update stock the moment they are saved, as always. The inventory
 * only decides which counts get a second look, and the person rechecking
 * corrects the original count in place rather than adding another one.
 */
class InventorySessionManager {

  /**
   * Where the finalize summary goes unless settings.php says otherwise.
   */
  public const DEFAULT_SUMMARY_EMAIL = 'staff@makehaven.org';

  /**
   * Flag statuses that count as handled.
   */
  public const RESOLVED = ['confirmed', 'recounted', 'not_sure'];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected TimeInterface $time,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * The inventory that has been started but not finalized, if any.
   */
  public function current(): ?object {
    return $this->database->select('material_inventory_session', 's')
      ->fields('s')
      ->isNull('finalized')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchObject() ?: NULL;
  }

  /**
   * Loads one inventory.
   */
  public function load(int $id): ?object {
    return $this->database->select('material_inventory_session', 's')
      ->fields('s')
      ->condition('id', $id)
      ->execute()
      ->fetchObject() ?: NULL;
  }

  /**
   * Finalized inventories, newest first.
   */
  public function history(int $limit = 12): array {
    return $this->database->select('material_inventory_session', 's')
      ->fields('s')
      ->isNotNull('finalized')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Starts an inventory. Only one can be open at a time.
   */
  public function start(int $uid): int {
    if ($this->current()) {
      throw new \RuntimeException('An inventory is already open.');
    }
    return (int) $this->database->insert('material_inventory_session')
      ->fields(['started' => $this->time->getRequestTime(), 'started_by' => $uid])
      ->execute();
  }

  /**
   * Ends counting and flags the counts that need a second look.
   *
   * @return int
   *   How many items were flagged.
   */
  public function complete(object $session, int $uid): int {
    if ($session->completed) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $flagged = 0;
    foreach ($this->latestCounts((int) $session->started, $now) as $adjustment) {
      // Weighed or estimated items (glazes, roll paper) are often zeroed
      // out on purpose, so a big swing there is expected.
      if ($this->isEstimated($adjustment->get('field_inventory_ref_material')->entity)) {
        continue;
      }
      $context = $this->countContext($adjustment);
      $flags = InventoryFlagRules::evaluate($context);
      if (!$flags) {
        continue;
      }
      $this->database->insert('material_inventory_session_flag')
        ->fields([
          'session_id' => $session->id,
          'adjustment_id' => $adjustment->id(),
          'material_id' => $context['material_id'],
          'flags' => implode(',', $flags),
          'delta' => $context['delta'],
          'value' => round(abs($context['delta']) * $context['unit_cost'], 2),
          'status' => 'open',
        ])
        ->execute();
      $flagged++;
    }
    $this->database->update('material_inventory_session')
      ->fields(['completed' => $now, 'completed_by' => $uid])
      ->condition('id', $session->id)
      ->execute();
    return $flagged;
  }

  /**
   * Flags for an inventory, biggest value first, open ones first.
   */
  public function flags(int $session_id): array {
    $query = $this->database->select('material_inventory_session_flag', 'f')
      ->fields('f')
      ->condition('session_id', $session_id);
    $query->addExpression("CASE WHEN f.status = 'open' THEN 0 ELSE 1 END", 'is_done');
    return $query->orderBy('is_done')
      ->orderBy('value', 'DESC')
      ->execute()
      ->fetchAll();
  }

  /**
   * Loads one flag.
   */
  public function flag(int $id): ?object {
    return $this->database->select('material_inventory_session_flag', 'f')
      ->fields('f')
      ->condition('id', $id)
      ->execute()
      ->fetchObject() ?: NULL;
  }

  /**
   * How many flags in an inventory are still open.
   */
  public function openFlagCount(int $session_id): int {
    return (int) $this->database->select('material_inventory_session_flag', 'f')
      ->condition('session_id', $session_id)
      ->condition('status', 'open')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * How many counts have been saved since the inventory started.
   */
  public function countsSoFar(object $session): int {
    return count($this->latestCounts((int) $session->started, $this->windowEnd($session)));
  }

  /**
   * When an inventory's counts stop: at finalize, not at complete.
   *
   * Weighed items are often counted a day or two after the rest, once the
   * recheck list is out. They are never flagged, but they belong in the
   * summary.
   */
  protected function windowEnd(object $session): int {
    return (int) (($session->finalized ?? NULL) ?: $this->time->getRequestTime());
  }

  /**
   * The count was right. A count above the system count needs its reason.
   */
  public function confirm(object $flag, int $uid, ?string $positive_reason, string $note = ''): void {
    $adjustment = $this->adjustment((int) $flag->adjustment_id);
    if ($adjustment && (int) $adjustment->get('field_inventory_quantity_change')->value > 0 && $positive_reason) {
      $adjustment->set('field_inventory_change_reason', $positive_reason);
      $this->appendMemo($adjustment, 'Confirmed on recheck');
      $adjustment->save();
    }
    $this->resolve($flag, 'confirmed', $uid, $note);
  }

  /**
   * A recount: the original count is corrected so stock equals what is there.
   *
   * @param object $flag
   *   The flag row.
   * @param int $uid
   *   Who recounted.
   * @param int $total
   *   What is on the shelf (and in backstock) now.
   * @param string|null $positive_reason
   *   Why, when the corrected count is still above the system count.
   * @param string $note
   *   Optional note.
   */
  public function recount(object $flag, int $uid, int $total, ?string $positive_reason, string $note = ''): void {
    $adjustment = $this->adjustment((int) $flag->adjustment_id);
    if (!$adjustment) {
      $this->resolve($flag, 'recounted', $uid, $note);
      return;
    }
    $material_id = (int) $flag->material_id;
    $stock_now = $this->stockNow($material_id);
    $old = (int) $adjustment->get('field_inventory_quantity_change')->value;
    $new = $old + ($total - $stock_now);
    $adjustment->set('field_inventory_quantity_change', $new);
    $adjustment->set('field_inventory_change_reason', CountReasons::forDelta($new, $positive_reason));
    $this->appendMemo($adjustment, sprintf('Recounted: %+d became %+d', $old, $new));
    // The update hook applies the difference to the material's totals.
    $adjustment->save();
    $this->resolve($flag, 'recounted', $uid, $note);
  }

  /**
   * Nobody can tell yet. Staff pick it up on the count review page.
   */
  public function notSure(object $flag, int $uid, string $note = ''): void {
    $adjustment = $this->adjustment((int) $flag->adjustment_id);
    if ($adjustment) {
      $adjustment->set('field_inventory_change_reason', CountReasons::NEEDS_REVIEW);
      if ($note !== '') {
        $this->appendMemo($adjustment, $note);
      }
      $adjustment->save();
    }
    $this->resolve($flag, 'not_sure', $uid, $note);
  }

  /**
   * Closes the inventory, saves its summary and emails it to staff.
   */
  public function finalize(object $session, int $uid): array {
    if (!$session->completed || $this->openFlagCount((int) $session->id) > 0) {
      throw new \RuntimeException('Every flagged item must be handled before finalizing.');
    }
    $now = $this->time->getRequestTime();
    $session->finalized = $now;
    $session->finalized_by = $uid;
    $summary = $this->summarize($session);
    $this->database->update('material_inventory_session')
      ->fields([
        'finalized' => $now,
        'finalized_by' => $uid,
        'summary' => json_encode($summary),
      ])
      ->condition('id', $session->id)
      ->execute();

    $to = Settings::get('material_inventory_summary_email', self::DEFAULT_SUMMARY_EMAIL);
    if ($to) {
      $url = Url::fromRoute('material_inventory_totals.inventory', [], [
        'query' => ['session' => $session->id],
        'absolute' => TRUE,
      ]);
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
      $this->mailManager->mail('material_inventory_totals', 'inventory_summary', $to, $langcode, [
        'summary' => $summary,
        'url' => $url->toString(),
      ]);
    }
    return $summary;
  }

  /**
   * Works out the numbers staff see when an inventory is finalized.
   */
  public function summarize(object $session): array {
    $counts = $this->latestCounts((int) $session->started, $this->windowEnd($session));
    $summary = [
      'started' => (int) $session->started,
      'finalized' => (int) ($session->finalized ?? 0),
      'items_counted' => count($counts),
      'matched' => 0,
      'estimated' => 0,
      'over' => ['n' => 0, 'value' => 0.0, 'by_reason' => []],
      'short' => ['n' => 0, 'value' => 0.0],
      'flags' => ['total' => 0, 'confirmed' => 0, 'recounted' => 0, 'not_sure' => 0],
      'not_sure_items' => [],
    ];
    $reason_labels = $this->reasonLabels();
    foreach ($counts as $adjustment) {
      $delta = (int) $adjustment->get('field_inventory_quantity_change')->value;
      $material = $adjustment->get('field_inventory_ref_material')->entity;
      $value = abs($delta) * $this->unitCost($material);
      if ($this->isEstimated($material)) {
        // Reported on their own: zeroing out is not a loss.
        $summary['estimated']++;
      }
      elseif ($delta === 0) {
        $summary['matched']++;
      }
      elseif ($delta > 0) {
        $summary['over']['n']++;
        $summary['over']['value'] += $value;
        $label = (string) ($reason_labels[$adjustment->get('field_inventory_change_reason')->value] ?? $adjustment->get('field_inventory_change_reason')->value);
        $summary['over']['by_reason'][$label] = ($summary['over']['by_reason'][$label] ?? 0) + 1;
      }
      else {
        $summary['short']['n']++;
        $summary['short']['value'] += $value;
      }
    }
    foreach ($this->flags((int) $session->id) as $flag) {
      $summary['flags']['total']++;
      if (isset($summary['flags'][$flag->status])) {
        $summary['flags'][$flag->status]++;
      }
      if ($flag->status === 'not_sure') {
        $material = $this->entityTypeManager->getStorage('node')->load($flag->material_id);
        $summary['not_sure_items'][] = $material ? $material->label() : '#' . $flag->material_id;
      }
    }
    $summary['over']['value'] = round($summary['over']['value'], 2);
    $summary['short']['value'] = round($summary['short']['value'], 2);
    return $summary;
  }

  /**
   * The facts the flag rules need about one count.
   */
  public function countContext(ContentEntityInterface $adjustment): array {
    $id = (int) $adjustment->id();
    $material_id = (int) $adjustment->get('field_inventory_ref_material')->target_id;
    $material = $adjustment->get('field_inventory_ref_material')->entity;
    $previous = $this->previousCount($material_id, $id);
    return [
      'material_id' => $material_id,
      'delta' => (int) $adjustment->get('field_inventory_quantity_change')->value,
      'system_before' => $this->stockBefore($material_id, $id),
      'previous_delta' => $previous ? (int) $previous->get('field_inventory_quantity_change')->value : NULL,
      'restock_since_previous' => $this->restockBetween($material_id, $previous ? (int) $previous->id() : 0, $id),
      'unit_cost' => $this->unitCost($material),
      'has_backstock' => $material instanceof NodeInterface && $material->hasField('field_material_backstock') && !$material->get('field_material_backstock')->isEmpty(),
    ];
  }

  /**
   * Each item's latest count saved in the window.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Adjustments keyed by material ID.
   */
  protected function latestCounts(int $from, int $to): array {
    $ids = $this->adjustmentQuery()
      ->condition('field_inventory_change_reason', MATERIAL_INVENTORY_TOTALS_PHYSICAL_COUNT_REASONS, 'IN')
      ->condition('created', $from, '>=')
      ->condition('created', $to, '<=')
      ->sort('id')
      ->execute();
    $latest = [];
    foreach ($this->entityTypeManager->getStorage('material_inventory')->loadMultiple($ids) as $adjustment) {
      $latest[(int) $adjustment->get('field_inventory_ref_material')->target_id] = $adjustment;
    }
    return $latest;
  }

  /**
   * The item's count before this one, if any.
   */
  protected function previousCount(int $material_id, int $before_id): ?ContentEntityInterface {
    $ids = $this->adjustmentQuery()
      ->condition('field_inventory_ref_material', $material_id)
      ->condition('field_inventory_change_reason', MATERIAL_INVENTORY_TOTALS_PHYSICAL_COUNT_REASONS, 'IN')
      ->condition('id', $before_id, '<')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    return $ids ? $this->adjustment((int) reset($ids)) : NULL;
  }

  /**
   * Whether a delivery was logged for the item between two adjustments.
   *
   * Items taken off a store tab are also saved as "restock"; their memo
   * marks them, and they are not deliveries.
   */
  protected function restockBetween(int $material_id, int $after_id, int $before_id): bool {
    $query = $this->adjustmentQuery()
      ->condition('field_inventory_ref_material', $material_id)
      ->condition('field_inventory_change_reason', CountReasons::RESTOCK)
      ->condition('id', $after_id, '>')
      ->condition('id', $before_id, '<');
    $memo = $query->orConditionGroup()
      ->notExists('field_inventory_change_memo')
      ->condition($query->andConditionGroup()
        ->condition('field_inventory_change_memo', 'Removed from tab%', 'NOT LIKE')
        ->condition('field_inventory_change_memo', 'Not a restock%', 'NOT LIKE'));
    return (bool) $query->condition($memo)->count()->execute();
  }

  /**
   * The system count for an item just before the given adjustment.
   */
  protected function stockBefore(int $material_id, int $before_id): int {
    return $this->sumQuantity($material_id, $before_id);
  }

  /**
   * The item's system count now.
   */
  public function stockNow(int $material_id): int {
    return $this->sumQuantity($material_id, NULL);
  }

  /**
   * Sums quantity changes for an item, optionally only before an ID.
   */
  protected function sumQuantity(int $material_id, ?int $before_id): int {
    $storage = $this->entityTypeManager->getStorage('material_inventory');
    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $mapping */
    $mapping = $storage->getTableMapping();
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions('material_inventory');
    $quantity = $definitions['field_inventory_quantity_change'];
    $reference = $definitions['field_inventory_ref_material'];
    $query = $this->database->select($mapping->getFieldTableName('field_inventory_quantity_change'), 'q');
    $query->join($mapping->getFieldTableName('field_inventory_ref_material'), 'r', 'r.entity_id = q.entity_id');
    $query->join($mapping->getBaseTable(), 'b', 'b.id = q.entity_id');
    $query->addExpression(sprintf('COALESCE(SUM(q.%s), 0)', $mapping->getFieldColumnName($quantity, 'value')), 'total');
    $query->condition('r.' . $mapping->getFieldColumnName($reference, 'target_id'), $material_id);
    $query->condition('b.type', 'inventory_adjustment');
    $query->condition('q.deleted', 0);
    if ($before_id !== NULL) {
      $query->condition('q.entity_id', $before_id, '<');
    }
    return (int) $query->execute()->fetchField();
  }

  /**
   * Base query over inventory adjustments.
   */
  protected function adjustmentQuery() {
    return $this->entityTypeManager->getStorage('material_inventory')->getQuery()
      ->condition('type', 'inventory_adjustment')
      ->accessCheck(FALSE);
  }

  /**
   * Loads one adjustment.
   */
  protected function adjustment(int $id): ?ContentEntityInterface {
    $adjustment = $this->entityTypeManager->getStorage('material_inventory')->load($id);
    return $adjustment instanceof ContentEntityInterface ? $adjustment : NULL;
  }

  /**
   * Marks a flag handled.
   */
  protected function resolve(object $flag, string $status, int $uid, string $note): void {
    $this->database->update('material_inventory_session_flag')
      ->fields([
        'status' => $status,
        'resolved' => $this->time->getRequestTime(),
        'resolved_by' => $uid,
        'note' => mb_substr($note, 0, 255) ?: NULL,
      ])
      ->condition('id', $flag->id)
      ->execute();
  }

  /**
   * Adds to an adjustment's memo, within the field's 255 characters.
   */
  protected function appendMemo(ContentEntityInterface $adjustment, string $text): void {
    $memo = trim((string) $adjustment->get('field_inventory_change_memo')->value);
    $adjustment->set('field_inventory_change_memo', mb_substr($memo === '' ? $text : $memo . ' | ' . $text, 0, 255));
  }

  /**
   * Whether the item's stock is estimated (weighed, measured on a roll).
   */
  public function isEstimated($material): bool {
    return $material instanceof NodeInterface
      && $material->hasField('field_material_inventory_method')
      && $material->get('field_material_inventory_method')->value === 'estimated';
  }

  /**
   * Store price per unit for a material.
   */
  public function unitCost($material): float {
    if (!$material instanceof NodeInterface || !$material->hasField('field_material_unit_cost')) {
      return 0.0;
    }
    return (float) $material->get('field_material_unit_cost')->value;
  }

  /**
   * Labels for the adjustment reason codes.
   */
  public function reasonLabels(): array {
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions('material_inventory');
    return $definitions['field_inventory_change_reason']->getSetting('allowed_values') ?? [];
  }

}
