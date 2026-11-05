<?php

declare(strict_types=1);

namespace Drupal\Tests\material_inventory_totals\Unit;

use Drupal\material_inventory_totals\InventorySummary;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\material_inventory_totals\InventorySummary
 * @group material_inventory_totals
 */
class InventorySummaryTest extends UnitTestCase {

  /**
   * Estimated and priced-by-use items do not count against the match rate.
   *
   * These are the figures from the November 2026 inventory.
   */
  public function testMatchRateLeavesOutItemsThatCannotMatch(): void {
    $summary = [
      'items_counted' => 170,
      'matched' => 107,
      'estimated' => 31,
      'over' => ['n' => 13],
      'short' => ['n' => 19],
    ];
    $this->assertSame(139, InventorySummary::checkable($summary));
    $this->assertSame(77, InventorySummary::matchPercent($summary));
  }

  /**
   * An inventory of only estimated items has no match rate, not a zero one.
   */
  public function testNoCheckableItemsHasNoMatchRate(): void {
    $summary = ['matched' => 0, 'over' => ['n' => 0], 'short' => ['n' => 0]];
    $this->assertSame(0, InventorySummary::checkable($summary));
    $this->assertNull(InventorySummary::matchPercent($summary));
  }

  /**
   * The prefix goes; a label without it is left alone.
   */
  public function testReasonLabel(): void {
    $this->assertSame('Correction (found)', InventorySummary::reasonLabel('Count: correction (found)'));
    $this->assertSame('Staff to check', InventorySummary::reasonLabel('Count: staff to check'));
    $this->assertSame('Other', InventorySummary::reasonLabel('Other'));
  }

}
