<?php

namespace Drupal\material_inventory_totals\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the store statistics dashboard.
 */
class StoreStatsController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs the StoreStatsController.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Builds the dashboard content.
   */
  public function content() {
    $build = [];

    // Calculate totals.
    $totals = $this->calculateTotals();

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['store-stats-summary']],
      'revenue' => [
        '#type' => 'item',
        '#title' => $this->t('Total Revenue (Estimated)'),
        '#markup' => '$' . number_format($totals['revenue'], 2),
      ],
      'items_sold' => [
        '#type' => 'item',
        '#title' => $this->t('Total Items Sold'),
        '#markup' => number_format($totals['items_sold']),
      ],
    ];

    // Top Selling Materials.
    $build['top_selling'] = [
      '#type' => 'details',
      '#title' => $this->t('Top Selling Materials'),
      '#open' => TRUE,
      'table' => $this->buildTopSellingTable(),
    ];

    // Daily Sales Trend.
    $build['daily_trend'] = [
      '#type' => 'details',
      '#title' => $this->t('Daily Sales Trend (Last 30 Days)'),
      '#open' => TRUE,
      'table' => $this->buildDailyTrendTable(),
    ];

    return $build;
  }

  /**
   * Calculates total revenue and items sold.
   */
  protected function calculateTotals() {
    // We assume negative inventory adjustments are sales.
    // Revenue = Sum(abs(quantity_change) * sales_cost).

    $query = $this->database->select('material_inventory', 'base');
    $query->join('material_inventory__field_inventory_quantity_change', 'qty', 'base.id = qty.entity_id');
    $query->join('material_inventory__field_inventory_ref_material', 'ref', 'base.id = ref.entity_id');
    $query->join('node__field_material_sales_cost', 'cost', 'ref.field_inventory_ref_material_target_id = cost.entity_id');

    $query->addExpression('SUM(ABS(qty.field_inventory_quantity_change_value))', 'total_items');
    $query->addExpression('SUM(ABS(qty.field_inventory_quantity_change_value) * cost.field_material_sales_cost_value)', 'total_revenue');

    $query->condition('base.type', 'inventory_adjustment');
    $query->condition('qty.field_inventory_quantity_change_value', 0, '<');

    $result = $query->execute()->fetchAssoc();

    return [
      'items_sold' => (int) $result['total_items'],
      'revenue' => (float) $result['total_revenue'],
    ];
  }

  /**
   * Builds the top selling materials table.
   */
  protected function buildTopSellingTable() {
    $header = [
      $this->t('Material'),
      $this->t('Total Quantity Sold'),
    ];

    $query = $this->database->select('material_inventory', 'base');
    $query->join('material_inventory__field_inventory_quantity_change', 'qty', 'base.id = qty.entity_id');
    $query->join('material_inventory__field_inventory_ref_material', 'ref', 'base.id = ref.entity_id');
    $query->join('node_field_data', 'node', 'ref.field_inventory_ref_material_target_id = node.nid');

    $query->addField('node', 'title', 'material_name');
    $query->addExpression('SUM(ABS(qty.field_inventory_quantity_change_value))', 'total_sold');

    $query->condition('base.type', 'inventory_adjustment');
    $query->condition('qty.field_inventory_quantity_change_value', 0, '<');

    $query->groupBy('node.nid');
    $query->groupBy('node.title');
    $query->orderBy('total_sold', 'DESC');
    $query->range(0, 10);

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $rows[] = [
        $row->material_name,
        $row->total_sold,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No sales data found.'),
    ];
  }

  /**
   * Builds the daily sales trend table.
   */
  protected function buildDailyTrendTable() {
    $header = [
      $this->t('Date'),
      $this->t('Items Sold'),
    ];

    $query = $this->database->select('material_inventory', 'base');
    $query->join('material_inventory__field_inventory_quantity_change', 'qty', 'base.id = qty.entity_id');

    // Group by date. FROM_UNIXTIME is MySQL specific, but standard enough for Drupal DB abstraction usually or we can fetch and process in PHP if needed.
    // Ideally use SQL expression compatible with supported DBs.
    // Drupal's recommended way is usually handling aggregation carefully.
    // For SQLite (which might be used in tests) vs MySQL.
    // Let's use a simpler approach: fetch timestamp, and aggregate in PHP if data volume isn't huge, or use DB specific functions.
    // Given we can't easily detect DB type, and for a dashboard, let's try a standard SQL approach.
    // 'FROM_UNIXTIME(base.created, "%Y-%m-%d")' works in MySQL.
    // SQLite uses 'strftime("%Y-%m-%d", base.created, "unixepoch")'.
    // To be safe, let's fetch the data and aggregate in PHP for the last 30 days. This is safer for cross-db compatibility if the dataset isn't massive.
    // If we assume a reasonable number of sales per month, fetching simple rows (date, qty) is fine.

    $query->addField('base', 'created');
    $query->addField('qty', 'field_inventory_quantity_change_value', 'quantity');
    $query->condition('base.type', 'inventory_adjustment');
    $query->condition('qty.field_inventory_quantity_change_value', 0, '<');
    $query->condition('base.created', strtotime('-30 days'), '>=');
    $query->orderBy('base.created', 'DESC');

    $results = $query->execute()->fetchAll();

    $daily_stats = [];
    foreach ($results as $row) {
      $date = date('Y-m-d', $row->created);
      if (!isset($daily_stats[$date])) {
        $daily_stats[$date] = 0;
      }
      $daily_stats[$date] += abs($row->quantity);
    }

    // Sort by date descending
    krsort($daily_stats);

    $rows = [];
    foreach ($daily_stats as $date => $count) {
      $rows[] = [
        $date,
        $count,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No sales in the last 30 days.'),
    ];
  }

}
