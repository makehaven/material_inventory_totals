<?php

namespace Drupal\material_inventory_totals\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\material_inventory_totals\CountReasons;
use Drupal\material_inventory_totals\InventoryOddities;
use Drupal\material_inventory_totals\InventorySummary;
use Drupal\material_inventory_totals\Service\InventorySessionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Staff report of inventory entries worth a second look.
 *
 * Read-only. The rules are in InventoryOddities; this loads the history and
 * lays out the result.
 */
class InventoryOdditiesController extends ControllerBase {

  /**
   * Periods offered, in days.
   */
  protected const PERIODS = [30, 90, 365];

  /**
   * Period shown first, in days. Long enough to take in three monthly counts.
   */
  protected const DEFAULT_PERIOD = 90;

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected InventorySessionManager $sessions,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('entity_field.manager'),
      $container->get('material_inventory_totals.sessions'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Builds the report.
   */
  public function report(Request $request): array {
    $days = (int) $request->query->get('days');
    $days = in_array($days, self::PERIODS, TRUE) ? $days : self::DEFAULT_PERIOD;
    $from = $this->time->getRequestTime() - $days * 86400;

    [$rows, $materials] = $this->history();
    $found = InventoryOddities::find($rows, $from);
    $reasons = $this->sessions->reasonLabels();

    $build = [
      '#cache' => ['max-age' => 0],
      'intro' => [
        '#markup' => '<p>' . $this->t('Entries in the store history that look odd and are worth checking. Nothing here changes stock. Values are at store price, not what we paid.') . '</p>',
      ],
      'period' => $this->periodLinks($days),
    ];

    $waiting = $this->openReviewCount();
    if ($waiting) {
      $build['waiting'] = [
        '#markup' => '<p>' . $this->formatPlural(
          $waiting,
          '1 count marked "Not sure" is waiting on the <a href=":url">count review list</a>.',
          '@count counts marked "Not sure" are waiting on the <a href=":url">count review list</a>.',
          [':url' => Url::fromRoute('material_inventory_totals.count_review')->toString()],
        ) . '</p>',
      ];
    }

    $totals = InventoryOddities::countTotals($rows, $from);
    $build['count_totals'] = $this->section(
      $this->t('Counts short and high, by month'),
      $this->t('At today\'s store price. Read the two together: a count that comes in short one month and high the next is often the same miscount.'),
      [
        $this->t('Month'),
        $this->t('Counted short'),
        $this->t('Counted high'),
      ],
      array_map(fn (string $month) => [
        date('F Y', strtotime($month . '-01')),
        $this->money($totals[$month]['short']),
        $this->money($totals[$month]['high']),
      ], array_keys($totals)),
      $this->t('No counts came in short or high in this period.'),
    );

    $build['increases'] = $this->section(
      $this->t('Counts that went up'),
      $this->t('Worth $@floor or more. Stock does not appear by itself, so each of these is a delivery nobody logged, a miscount, or an entry correcting something else.', ['@floor' => (int) InventoryOddities::INCREASE_VALUE]),
      [
        $this->t('Date'),
        $this->t('Item'),
        $this->t('Went up by'),
        $this->t('Value'),
        $this->t('Saved as'),
        $this->t('What it looks like'),
        $this->t('Notes'),
      ],
      array_map(function (array $row) use ($materials, $reasons) {
        $labels = InventoryOddities::increaseLabels();
        return [
          $this->date($row),
          $this->itemCell($row['nid'], $materials),
          $this->quantity($row, $materials),
          $this->money($row['value']),
          InventorySummary::reasonLabel((string) ($reasons[$row['reason']] ?? $row['reason'])),
          $labels[$row['why']],
          $this->plain($row['memo']),
        ];
      }, $found['increases']),
      $this->t('No counts went up by that much.'),
    );

    $build['losses'] = $this->section(
      $this->t('Items losing stock'),
      $this->t('Every shortfall on the item in this period, less what turned up, when that comes to $@floor or more. An item that went short and was then found again is not listed here; it shows above as a likely miscount.', ['@floor' => (int) InventoryOddities::LOSS_VALUE]),
      [
        $this->t('Item'),
        $this->t('Times short'),
        $this->t('Lost'),
        $this->t('Turned up'),
        $this->t('Net loss'),
        $this->t('Latest note'),
      ],
      array_map(fn(array $item) => [
        $this->itemCell($item['nid'], $materials),
        $item['times'],
        $this->money($item['lost']),
        $this->money($item['found']),
        $this->money($item['net']),
        $this->plain($item['last']['memo'] ?? ''),
      ], $found['losses']),
      $this->t('No item lost that much.'),
    );

    $buyers = $this->buyers($found['small_sales']);
    $build['small_sales'] = $this->section(
      $this->t('Unusually small sales'),
      $this->t('A tenth or less of what the item usually sells in, such as 1 inch of something usually sold by the yard. Often someone meant to enter more. Items sold singly and machine time priced in cents are left out.'),
      [
        $this->t('Date'),
        $this->t('Item'),
        $this->t('Sold'),
        $this->t('Usual sale'),
        $this->t('Charged'),
        $this->t('Bought by'),
      ],
      array_map(fn(array $row) => [
        $this->date($row),
        $this->itemCell($row['nid'], $materials),
        $this->quantity($row, $materials),
        $this->formatNumber($row['usual']),
        $this->money($row['value']),
        $buyers[InventoryOddities::buyerUid($row)] ?? '',
      ], $found['small_sales']),
      $this->t('No unusually small sales.'),
    );

    $build['resets'] = $this->section(
      $this->t('Weighed items zeroed out'),
      $this->t('These counts were reset on purpose, so the change says nothing about what is on the shelf and they are left out of the lists above. A weight only exists in the note, and a weighed item recorded as "close enough" shows no change at all. So this report cannot see vinyl or resin going missing: compare the weights in these notes by hand.'),
      [
        $this->t('Date'),
        $this->t('Item'),
        $this->t('Change'),
        $this->t('Notes'),
      ],
      array_map(fn(array $row) => [
        $this->date($row),
        $this->itemCell($row['nid'], $materials),
        $this->quantity($row, $materials),
        $this->plain($row['memo']),
      ], $found['resets']),
      $this->t('Nothing was zeroed out.'),
    );

    return $build;
  }

  /**
   * Loads the whole adjustment history, oldest first, and the items in it.
   *
   * Long field names have hashed table names, so these go through the table
   * mapping. Entities would be far slower: a sale history runs to thousands.
   *
   * @return array
   *   The rows InventoryOddities works on, and the material nodes by ID.
   */
  protected function history(): array {
    $storage = $this->entityTypeManager()->getStorage('material_inventory');
    $mapping = $storage->getTableMapping();
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions('material_inventory');
    $query = $this->database->select('material_inventory_field_data', 'a')
      ->condition('a.type', 'inventory_adjustment')
      ->fields('a', ['id', 'created', 'uid'])
      ->orderBy('a.id');
    $join = function (string $method, string $field, string $property, string $alias, string $as) use ($query, $mapping, $definitions): void {
      $query->{$method}($mapping->getFieldTableName($field), $alias, "$alias.entity_id = a.id");
      $query->addField($alias, $mapping->getFieldColumnName($definitions[$field], $property), $as);
    };
    $join('join', 'field_inventory_ref_material', 'target_id', 'material', 'nid');
    $join('join', 'field_inventory_quantity_change', 'value', 'quantity', 'qty');
    $join('leftJoin', 'field_inventory_change_reason', 'value', 'reason', 'reason');
    $join('leftJoin', 'field_inventory_change_memo', 'value', 'memo', 'memo');
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $materials = $this->entityTypeManager()->getStorage('node')
      ->loadMultiple(array_unique(array_column($rows, 'nid')));
    foreach ($rows as &$row) {
      $row['unit_cost'] = $this->sessions->unitCost($materials[$row['nid']] ?? NULL);
    }
    return [$rows, $materials];
  }

  /**
   * How many counts are waiting for a staff decision.
   */
  protected function openReviewCount(): int {
    return (int) $this->entityTypeManager()->getStorage('material_inventory')->getQuery()
      ->condition('type', 'inventory_adjustment')
      ->condition('field_inventory_change_reason', CountReasons::NEEDS_REVIEW)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Links to the other periods.
   */
  protected function periodLinks(int $days): array {
    $links = [];
    foreach (self::PERIODS as $period) {
      $label = $this->t('@n days', ['@n' => $period]);
      $links[] = $period === $days
        ? '<strong>' . $label . '</strong>'
        : Link::createFromRoute($label, 'material_inventory_totals.inventory_oddities', [], ['query' => ['days' => $period]])->toString();
    }
    return ['#markup' => '<p>' . $this->t('Period:') . ' ' . implode(' | ', $links) . '</p>'];
  }

  /**
   * One heading, explanation and table.
   */
  protected function section($title, $help, array $header, array $rows, $empty): array {
    return [
      'title' => ['#markup' => '<h2>' . $title . ' (' . count($rows) . ')</h2>'],
      'help' => ['#markup' => '<p class="description">' . $help . '</p>'],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $empty,
      ],
    ];
  }

  /**
   * Display names of the people who made these sales.
   */
  protected function buyers(array $sales): array {
    $uids = array_filter(array_map([InventoryOddities::class, 'buyerUid'], $sales));
    $names = [];
    foreach ($this->entityTypeManager()->getStorage('user')->loadMultiple(array_unique($uids)) as $uid => $account) {
      $names[$uid] = $account->getDisplayName();
    }
    return $names;
  }

  /**
   * A link to the item.
   */
  protected function itemCell($nid, array $materials): array {
    $material = $materials[$nid] ?? NULL;
    return [
      'data' => $material
        ? ['#type' => 'link', '#title' => $material->label(), '#url' => $material->toUrl()]
        : ['#markup' => $this->t('(deleted item)')],
    ];
  }

  /**
   * An entry's quantity with the item's unit.
   */
  protected function quantity(array $row, array $materials): array {
    $material = $materials[$row['nid']] ?? NULL;
    $unit = $material && $material->hasField('field_material_unit') ? (string) $material->get('field_material_unit')->value : '';
    // Many units start with a size, such as "1 lb roll": 8 of those, not 81.
    $between = ctype_digit(substr($unit, 0, 1)) ? ' × ' : ' ';
    return $this->plain(trim($this->formatNumber(abs((float) $row['qty'])) . $between . $unit));
  }

  /**
   * A date cell.
   */
  protected function date(array $row): string {
    return $this->dateFormatter->format((int) $row['created'], 'custom', 'M j, Y');
  }

  /**
   * An amount in dollars.
   */
  protected function money(float $value): string {
    return '$' . number_format($value, 2);
  }

  /**
   * A number without trailing zeroes.
   */
  protected function formatNumber(float $value): string {
    return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
  }

  /**
   * A table cell holding text someone typed.
   */
  protected function plain(?string $text): array {
    return ['data' => ['#plain_text' => (string) $text]];
  }

}
