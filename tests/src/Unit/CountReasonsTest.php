<?php

declare(strict_types=1);

namespace Drupal\Tests\material_inventory_totals\Unit;

use Drupal\material_inventory_totals\CountReasons;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\material_inventory_totals\CountReasons
 * @group material_inventory_totals
 */
class CountReasonsTest extends UnitTestCase {

  /**
   * A higher count without a known reason goes to staff review.
   */
  public function testForDelta(): void {
    $this->assertSame(CountReasons::VERIFIED, CountReasons::forDelta(0));
    $this->assertSame(CountReasons::LOSS, CountReasons::forDelta(-2, CountReasons::COUNT_FOUND));
    $this->assertSame(CountReasons::COUNT_FOUND, CountReasons::forDelta(3, CountReasons::COUNT_FOUND));
    $this->assertSame(CountReasons::RESTOCK_UNLOGGED, CountReasons::forDelta(3, CountReasons::RESTOCK_UNLOGGED));
    $this->assertSame(CountReasons::NEEDS_REVIEW, CountReasons::forDelta(3, CountReasons::NEEDS_REVIEW));
    $this->assertSame(CountReasons::NEEDS_REVIEW, CountReasons::forDelta(3));
    $this->assertSame(CountReasons::NEEDS_REVIEW, CountReasons::forDelta(3, 'bogus'));
  }

}
