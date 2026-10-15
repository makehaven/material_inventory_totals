<?php

declare(strict_types=1);

namespace Drupal\Tests\material_inventory_totals\Unit;

use Drupal\material_inventory_totals\Controller\InventoryApiController;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\material_inventory_totals\Controller\InventoryApiController
 * @group material_inventory_totals
 */
class InventoryApiParsingTest extends UnitTestCase {

  /**
   * A restock needs a material and a whole quantity of at least one.
   */
  public function testParseRestock(): void {
    $body = ['nid' => 12, 'quantity' => 5, 'memo' => ' Blick order 4411 '];
    $this->assertSame([12, 5, 'Blick order 4411'], InventoryApiController::parseRestock($body));
    $this->assertSame([12, 5, ''], InventoryApiController::parseRestock(['nid' => '12', 'quantity' => '5']));
    $this->assertIsString(InventoryApiController::parseRestock(['quantity' => 5]));
    $this->assertIsString(InventoryApiController::parseRestock(['nid' => 12, 'quantity' => 0]));
    $this->assertIsString(InventoryApiController::parseRestock(['nid' => 12, 'quantity' => -3]));
    $this->assertIsString(InventoryApiController::parseRestock(['nid' => 12, 'quantity' => '2.5']));
    $too_many = ['nid' => 12, 'quantity' => InventoryApiController::MAX_RESTOCK + 1];
    $this->assertIsString(InventoryApiController::parseRestock($too_many));
    $long = InventoryApiController::parseRestock(['nid' => 1, 'quantity' => 1, 'memo' => str_repeat('x', 300)]);
    $this->assertSame(255, mb_strlen($long[2]));
  }

  /**
   * Search words: lower-cased, 2+ characters, de-duplicated, at most five.
   */
  public function testSearchWords(): void {
    $this->assertSame(['baltic', 'birch'], InventoryApiController::searchWords('  Baltic   BIRCH '));
    $this->assertSame(['mm', 'ply'], InventoryApiController::searchWords('3 mm ply'));
    $this->assertSame([], InventoryApiController::searchWords(' a '));
    $this->assertSame(['aa', 'bb', 'cc', 'dd', 'ee'], InventoryApiController::searchWords('aa bb aa cc dd ee ff'));
  }

}
