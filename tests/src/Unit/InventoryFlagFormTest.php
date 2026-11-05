<?php

declare(strict_types=1);

namespace Drupal\Tests\material_inventory_totals\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\material_inventory_totals\CountReasons;
use Drupal\material_inventory_totals\Form\InventoryFlagForm;
use Drupal\material_inventory_totals\Service\InventorySessionManager;
use Drupal\Tests\UnitTestCase;

/**
 * Where a recheck answer goes when the counter is not sure why it is higher.
 *
 * @coversDefaultClass \Drupal\material_inventory_totals\Form\InventoryFlagForm
 * @group material_inventory_totals
 */
class InventoryFlagFormTest extends UnitTestCase {

  /**
   * Submits the form with these values against a mocked session manager.
   */
  protected function submit(InventorySessionManager $sessions, int $delta, array $values): void {
    $flag = (object) ['id' => 5, 'status' => 'open', 'delta' => $delta];
    $sessions->method('flag')->willReturn($flag);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(7);
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    \Drupal::setContainer($container);

    $form = new InventoryFlagForm(
      $sessions,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(DateFormatterInterface::class),
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->createMock(MessengerInterface::class));

    $form_state = new FormState();
    $form_state->set('flag_id', 5);
    $form_state->setValues($values);
    $render = [];
    $form->submitForm($render, $form_state);
  }

  /**
   * "The count was right" + "Not sure" is reported as not sure.
   */
  public function testConfirmedButNotSureGoesToStaff(): void {
    $sessions = $this->createMock(InventorySessionManager::class);
    $sessions->expects($this->once())->method('notSure');
    $sessions->expects($this->never())->method('confirm');
    $this->submit($sessions, 286, ['decision' => 'confirm', 'positive_reason' => CountReasons::NEEDS_REVIEW]);
  }

  /**
   * A confirmed count with a known reason stays confirmed.
   */
  public function testConfirmedWithReasonStaysConfirmed(): void {
    $sessions = $this->createMock(InventorySessionManager::class);
    $sessions->expects($this->once())->method('confirm');
    $sessions->expects($this->never())->method('notSure');
    $this->submit($sessions, 2, ['decision' => 'confirm', 'positive_reason' => CountReasons::COUNT_FOUND]);
  }

  /**
   * A low count has no "why higher" question, whatever a hidden field holds.
   */
  public function testLowCountIgnoresLeftoverReason(): void {
    $sessions = $this->createMock(InventorySessionManager::class);
    $sessions->expects($this->once())->method('confirm');
    $sessions->expects($this->never())->method('notSure');
    $this->submit($sessions, -2, ['decision' => 'confirm', 'positive_reason' => CountReasons::NEEDS_REVIEW]);
  }

  /**
   * A recount keeps the new number and carries "Not sure" as its reason.
   */
  public function testRecountCanBeNotSure(): void {
    $sessions = $this->createMock(InventorySessionManager::class);
    $sessions->expects($this->once())->method('recount')
      ->with($this->anything(), 7, 300, CountReasons::NEEDS_REVIEW, '');
    $sessions->expects($this->never())->method('notSure');
    $this->submit($sessions, 286, ['decision' => 'recount', 'total' => 300, 'positive_reason' => CountReasons::NEEDS_REVIEW]);
  }

}
