<?php

namespace Drupal\material_inventory_totals;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Finds the entries in the inventory history worth a person's attention.
 *
 * Works on plain rows, oldest first, keyed by: id, created, nid, uid, qty,
 * reason, memo, unit_cost. The whole history is needed even for a short
 * report: an item's first entry, its previous count and its usual sale size
 * can all sit before the period being looked at.
 *
 * Tried against the live history in November 2026. Three things in it look odd
 * and are not, so they are handled here:
 * - A zero-out on a weighed item ("+251 inches") only cancels what was sold
 *   since the last one. It is listed apart and never reads as a gain or loss.
 * - Machine time is priced in cents, so 1 cent on the waterjet is a normal
 *   sale and not a mistake.
 * - A tab line removed later was never really a sale.
 */
final class InventoryOddities {

  /**
   * An increase worth less than this (store price) is not listed.
   */
  public const INCREASE_VALUE = InventoryFlagRules::RECHECK_FLOOR;

  /**
   * An item is listed when its losses, less what turned up, reach this.
   */
  public const LOSS_VALUE = 25.0;

  /**
   * A sale is small at this share of the item's usual sale or less.
   */
  public const SMALL_SALE_SHARE = 0.1;

  /**
   * An item's usual sale must be at least this many units to judge against.
   */
  public const SMALL_SALE_MIN_USUAL = 10;

  /**
   * An item needs this many sales before it has a usual sale.
   */
  public const SMALL_SALE_MIN_SALES = 5;

  /**
   * At this price per unit or less, an item is priced by use (cents).
   */
  public const PRICED_BY_USE = 0.01;

  /**
   * The count before this one was short.
   */
  public const AFTER_SHORTFALL = 'after_shortfall';

  /**
   * The count after this one was short.
   */
  public const BEFORE_SHORTFALL = 'before_shortfall';

  /**
   * A delivery was logged since the last count and the count still rose.
   */
  public const RESTOCK_STILL_OVER = 'restock_still_over';

  /**
   * Stock sold since the last count, nothing delivered, and the count rose.
   */
  public const SOLD_NO_RESTOCK = 'sold_no_restock';

  /**
   * Nothing sold or delivered since the last count, and the count rose.
   */
  public const NOTHING_SINCE = 'nothing_since';

  /**
   * What each increase looks like, for the person reading the report.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Keyed by the codes above.
   */
  public static function increaseLabels(): array {
    return [
      self::AFTER_SHORTFALL => new TranslatableMarkup('Probably a miscount: the count before was short'),
      self::BEFORE_SHORTFALL => new TranslatableMarkup('Probably a miscount: the next count was short'),
      self::RESTOCK_STILL_OVER => new TranslatableMarkup('A delivery was logged and it still came in high'),
      self::SOLD_NO_RESTOCK => new TranslatableMarkup('Looks like a delivery nobody logged: it sold and still went up'),
      self::NOTHING_SINCE => new TranslatableMarkup('Nothing sold or delivered since the last count'),
    ];
  }

  /**
   * Works out what one history entry is.
   *
   * @param array $row
   *   The entry.
   * @param bool $first
   *   Whether it is the item's first entry.
   *
   * @return string|null
   *   One of: sale, tab_return, opening, restock, reset, over, short, match.
   *   NULL for anything else, such as internal use.
   */
  public static function classify(array $row, bool $first): ?string {
    $reason = (string) $row['reason'];
    $memo = (string) $row['memo'];
    if (in_array($reason, ['sale', 'unpaid_tab'], TRUE)) {
      return 'sale';
    }
    if (preg_match('/removed from tab|not a restock: stock returned/i', $memo)) {
      return 'tab_return';
    }
    if ($first || stripos($memo, 'initial') !== FALSE) {
      return 'opening';
    }
    if ($reason === CountReasons::RESTOCK) {
      return 'restock';
    }
    $count_reasons = [
      CountReasons::VERIFIED,
      CountReasons::LOSS,
      CountReasons::RESTOCK_UNLOGGED,
      CountReasons::COUNT_FOUND,
      CountReasons::NEEDS_REVIEW,
      CountReasons::UNCLASSIFIED,
    ];
    if (!in_array($reason, $count_reasons, TRUE)) {
      return NULL;
    }
    if (preg_match('/\bzero|resetting/i', $memo)) {
      return 'reset';
    }
    $qty = (float) $row['qty'];
    return $qty > 0 ? 'over' : ($qty < 0 ? 'short' : 'match');
  }

  /**
   * Finds the oddities from a date on.
   *
   * @param array $rows
   *   The whole history, oldest first.
   * @param int $from
   *   Start of the period, as a timestamp.
   *
   * @return array
   *   Keyed by:
   *   - increases: count entries, largest value first, each with value and why.
   *   - losses: one row per item, largest net loss first: nid, lost, found,
   *     net, times, last (the latest short entry).
   *   - small_sales: sale entries, newest first, each with usual and value.
   *   - resets: zero-out entries, newest first.
   */
  public static function find(array $rows, int $from): array {
    $seen = $counts = $sales = $restocked = $sold = [];
    $resets = [];

    foreach ($rows as $row) {
      $nid = (int) $row['nid'];
      $qty = (float) $row['qty'];
      $class = self::classify($row, !isset($seen[$nid]));
      $seen[$nid] = TRUE;

      switch ($class) {
        case 'sale':
          if ($qty < 0) {
            $sales[$nid][] = $row;
            $sold[$nid] = ($sold[$nid] ?? 0) - $qty;
          }
          break;

        case 'tab_return':
          $sold[$nid] = ($sold[$nid] ?? 0) - $qty;
          self::cancelSale($sales[$nid], $qty);
          break;

        case 'restock':
          $restocked[$nid] = TRUE;
          break;

        case 'reset':
          if ((int) $row['created'] >= $from) {
            $resets[] = $row;
          }
          break;

        case 'over':
        case 'short':
        case 'match':
          $row['class'] = $class;
          $row['restocked'] = !empty($restocked[$nid]);
          $row['sold'] = ($sold[$nid] ?? 0) > 0;
          $counts[$nid][] = $row;
          $restocked[$nid] = FALSE;
          $sold[$nid] = 0;
          break;
      }
    }

    [$increases, $losses] = self::readCounts($counts, $from);
    return [
      'increases' => $increases,
      'losses' => $losses,
      'small_sales' => self::smallSales($sales, $from),
      'resets' => array_reverse($resets),
    ];
  }

  /**
   * What counts found short and high, per month, newest first.
   *
   * @param array $rows
   *   The whole history, oldest first.
   * @param int $from
   *   Start of the period, as a timestamp.
   *
   * @return array
   *   Keyed by Y-m: short and high, in dollars at the store price.
   */
  public static function countTotals(array $rows, int $from): array {
    $seen = $totals = [];
    foreach ($rows as $row) {
      $nid = (int) $row['nid'];
      $class = self::classify($row, !isset($seen[$nid]));
      $seen[$nid] = TRUE;
      if (($class !== 'short' && $class !== 'over') || (int) $row['created'] < $from) {
        continue;
      }
      $month = date('Y-m', (int) $row['created']);
      $totals[$month] ??= ['short' => 0.0, 'high' => 0.0];
      $totals[$month][$class === 'short' ? 'short' : 'high'] += abs((float) $row['qty']) * (float) $row['unit_cost'];
    }
    krsort($totals);
    return $totals;
  }

  /**
   * Who a sale was to, when known.
   *
   * A memo that names the buyer wins. A sale otherwise belongs to its owner,
   * so a cash box sale shows the staff member who entered it.
   */
  public static function buyerUid(array $row): int {
    return preg_match('/UID:(\d+)/', (string) $row['memo'], $match) ? (int) $match[1] : (int) $row['uid'];
  }

  /**
   * Drops the latest sale a tab return undoes.
   */
  private static function cancelSale(?array &$sales, float $returned): void {
    foreach (array_reverse(array_keys($sales ?? [])) as $key) {
      if (abs(abs((float) $sales[$key]['qty']) - abs($returned)) < 0.0001) {
        unset($sales[$key]);
        $sales = array_values($sales);
        return;
      }
    }
  }

  /**
   * Reads each item's counts into increases and per-item losses.
   */
  private static function readCounts(array $counts, int $from): array {
    $increases = $items = [];
    foreach ($counts as $nid => $list) {
      foreach ($list as $i => $row) {
        if ((int) $row['created'] < $from || $row['class'] === 'match') {
          continue;
        }
        $value = abs((float) $row['qty']) * (float) $row['unit_cost'];
        $items[$nid] ??= [
          'nid' => $nid,
          'lost' => 0.0,
          'found' => 0.0,
          'times' => 0,
          'last' => NULL,
        ];
        if ($row['class'] === 'short') {
          $items[$nid]['lost'] += $value;
          $items[$nid]['times']++;
          $items[$nid]['last'] = $row;
          continue;
        }
        $items[$nid]['found'] += $value;
        // An item with no price stays in: its value is unknown, not small.
        if ((float) $row['unit_cost'] > 0 && $value < self::INCREASE_VALUE) {
          continue;
        }
        $row['value'] = $value;
        $row['why'] = self::whyIncrease($row, $list[$i - 1] ?? NULL, $list[$i + 1] ?? NULL);
        $increases[] = $row;
      }
    }
    usort($increases, fn($a, $b) => $b['value'] <=> $a['value']);

    $losses = [];
    foreach ($items as $item) {
      $item['net'] = $item['lost'] - $item['found'];
      if ($item['net'] >= self::LOSS_VALUE) {
        $losses[] = $item;
      }
    }
    usort($losses, fn($a, $b) => $b['net'] <=> $a['net']);
    return [$increases, $losses];
  }

  /**
   * Says what a count that came in high looks like.
   */
  private static function whyIncrease(array $row, ?array $previous, ?array $next): string {
    if ($previous && $previous['class'] === 'short') {
      return self::AFTER_SHORTFALL;
    }
    if ($next && $next['class'] === 'short') {
      return self::BEFORE_SHORTFALL;
    }
    if ($row['restocked']) {
      return self::RESTOCK_STILL_OVER;
    }
    return $row['sold'] ? self::SOLD_NO_RESTOCK : self::NOTHING_SINCE;
  }

  /**
   * Finds sales far below what the item usually sells in.
   */
  private static function smallSales(array $sales, int $from): array {
    $small = [];
    foreach ($sales as $list) {
      if (count($list) < self::SMALL_SALE_MIN_SALES || (float) $list[0]['unit_cost'] <= self::PRICED_BY_USE) {
        continue;
      }
      $usual = self::median(array_map(fn($row) => abs((float) $row['qty']), $list));
      if ($usual < self::SMALL_SALE_MIN_USUAL) {
        continue;
      }
      foreach ($list as $row) {
        if ((int) $row['created'] >= $from && abs((float) $row['qty']) <= $usual * self::SMALL_SALE_SHARE) {
          $row['usual'] = $usual;
          $row['value'] = abs((float) $row['qty']) * (float) $row['unit_cost'];
          $small[] = $row;
        }
      }
    }
    usort($small, fn($a, $b) => (int) $b['created'] <=> (int) $a['created']);
    return $small;
  }

  /**
   * The middle value of a list.
   */
  private static function median(array $values): float {
    sort($values);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
  }

}
