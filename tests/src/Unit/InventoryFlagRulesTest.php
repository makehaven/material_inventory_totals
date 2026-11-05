<?php

declare(strict_types=1);

namespace Drupal\Tests\material_inventory_totals\Unit;

use Drupal\material_inventory_totals\InventoryFlagRules as R;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\material_inventory_totals\InventoryFlagRules
 * @group material_inventory_totals
 */
class InventoryFlagRulesTest extends UnitTestCase {

  /**
   * Builds a count with sensible defaults.
   */
  protected function sample(array $values): array {
    return $values + [
      'delta' => 0,
      'system_before' => 20,
      'previous_delta' => NULL,
      'restock_since_previous' => FALSE,
      'unit_cost' => 5.0,
    ];
  }

  /**
   * Each rule fires on its own case and not on its near miss.
   */
  public function testRules(): void {
    $this->assertSame([], R::evaluate($this->sample(['delta' => 0, 'previous_delta' => -5])));
    // Short last time, over now: a swing, and nothing was delivered.
    $this->assertSame([R::SWING, R::ROSE_UNLOGGED], R::evaluate($this->sample(['delta' => 2, 'previous_delta' => -2])));
    // Up with a delivery logged and no swing: fine.
    $this->assertSame([], R::evaluate($this->sample(['delta' => 2, 'restock_since_previous' => TRUE])));
    // Jump must beat both 3 units and 25% of the system count.
    $this->assertSame([R::BIG_JUMP], R::evaluate($this->sample(['delta' => -6, 'system_before' => 20])));
    $this->assertSame([], R::evaluate($this->sample(['delta' => -5, 'system_before' => 20])));
    $this->assertSame([], R::evaluate($this->sample(['delta' => -3, 'system_before' => 4])));
    // Losses: $50 or more.
    $this->assertSame([R::LOSS], R::evaluate($this->sample(['delta' => -2, 'unit_cost' => 25.0])));
    $this->assertSame([], R::evaluate($this->sample(['delta' => -2, 'unit_cost' => 24.0])));
  }

  /**
   * Under $5 is left alone, whichever rule it would have tripped.
   */
  public function testRecheckFloor(): void {
    // A $2 dust mask, up one with no delivery.
    $this->assertSame([], R::evaluate($this->sample(['delta' => 1, 'unit_cost' => 2.0])));
    // Dominos, short.
    $this->assertSame([], R::evaluate($this->sample(['delta' => -2, 'unit_cost' => 0.2])));
    // A swing and a big jump, still only $4.50.
    $this->assertSame([], R::evaluate($this->sample(['delta' => 9, 'unit_cost' => 0.5, 'previous_delta' => -9, 'system_before' => 4])));
    // Exactly $5 is checked.
    $this->assertSame([R::ROSE_UNLOGGED], R::evaluate($this->sample(['delta' => 1, 'unit_cost' => 5.0])));
    // No price set: the value is unknown, so it is still checked.
    $this->assertSame([R::ROSE_UNLOGGED], R::evaluate($this->sample(['delta' => 1, 'unit_cost' => 0.0])));
  }

}
