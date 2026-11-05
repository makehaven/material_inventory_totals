<?php

declare(strict_types=1);

namespace Drupal\Tests\material_inventory_totals\Unit;

use Drupal\material_inventory_totals\InventoryOddities;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\material_inventory_totals\InventoryOddities
 * @group material_inventory_totals
 */
class InventoryOdditiesTest extends UnitTestCase {

  /**
   * Start of the period in these tests.
   */
  private const FROM = 1000;

  /**
   * Builds a history from short rows, oldest first.
   *
   * @param array $rows
   *   Each row: [created, qty, reason, memo], optionally unit_cost, nid.
   */
  private function history(array $rows): array {
    $history = [];
    foreach ($rows as $i => $row) {
      $history[] = [
        'id' => $i + 1,
        'created' => $row[0],
        'nid' => $row[5] ?? 1,
        'uid' => 0,
        'qty' => $row[1],
        'reason' => $row[2],
        'memo' => $row[3] ?? '',
        'unit_cost' => $row[4] ?? 1.0,
      ];
    }
    return $history;
  }

  /**
   * A zero-out on a weighed item is neither a gain nor a loss.
   *
   * From November 2026: #07 printable vinyl went "+251" on a zero-out.
   */
  public function testZeroOutIsListedApartFromGains(): void {
    $found = InventoryOddities::find($this->history([
      [100, 500, 'restock', 'Initial inventory'],
      [1100, 251, 'other', '2600g. Zeroing out following inventory. 7.17g/inch', 0.6],
    ]), self::FROM);
    $this->assertSame([], $found['increases']);
    $this->assertSame([], $found['losses']);
    $this->assertCount(1, $found['resets']);
  }

  /**
   * A delivery note saying not to zero out is still a delivery.
   */
  public function testRestockMentioningZeroStaysRestock(): void {
    $found = InventoryOddities::find($this->history([
      [100, 5, 'restock', 'Initial inventory'],
      [900, 0, 'verification'],
      [950, 10, 'restock', 'Full roll added. DO NOT ZERO OUT'],
      [1100, 20, 'count_found'],
    ]), self::FROM);
    $this->assertSame([], $found['resets']);
    $this->assertSame(InventoryOddities::RESTOCK_STILL_OVER, $found['increases'][0]['why']);
  }

  /**
   * An increase next to a shortfall reads as a miscount, either way round.
   */
  public function testIncreaseBesideShortfallLooksLikeMiscount(): void {
    $after = InventoryOddities::find($this->history([
      [100, 50, 'restock', 'Initial inventory'],
      [900, -10, 'lossage'],
      [1100, 10, 'count_found'],
    ]), self::FROM);
    $this->assertSame(InventoryOddities::AFTER_SHORTFALL, $after['increases'][0]['why']);

    $before = InventoryOddities::find($this->history([
      [100, 50, 'restock', 'Initial inventory'],
      [1100, 10, 'count_found'],
      [1200, -10, 'lossage'],
    ]), self::FROM);
    $this->assertSame(InventoryOddities::BEFORE_SHORTFALL, $before['increases'][0]['why']);
  }

  /**
   * An increase with sales and no delivery looks like an unlogged delivery.
   */
  public function testIncreaseAfterSalesLooksLikeUnloggedDelivery(): void {
    $sold = InventoryOddities::find($this->history([
      [100, 50, 'restock', 'Initial inventory'],
      [900, 0, 'verification'],
      [950, -5, 'sale'],
      [1100, 10, 'other'],
    ]), self::FROM);
    $this->assertSame(InventoryOddities::SOLD_NO_RESTOCK, $sold['increases'][0]['why']);

    $quiet = InventoryOddities::find($this->history([
      [100, 50, 'restock', 'Initial inventory'],
      [900, 0, 'verification'],
      [1100, 10, 'other'],
    ]), self::FROM);
    $this->assertSame(InventoryOddities::NOTHING_SINCE, $quiet['increases'][0]['why']);
  }

  /**
   * Small increases are left out, except when the item has no price.
   */
  public function testIncreaseFloor(): void {
    $small = InventoryOddities::find($this->history([
      [100, 50, 'restock', 'Initial inventory'],
      [1100, 4, 'other'],
    ]), self::FROM);
    $this->assertSame([], $small['increases']);

    $no_price = InventoryOddities::find($this->history([
      [100, 50, 'restock', 'Initial inventory', 0.0],
      [1100, 4, 'other', '', 0.0],
    ]), self::FROM);
    $this->assertCount(1, $no_price['increases']);
  }

  /**
   * A first entry is the opening stock, whatever reason it was saved with.
   */
  public function testFirstEntryIsNotIncrease(): void {
    $found = InventoryOddities::find($this->history([
      [1100, 300, 'other'],
    ]), self::FROM);
    $this->assertSame([], $found['increases']);
  }

  /**
   * Losses add up per item, less what turned up in the same period.
   */
  public function testLossesAreNetPerItem(): void {
    $found = InventoryOddities::find($this->history([
      [100, 500, 'restock', 'Initial inventory', 1.0, 1],
      [1100, -20, 'lossage', 'first', 1.0, 1],
      [1200, -15, 'lossage', 'second', 1.0, 1],
      [1300, 5, 'count_found', '', 1.0, 1],
      // A swing: short, then found again, is no loss.
      [100, 500, 'restock', 'Initial inventory', 1.0, 2],
      [1100, -30, 'lossage', '', 1.0, 2],
      [1200, 30, 'count_found', '', 1.0, 2],
    ]), self::FROM);
    $this->assertCount(1, $found['losses']);
    $loss = $found['losses'][0];
    $this->assertSame(1, $loss['nid']);
    $this->assertSame(2, $loss['times']);
    $this->assertEqualsWithDelta(30.0, $loss['net'], 0.001);
    $this->assertSame('second', $loss['last']['memo']);
  }

  /**
   * Counts from before the period do not reach the report.
   */
  public function testEntriesBeforeThePeriodAreLeftOut(): void {
    $found = InventoryOddities::find($this->history([
      [100, 500, 'restock', 'Initial inventory'],
      [500, -100, 'lossage'],
      [600, 100, 'count_found'],
    ]), self::FROM);
    $this->assertSame([], $found['increases']);
    $this->assertSame([], $found['losses']);
  }

  /**
   * One inch of an item usually sold in dozens of inches stands out.
   */
  public function testSmallSale(): void {
    $found = InventoryOddities::find($this->history(array_merge(
      [[100, 500, 'restock', 'Initial inventory', 0.15]],
      array_fill(0, 6, [200, -30, 'sale', '', 0.15]),
      [[1100, -1, 'unpaid_tab', '', 0.15]],
    )), self::FROM);
    $this->assertCount(1, $found['small_sales']);
    $this->assertEquals(30, $found['small_sales'][0]['usual']);
  }

  /**
   * Machine time, items usually sold singly and new items are not judged.
   */
  public function testSmallSaleNeedsUsualSizeToJudgeBy(): void {
    $cents = InventoryOddities::find($this->history(array_merge(
      [[100, 5000, 'restock', 'Initial inventory', 0.01]],
      array_fill(0, 6, [200, -1165, 'sale', '', 0.01]),
      [[1100, -1, 'unpaid_tab', '', 0.01]],
    )), self::FROM);
    $this->assertSame([], $cents['small_sales']);

    $singles = InventoryOddities::find($this->history(array_merge(
      [[100, 50, 'restock', 'Initial inventory']],
      array_fill(0, 6, [200, -1, 'sale']),
    )), 100);
    $this->assertSame([], $singles['small_sales']);

    $new_item = InventoryOddities::find($this->history([
      [100, 500, 'restock', 'Initial inventory'],
      [200, -30, 'sale'],
      [300, -30, 'sale'],
      [1100, -1, 'sale'],
    ]), self::FROM);
    $this->assertSame([], $new_item['small_sales']);
  }

  /**
   * A tab line removed afterwards was not a sale.
   */
  public function testRemovedTabLineIsNotSmallSale(): void {
    $found = InventoryOddities::find($this->history(array_merge(
      [[100, 500, 'restock', 'Initial inventory', 0.15]],
      array_fill(0, 6, [200, -30, 'sale', '', 0.15]),
      [
        [1100, -1, 'unpaid_tab', '', 0.15],
        [1101, 1, 'restock', 'Removed from tab (Mistake) by user 2415', 0.15],
      ],
    )), self::FROM);
    $this->assertSame([], $found['small_sales']);
  }

  /**
   * A store-page sale has no owner, so the buyer comes from its memo.
   */
  public function testBuyerUid(): void {
    $this->assertSame(3474, InventoryOddities::buyerUid(['uid' => 0, 'memo' => '[UID:3474] Sold to someone']));
    $this->assertSame(12, InventoryOddities::buyerUid(['uid' => 12, 'memo' => 'Added to tab by user 12']));
    $this->assertSame(0, InventoryOddities::buyerUid(['uid' => 0, 'memo' => '']));
    $this->assertSame(3474, InventoryOddities::buyerUid(['uid' => 12, 'memo' => '[UID:3474] Sold to someone']));
  }

}
