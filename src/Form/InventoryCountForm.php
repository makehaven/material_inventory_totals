<?php

namespace Drupal\material_inventory_totals\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\material_inventory_totals\Service\InventoryTotalsCalculator;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for updating material inventory counts.
 */
class InventoryCountForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The inventory totals calculator.
   *
   * @var \Drupal\material_inventory_totals\Service\InventoryTotalsCalculator
   */
  protected $calculator;

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\material_inventory_totals\Service\InventoryTotalsCalculator $calculator
   *   The inventory calculator service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, InventoryTotalsCalculator $calculator) {
    $this->entityTypeManager = $entity_type_manager;
    $this->calculator = $calculator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('material_inventory_totals.calculator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'material_inventory_count_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {
    if (!$node || $node->bundle() !== 'material') {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Invalid material.'),
      ];
      return $form;
    }

    $form_state->set('material_node', $node);

    // Get current count using the calculator for accuracy, or fallback to field.
    // Recalculate checks the consistency.
    $summary = $this->calculator->recalculate((int) $node->id(), FALSE);
    $current_count = $summary['calculated_count'] ?? 0;

    $form['title'] = [
      '#type' => 'item',
      '#title' => $this->t('Material'),
      '#markup' => $node->label(),
    ];

    $form['current_count_display'] = [
      '#type' => 'item',
      '#title' => $this->t('Current System Count'),
      '#markup' => $current_count,
    ];

    $user_input = $form_state->getUserInput();
    if ($user_input && array_key_exists('total_count', $user_input) && $user_input['total_count'] !== '') {
      $total_input = (int) $user_input['total_count'];
    }
    else {
      $entered_total = $form_state->getValue('total_count');
      $total_input = $entered_total !== NULL ? (int) $entered_total : $current_count;
    }
    $is_positive_correction = $total_input > $current_count;

    $form['total_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Actual Count (Total)'),
      '#description' => $this->t('Enter the total number of items physically present. Positive corrections usually mean a missed restock entry.'),
      '#required' => TRUE,
      '#default_value' => $total_input,
      '#ajax' => [
        'callback' => '::ajaxRefreshCorrections',
        'wrapper' => 'material-inventory-corrections',
        'event' => 'input',
      ],
    ];

    $form['correction_guidance'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'material-inventory-corrections',
      ],
    ];

    if ($is_positive_correction) {
      $form['correction_guidance']['restock_notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Inventory corrections'),
        '#markup' => $this->t('Positive corrections usually indicate a missed restock entry. Confirm any restocks were entered and explain the correction below.'),
        '#wrapper_attributes' => [
          'class' => ['material-inventory-alert', 'alert', 'alert-warning', 'mb-3'],
          'role' => 'alert',
        ],
      ];
    }

    $notes_default = '';
    $existing_notes = NULL;
    if ($user_input && array_key_exists('notes', $user_input)) {
      $existing_notes = $user_input['notes'];
    }
    elseif ($form_state->hasValue('notes')) {
      $existing_notes = $form_state->getValue('notes');
    }

    if ($existing_notes !== NULL && $existing_notes !== '') {
      $notes_default = $existing_notes;
    }
    elseif ($is_positive_correction) {
      $notes_default = $this->t('Inventory correction: ');
    }

    $form['correction_guidance']['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#description' => $this->t('Optional notes about this adjustment. Help future reviewers understand why the inventory changed.'),
      '#default_value' => $notes_default,
      '#attributes' => [
        'placeholder' => $is_positive_correction ? $this->t('Inventory correction: explain why the count increased.') : '',
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update Inventory'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Route access callback.
   */
  public static function access(NodeInterface $node, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($node->bundle() === 'material')
      ->addCacheableDependency($node)
      ->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $new_total = $form_state->getValue('total_count');
    if (!is_numeric($new_total)) {
      $form_state->setErrorByName('total_count', $this->t('Count must be a number.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $node = $form_state->get('material_node');
    $summary = $this->calculator->recalculate((int) $node->id(), FALSE);
    $current_count = $summary['calculated_count'] ?? (int) $node->get('field_material_inventory_count')->value;
    $new_total = (int) $form_state->getValue('total_count');
    $delta = $new_total - $current_count;
    $reason = $delta < 0 ? 'lossage' : 'other';

    if ($delta === 0) {
      $this->messenger()->addStatus($this->t('No changes made to inventory (counts match).'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    try {
      $adjustment = $this->entityTypeManager->getStorage('material_inventory')->create([
        'type' => 'inventory_adjustment',
        'field_inventory_ref_material' => $node->id(),
        'field_inventory_quantity_change' => $delta,
        'field_inventory_change_reason' => $reason,
        'field_inventory_change_memo' => $form_state->getValue('notes'),
      ]);
      $adjustment->save();

      // The module hooks will handle the recalculation of the node field.
      $this->messenger()->addStatus($this->t('Inventory updated. Adjustment: @delta.', ['@delta' => $delta > 0 ? '+' . $delta : $delta]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to update inventory: @error', ['@error' => $e->getMessage()]));
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * AJAX callback for the correction guidance container.
   */
  public function ajaxRefreshCorrections(array &$form, FormStateInterface $form_state): array {
    return $form['correction_guidance'];
  }

}
