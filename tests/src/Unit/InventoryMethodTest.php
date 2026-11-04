<?php

declare(strict_types=1);

namespace Drupal\Tests\material_inventory_totals\Unit;

use Drupal\material_inventory_totals\Controller\InventoryApiController;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Which inventory methods are counted, checked or neither.
 *
 * @group material_inventory_totals
 */
class InventoryMethodTest extends UnitTestCase {

  /**
   * Each method reads as counted, a charge, or checked.
   *
   * @dataProvider methods
   */
  public function testMethods(?string $method, bool $untracked, bool $check_only): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->willReturn((object) ['value' => $method]);
    $this->assertSame($untracked, InventoryApiController::isUntracked($node));
    $this->assertSame($check_only, InventoryApiController::isCheckOnly($node));
  }

  /**
   * Inventory methods and how each behaves.
   */
  public static function methods(): array {
    return [
      'counted' => ['counted', FALSE, FALSE],
      'estimated' => ['estimated', FALSE, FALSE],
      'charge' => ['untracked', TRUE, FALSE],
      'priced by use' => ['usage_check', TRUE, TRUE],
      'not set' => [NULL, FALSE, FALSE],
    ];
  }

}
