<?php

namespace Drupal\material_inventory_totals\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the store statistics dashboard.
 */
class StoreStatsController extends ControllerBase {

  /**
   * Keeps track of missing tables we already logged for this request.
   *
   * @var array
   */
  protected array $missingTablesLogged = [];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  /**
   * Constructs the StoreStatsController.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database, TimeInterface $time) {
    $this->database = $database;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('datetime.time')
    );
  }

  /**
   * Builds the dashboard content.
   */
  public function content() {
    $stats = $this->buildStoreStats();

    return [
      '#theme' => 'material_inventory_store_stats',
      '#stats' => $stats,
      '#attached' => [
        'library' => [
          'material_inventory_totals/store_stats',
        ],
      ],
      '#cache' => [
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Builds the aggregate data used by the dashboard template.
   */
  protected function buildStoreStats(): array {
    $generated = $this->time->getRequestTime();

    $sales_tables = $this->getSalesTables();
    $sales_available = $this->requiredTablesAvailable($sales_tables);

    $inventory_tables = $this->getInventoryTables();
    $inventory_available = $this->requiredTablesAvailable($inventory_tables);

    $stats = [
      'generated' => $generated,
      'sales' => [
        'available' => $sales_available,
      ],
      'inventory' => [
        'available' => $inventory_available,
      ],
    ];

    if ($sales_available) {
      $stats['sales']['overall'] = $this->getSalesSummary();
      $stats['sales']['last_30_days'] = $this->getSalesSummary(30);
      $stats['sales']['last_7_days'] = $this->getSalesSummary(7);
      $stats['sales']['top_materials'] = $this->getTopMaterials();
      $stats['sales']['top_materials_recent'] = $this->getTopMaterials(5, 30);
      $stats['sales']['recent_activity'] = $this->getRecentSales();
      $stats['sales']['trend'] = $this->getDailyTrend();
      $stats['sales']['monthly_purchases'] = $this->getMonthlyPurchaseTrend();
    }

    if ($inventory_available) {
      $stats['inventory']['snapshot'] = $this->getInventorySnapshot();
      $stats['inventory']['low_stock'] = $this->getLowStockMaterials();
      if ($sales_available) {
        $stats['inventory']['reason_quarters'] = $this->getQuarterlyReasonSummary();
      }
    }

    return $stats;
  }

  /**
   * Returns a summarized view of sales.
   */
  protected function getSalesSummary(?int $days = NULL): array {
    $query = $this->database->select('material_inventory', 'base');
    $query->join('material_inventory__field_inventory_quantity_change', 'qty', 'base.id = qty.entity_id');
    $query->join('material_inventory__field_inventory_ref_material', 'ref', 'base.id = ref.entity_id');
    $query->leftJoin('node_field_data', 'node', 'ref.field_inventory_ref_material_target_id = node.nid');
    $query->leftJoin('node__field_material_sales_cost', 'cost', 'node.nid = cost.entity_id AND cost.delta = 0 AND cost.langcode = node.langcode');

    $query->addExpression('SUM(ABS(qty.field_inventory_quantity_change_value))', 'total_items');
    $query->addExpression('SUM(ABS(qty.field_inventory_quantity_change_value) * COALESCE(cost.field_material_sales_cost_value, 0))', 'total_revenue');
    $query->addExpression('COUNT(DISTINCT base.id)', 'total_transactions');
    $query->addExpression('COUNT(DISTINCT ref.field_inventory_ref_material_target_id)', 'unique_materials');

    $query->condition('base.type', 'inventory_adjustment');
    $query->condition('qty.field_inventory_quantity_change_value', 0, '<');

    if ($days) {
      $query->condition('base.created', $this->time->getRequestTime() - ($days * 86400), '>=');
    }

    $result = $query->execute()->fetchAssoc() ?: [];

    $items = (int) ($result['total_items'] ?? 0);
    $revenue = (float) ($result['total_revenue'] ?? 0);
    $transactions = (int) ($result['total_transactions'] ?? 0);

    return [
      'items' => $items,
      'revenue' => $revenue,
      'transactions' => $transactions,
      'unique_materials' => (int) ($result['unique_materials'] ?? 0),
      'avg_item_value' => $items > 0 ? $revenue / $items : 0.0,
      'avg_transaction_value' => $transactions > 0 ? $revenue / $transactions : 0.0,
    ];
  }

  /**
   * Loads the top selling materials.
   */
  protected function getTopMaterials(int $limit = 5, ?int $days = NULL): array {
    $query = $this->database->select('material_inventory', 'base');
    $query->join('material_inventory__field_inventory_quantity_change', 'qty', 'base.id = qty.entity_id');
    $query->join('material_inventory__field_inventory_ref_material', 'ref', 'base.id = ref.entity_id');
    $query->leftJoin('node_field_data', 'node', 'ref.field_inventory_ref_material_target_id = node.nid');
    $query->leftJoin('node__field_material_sales_cost', 'cost', 'node.nid = cost.entity_id AND cost.delta = 0 AND cost.langcode = node.langcode');

    $query->addField('ref', 'field_inventory_ref_material_target_id', 'material_id');
    $query->addField('node', 'title', 'material_name');
    $query->addExpression('SUM(ABS(qty.field_inventory_quantity_change_value))', 'total_sold');
    $query->addExpression('SUM(ABS(qty.field_inventory_quantity_change_value) * COALESCE(cost.field_material_sales_cost_value, 0))', 'total_revenue');

    $query->condition('base.type', 'inventory_adjustment');
    $query->condition('qty.field_inventory_quantity_change_value', 0, '<');

    if ($days) {
      $query->condition('base.created', $this->time->getRequestTime() - ($days * 86400), '>=');
    }

    $query->groupBy('ref.field_inventory_ref_material_target_id');
    $query->groupBy('node.title');
    $query->orderBy('total_sold', 'DESC');
    $query->range(0, $limit);

    $results = $query->execute()->fetchAll();
    $materials = [];
    foreach ($results as $row) {
      $materials[] = [
        'material_id' => (int) $row->material_id,
        'name' => $row->material_name ?: (string) $this->t('Unknown material'),
        'quantity' => (int) $row->total_sold,
        'revenue' => (float) $row->total_revenue,
      ];
    }

    return $materials;
  }

  /**
   * Builds recent sales activity feed.
   */
  protected function getRecentSales(int $limit = 6): array {
    $query = $this->database->select('material_inventory', 'base');
    $query->join('material_inventory__field_inventory_quantity_change', 'qty', 'base.id = qty.entity_id');
    $query->join('material_inventory__field_inventory_ref_material', 'ref', 'base.id = ref.entity_id');
    $query->leftJoin('node_field_data', 'node', 'ref.field_inventory_ref_material_target_id = node.nid');
    $query->leftJoin('node__field_material_sales_cost', 'cost', 'node.nid = cost.entity_id AND cost.delta = 0 AND cost.langcode = node.langcode');

    $query->addField('base', 'id', 'adjustment_id');
    $query->addField('base', 'created');
    $query->addField('node', 'title', 'material_name');
    $query->addField('ref', 'field_inventory_ref_material_target_id', 'material_id');
    $query->addField('qty', 'field_inventory_quantity_change_value', 'quantity');
    $query->addField('cost', 'field_material_sales_cost_value', 'unit_price');

    $query->condition('base.type', 'inventory_adjustment');
    $query->condition('qty.field_inventory_quantity_change_value', 0, '<');
    $query->orderBy('base.created', 'DESC');
    $query->range(0, $limit);

    $results = $query->execute()->fetchAll();
    $rows = [];
    foreach ($results as $row) {
      $quantity = abs((int) $row->quantity);
      $unit_price = (float) ($row->unit_price ?? 0);
      $rows[] = [
        'id' => (int) $row->adjustment_id,
        'material_id' => (int) $row->material_id,
        'name' => $row->material_name ?: (string) $this->t('Unknown material'),
        'quantity' => $quantity,
        'unit_price' => $unit_price,
        'total' => $unit_price * $quantity,
        'timestamp' => (int) $row->created,
      ];
    }

    return $rows;
  }

  /**
   * Builds day-by-day sales trend data.
   */
  protected function getDailyTrend(int $days = 30): array {
    $daily_stats = [];
    $start = $this->time->getRequestTime() - ($days * 86400);
    for ($i = 0; $i <= $days; $i++) {
      $timestamp = $start + ($i * 86400);
      $date = date('Y-m-d', $timestamp);
      $daily_stats[$date] = 0;
    }

    $query = $this->database->select('material_inventory', 'base');
    $query->join('material_inventory__field_inventory_quantity_change', 'qty', 'base.id = qty.entity_id');
    $query->addField('base', 'created');
    $query->addField('qty', 'field_inventory_quantity_change_value', 'quantity');
    $query->condition('base.type', 'inventory_adjustment');
    $query->condition('qty.field_inventory_quantity_change_value', 0, '<');
    $query->condition('base.created', $start, '>=');
    $query->orderBy('base.created', 'DESC');

    $results = $query->execute()->fetchAll();

    foreach ($results as $row) {
      $date = date('Y-m-d', (int) $row->created);
      if (isset($daily_stats[$date])) {
        $daily_stats[$date] += abs((int) $row->quantity);
      }
    }

    krsort($daily_stats);

    $points = [];
    $max = 0;
    foreach ($daily_stats as $date => $count) {
      $max = max($max, $count);
      $points[] = [
        'date' => $date,
        'items' => $count,
      ];
    }

    return [
      'points' => $points,
      'max' => $max,
    ];
  }

  /**
   * Builds an inventory snapshot using cached counts/values on materials.
   */
  protected function getInventorySnapshot(): array {
    $query = $this->database->select('node_field_data', 'node');
    $query->leftJoin('node__field_material_inventory_count', 'count', 'node.nid = count.entity_id AND count.delta = 0 AND count.langcode = node.langcode');
    $query->leftJoin('node__field_material_inventory_value', 'value', 'node.nid = value.entity_id AND value.delta = 0 AND value.langcode = node.langcode');
    $query->leftJoin('node__field_material_inventory_method', 'method', 'node.nid = method.entity_id AND method.delta = 0 AND method.langcode = node.langcode');
    $query->condition('node.type', 'material');
    $query->condition('node.status', 1);
    $query->condition($query->orConditionGroup()
      ->isNull('method.field_material_inventory_method_value')
      ->condition('method.field_material_inventory_method_value', 'untracked', '<>')
    );

    $query->addExpression('COUNT(DISTINCT node.nid)', 'material_count');
    $query->addExpression('SUM(COALESCE(count.field_material_inventory_count_value, 0))', 'unit_count');
    $query->addExpression('SUM(COALESCE(value.field_material_inventory_value_value, 0))', 'inventory_value');
    $query->addExpression('SUM(CASE WHEN COALESCE(count.field_material_inventory_count_value, 0) <= 0 THEN 1 ELSE 0 END)', 'out_of_stock');

    $result = $query->execute()->fetchAssoc() ?: [];

    $unit_count = (int) ($result['unit_count'] ?? 0);
    $inventory_value = (float) ($result['inventory_value'] ?? 0);

    return [
      'material_count' => (int) ($result['material_count'] ?? 0),
      'unit_count' => $unit_count,
      'inventory_value' => $inventory_value,
      'out_of_stock' => (int) ($result['out_of_stock'] ?? 0),
      'avg_unit_value' => $unit_count > 0 ? $inventory_value / $unit_count : 0.0,
    ];
  }

  /**
   * Finds items that are low or out of stock.
   */
  protected function getLowStockMaterials(int $limit = 6): array {
    $materials = $this->runLowStockQuery($limit, TRUE);
    if (empty($materials)) {
      $materials = $this->runLowStockQuery($limit, FALSE);
    }
    return $materials;
  }

  /**
   * Builds a monthly purchase trend for completed months.
   */
  protected function getMonthlyPurchaseTrend(int $months = 12): array {
    $current_time = $this->time->getRequestTime();
    $current_month_start = strtotime(date('Y-m-01 00:00:00', $current_time));
    if (!$current_month_start) {
      return [];
    }

    $start_window = strtotime(sprintf('-%d months', $months), $current_month_start);
    if (!$start_window) {
      return [];
    }

    $query = $this->database->select('material_inventory', 'base');
    $query->join('material_inventory__field_inventory_quantity_change', 'qty', 'base.id = qty.entity_id');
    $query->addField('base', 'created');
    $query->addField('base', 'id', 'adjustment_id');
    $query->addField('qty', 'field_inventory_quantity_change_value', 'quantity');
    $query->condition('base.type', 'inventory_adjustment');
    $query->condition('qty.field_inventory_quantity_change_value', 0, '<');
    $query->condition('base.created', $start_window, '>=');
    $query->condition('base.created', $current_month_start, '<');

    $results = $query->execute()->fetchAll();

    $buckets = [];
    for ($i = $months; $i >= 1; $i--) {
      $month_start = strtotime(sprintf('-%d months', $i), $current_month_start);
      if (!$month_start) {
        continue;
      }
      $key = date('Y-m', $month_start);
      $buckets[$key] = [
        'label' => date('M Y', $month_start),
        'transactions' => 0,
        'items' => 0,
      ];
    }

    foreach ($results as $row) {
      $timestamp = (int) $row->created;
      $key = date('Y-m', $timestamp);
      if (!isset($buckets[$key])) {
        continue;
      }
      $buckets[$key]['transactions']++;
      $buckets[$key]['items'] += abs((int) $row->quantity);
    }

    $max_transactions = 0;
    foreach ($buckets as $bucket) {
      $max_transactions = max($max_transactions, $bucket['transactions']);
    }

    return [
      'points' => array_values($buckets),
      'max_transactions' => $max_transactions,
    ];
  }

  /**
   * Summarizes adjustment reasons by completed quarters.
   */
  protected function getQuarterlyReasonSummary(int $quarters = 4): array {
    if (!$this->database->schema()->tableExists('material_inventory__field_inventory_change_reason')) {
      return [];
    }
    $current_time = $this->time->getRequestTime();
    $current_quarter_start = $this->getQuarterStart($current_time);
    if (!$current_quarter_start) {
      return [];
    }

    $last_completed_quarter = strtotime('-3 months', $current_quarter_start);
    if (!$last_completed_quarter) {
      return [];
    }

    $oldest_quarter_start = strtotime(sprintf('-%d months', 3 * ($quarters - 1)), $last_completed_quarter);
    if (!$oldest_quarter_start) {
      return [];
    }

    $quarter_windows = [];
    $quarter_start = $oldest_quarter_start;
    for ($i = 0; $i < $quarters; $i++) {
      $quarter_end = strtotime('+3 months', $quarter_start);
      if (!$quarter_end) {
        break;
      }
      $key = $this->getQuarterKeyFromStart($quarter_start);
      $quarter_windows[$key] = [
        'label' => $this->formatQuarterLabel($quarter_start),
        'start' => $quarter_start,
        'end' => $quarter_end,
      ];
      $quarter_start = $quarter_end;
    }

    if (empty($quarter_windows)) {
      return [];
    }

    $reason_labels = $this->getReasonLabels();
    foreach ($quarter_windows as $key => $window) {
      $quarter_windows[$key]['reasons'] = [];
      foreach ($reason_labels as $reason_value => $label) {
        $quarter_windows[$key]['reasons'][$reason_value] = [
          'label' => $label,
          'transactions' => 0,
          'items' => 0,
        ];
      }
      $quarter_windows[$key]['reasons']['unknown'] = [
        'label' => (string) $this->t('Unlabeled'),
        'transactions' => 0,
        'items' => 0,
      ];
    }

    $query = $this->database->select('material_inventory', 'base');
    $query->join('material_inventory__field_inventory_quantity_change', 'qty', 'base.id = qty.entity_id');
    $query->leftJoin('material_inventory__field_inventory_change_reason', 'reason', 'base.id = reason.entity_id AND reason.delta = 0 AND reason.langcode = base.langcode');
    $query->addField('base', 'created');
    $query->addField('reason', 'field_inventory_change_reason_value', 'reason');
    $query->addField('qty', 'field_inventory_quantity_change_value', 'quantity');
    $query->condition('base.type', 'inventory_adjustment');
    $query->condition('base.created', $oldest_quarter_start, '>=');
    $query->condition('base.created', $current_quarter_start, '<');

    $results = $query->execute()->fetchAll();

    $totals = [];
    foreach ($quarter_windows as $key => $window) {
      $totals[$key] = [
        'transactions' => 0,
        'items' => 0,
      ];
    }

    foreach ($results as $row) {
      $timestamp = (int) $row->created;
      $bucket_key = $this->getQuarterKeyFromStart($this->getQuarterStart($timestamp));
      if (!$bucket_key || !isset($quarter_windows[$bucket_key])) {
        continue;
      }
      $reason = $row->reason ?: 'unknown';
      if (!isset($quarter_windows[$bucket_key]['reasons'][$reason])) {
        $quarter_windows[$bucket_key]['reasons'][$reason] = [
          'label' => $reason_labels[$reason] ?? $reason,
          'transactions' => 0,
          'items' => 0,
        ];
      }
      $quantity = abs((int) $row->quantity);
      $quarter_windows[$bucket_key]['reasons'][$reason]['transactions']++;
      $quarter_windows[$bucket_key]['reasons'][$reason]['items'] += $quantity;
      $totals[$bucket_key]['transactions']++;
      $totals[$bucket_key]['items'] += $quantity;
    }

    $quarters = [];
    foreach ($quarter_windows as $key => $window) {
      $quarters[] = [
        'label' => $window['label'],
        'reasons' => $window['reasons'],
        'totals' => $totals[$key] ?? ['transactions' => 0, 'items' => 0],
      ];
    }

    return $quarters;
  }

  /**
   * Maps timestamps to the beginning of their quarter.
   */
  protected function getQuarterStart(int $timestamp): int {
    $month = (int) date('n', $timestamp);
    $year = (int) date('Y', $timestamp);
    $quarter = (int) floor(($month - 1) / 3);
    $quarter_month = ($quarter * 3) + 1;
    return strtotime(sprintf('%d-%02d-01 00:00:00', $year, $quarter_month)) ?: 0;
  }

  /**
   * Generates a consistent quarter key.
   */
  protected function getQuarterKeyFromStart(int $quarter_start): string {
    if (!$quarter_start) {
      return '';
    }
    $year = date('Y', $quarter_start);
    $quarter = (int) floor(((int) date('n', $quarter_start) - 1) / 3) + 1;
    return sprintf('%s-Q%d', $year, $quarter);
  }

  /**
   * Formats a quarter label.
   */
  protected function formatQuarterLabel(int $quarter_start): string {
    $quarter = (int) floor(((int) date('n', $quarter_start) - 1) / 3) + 1;
    $year = date('Y', $quarter_start);
    return sprintf('Q%d %s', $quarter, $year);
  }

  /**
   * Provides labels for adjustment reasons.
   */
  protected function getReasonLabels(): array {
    return [
      'restock' => (string) $this->t('Restock'),
      'sale' => (string) $this->t('Sale'),
      'lossage' => (string) $this->t('Lossage'),
      'other' => (string) $this->t('Other'),
      'internal' => (string) $this->t('Internal use'),
      'education' => (string) $this->t('Education program'),
    ];
  }

  /**
   * Executes the low-stock lookup with optional threshold filtering.
   */
  protected function runLowStockQuery(int $limit, bool $enforceThreshold): array {
    $query = $this->database->select('node_field_data', 'node');
    $query->leftJoin('node__field_material_inventory_count', 'count', 'node.nid = count.entity_id AND count.delta = 0 AND count.langcode = node.langcode');
    $query->leftJoin('node__field_material_sales_cost', 'cost', 'node.nid = cost.entity_id AND cost.delta = 0 AND cost.langcode = node.langcode');
    $query->leftJoin('node__field_material_inventory_method', 'method', 'node.nid = method.entity_id AND method.delta = 0 AND method.langcode = node.langcode');
    $query->condition('node.type', 'material');
    $query->condition('node.status', 1);
    $query->condition($query->orConditionGroup()
      ->isNull('method.field_material_inventory_method_value')
      ->condition('method.field_material_inventory_method_value', 'untracked', '<>')
    );

    if ($enforceThreshold) {
      $or = $query->orConditionGroup()
        ->isNull('count.field_material_inventory_count_value')
        ->condition('count.field_material_inventory_count_value', 5, '<=');
      $query->condition($or);
    }

    $query->addField('node', 'nid', 'material_id');
    $query->addField('node', 'title', 'material_name');
    $query->addExpression('COALESCE(count.field_material_inventory_count_value, 0)', 'on_hand');
    $query->addExpression('COALESCE(cost.field_material_sales_cost_value, 0)', 'unit_price');

    $query->orderBy('on_hand', 'ASC');
    $query->orderBy('material_name', 'ASC');
    $query->range(0, $limit);

    $results = $query->execute()->fetchAll();
    $materials = [];
    foreach ($results as $row) {
      $on_hand = (int) $row->on_hand;
      $unit_price = (float) $row->unit_price;
      $materials[] = [
        'material_id' => (int) $row->material_id,
        'name' => $row->material_name ?: (string) $this->t('Untitled material'),
        'on_hand' => $on_hand,
        'unit_price' => $unit_price,
      ];
    }

    return $materials;
  }

  /**
   * Tables needed for sales metrics.
   */
  protected function getSalesTables(): array {
    return [
      'material_inventory',
      'material_inventory__field_inventory_quantity_change',
      'material_inventory__field_inventory_ref_material',
      'node_field_data',
      'node__field_material_sales_cost',
    ];
  }

  /**
   * Tables needed for inventory metrics.
   */
  protected function getInventoryTables(): array {
    return [
      'node_field_data',
      'node__field_material_inventory_count',
      'node__field_material_inventory_value',
      'node__field_material_sales_cost',
      'node__field_material_inventory_method',
    ];
  }

  /**
   * Checks whether the provided tables exist before running queries.
   */
  protected function requiredTablesAvailable(array $tables) {
    foreach ($tables as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        $this->logMissingTable($table);
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Logs a missing table warning only once per table.
   */
  protected function logMissingTable($table) {
    if (!isset($this->missingTablesLogged[$table])) {
      $this->getLogger('material_inventory_totals')->warning('Required table @table is missing; store statistics dashboard cannot calculate totals.', [
        '@table' => $table,
      ]);
      $this->missingTablesLogged[$table] = TRUE;
    }
  }

}
