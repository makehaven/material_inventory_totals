<?php

namespace Drupal\material_inventory_totals\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\material_inventory_totals\Controller\InventoryApiController;
use Drupal\material_inventory_totals\CountReasons;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Records an inventory check of an item priced by use.
 *
 * The site's number for these items is money or machine time, so what is on
 * the shelf cannot be entered as a count. The check is saved as a note with no
 * quantity change and marks the item as inventoried.
 */
class InventoryCheckForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'material_inventory_check_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    $form_state->set('material_node', $node);

    $form['header'] = [
      '#markup' => '<h4 class="mt-2 mb-3">' . $this->t('Inventory check: @label', ['@label' => $node->label()]) . '</h4>',
    ];

    $last = $this->lastCheck($node);
    if ($last) {
      $form['last_check'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['alert', 'alert-light', 'border', 'py-2', 'mb-3']],
        'text' => [
          '#markup' => $this->t('Last check, @date: @memo', [
            '@date' => $this->dateFormatter->format((int) $last->get('created')->value, 'custom', 'M j, Y'),
            '@memo' => $last->get('field_inventory_change_memo')->value ?: $this->t('no note'),
          ]),
        ],
      ];
    }

    $form['found'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What did you find?'),
      '#description' => $this->t('For example: 2 spools remain, or: matches the usage log.'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save check'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Route access callback: only items priced by use are checked this way.
   */
  public static function access(NodeInterface $node, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($node->bundle() === 'material' && InventoryApiController::isCheckOnly($node))
      ->addCacheableDependency($node)
      ->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $node = $form_state->get('material_node');
    // Saving triggers material_inventory_totals_entity_insert(), which stamps
    // field_material_last_inventoried for this reason.
    $this->entityTypeManager->getStorage('material_inventory')->create([
      'type' => 'inventory_adjustment',
      'field_inventory_ref_material' => $node->id(),
      'field_inventory_quantity_change' => 0,
      'field_inventory_change_reason' => CountReasons::VERIFIED,
      'field_inventory_change_memo' => $form_state->getValue('found'),
    ])->save();
    $this->messenger()->addStatus($this->t('Check saved for @label.', ['@label' => $node->label()]));
    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * The item's most recent check, if any.
   */
  protected function lastCheck(NodeInterface $node): ?object {
    $storage = $this->entityTypeManager->getStorage('material_inventory');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'inventory_adjustment')
      ->condition('field_inventory_ref_material', $node->id())
      ->condition('field_inventory_change_reason', ['verification', 'other'], 'IN')
      ->condition('field_inventory_quantity_change', 0)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

}
