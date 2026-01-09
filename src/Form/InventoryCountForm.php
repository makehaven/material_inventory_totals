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
    $summary = $this->calculator->recalculate((int) $node->id(), FALSE);
    $current_count = $summary['calculated_count'] ?? 0;

    $form['inventory_header'] = [
      '#type' => 'markup',
      '#markup' => '<h4 class="mt-2 mb-3">' . $this->t('Inventory Update: @label', ['@label' => $node->label()]) . '</h4>',
    ];

    // Check for last inventory adjustment.
    $storage = $this->entityTypeManager->getStorage('material_inventory');
    $query = $storage->getQuery()
      ->condition('field_inventory_ref_material', $node->id())
      ->condition('type', 'inventory_adjustment')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);
    $ids = $query->execute();
    
    $last_check_text = $this->t('Never');
    if (!empty($ids)) {
      $last_entity = $storage->load(reset($ids));
      /** @var \Drupal\Core\Datetime\DateFormatterInterface $date_formatter */
      $date_formatter = \Drupal::service('date.formatter');
      $ago = $date_formatter->formatTimeDiffSince($last_entity->get('created')->value);
      $last_check_text = $this->t('@time ago', ['@time' => $ago]);
    }

    $form['info_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'mb-2']],
    ];

    $form['info_row']['last_inventory_check'] = [
      '#type' => 'item',
      '#title' => $this->t('Last Checked'),
      '#markup' => '<strong>' . $last_check_text . '</strong>',
      '#wrapper_attributes' => ['class' => ['col-sm-6']],
    ];

    $form['info_row']['current_count_display'] = [
      '#type' => 'item',
      '#title' => $this->t('System Count'),
      '#markup' => '<strong>' . $current_count . '</strong>',
      '#wrapper_attributes' => ['class' => ['col-sm-6']],
    ];

    // Check for items in pending tabs.
    $pending_qty = 0;
    try {
      if (\Drupal::entityTypeManager()->hasDefinition('material_transaction')) {
        $trans_storage = \Drupal::entityTypeManager()->getStorage('material_transaction');
        $t_query = $trans_storage->getQuery()
          ->condition('field_material_ref', $node->id())
          ->condition('field_transaction_status', 'pending')
          ->accessCheck(FALSE);
        $t_ids = $t_query->execute();
        if (!empty($t_ids)) {
          foreach ($trans_storage->loadMultiple($t_ids) as $t) {
            $pending_qty += (int) $t->get('field_quantity')->value;
          }
        }
      }
    } catch (\Exception $e) {}

    if ($pending_qty > 0) {
      $form['tab_notice'] = [
        '#type' => 'markup',
        '#markup' => '<div class="alert alert-secondary py-1 mb-3" style="font-size: 0.9em;">' . 
                     $this->t('Note: <strong>@qty</strong> additional items are currently in member tabs (already deducted from system count).', ['@qty' => $pending_qty]) . 
                     '</div>',
      ];
    }

    if ($node->hasField('field_material_backstock') && !$node->get('field_material_backstock')->isEmpty()) {
       $backstock_location = $node->get('field_material_backstock')->value;
       $form['backstock_alert'] = [
         '#type' => 'markup',
         '#markup' => '<div class="alert alert-info py-2" role="alert" style="border-left: 5px solid #0dcaf0; background-color: #cff4fc; margin-bottom: 15px;"><strong>' . $this->t('⚠️ CHECK BACKSTOCK') . '</strong>: ' . $this->t('@loc', ['@loc' => $backstock_location]) . '</div>',
       ];
    }

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
      '#required' => TRUE,
      '#default_value' => $total_input,
      '#attributes' => [
        'class' => ['form-control-lg'],
        'autofocus' => 'autofocus',
      ],
      '#ajax' => [
        'callback' => '::ajaxRefreshCorrections',
        'wrapper' => 'material-inventory-corrections',
        'event' => 'change',
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
        '#markup' => '<div class="alert alert-warning py-1 mt-2 mb-2" role="alert">' . $this->t('Positive correction: ensure any recent restocks were already entered.') . '</div>',
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
      '#type' => 'textfield',
      '#title' => $this->t('Notes'),
      '#title_display' => 'invisible',
      '#default_value' => $notes_default,
      '#attributes' => [
        'placeholder' => $this->t('Optional notes...'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mt-3']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Count'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['btn-lg', 'w-100']],
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
    
    if ($delta === 0) {
      $reason = 'verification';
      $msg = $this->t('Inventory verified. No changes needed.');
    }
    else {
      $reason = $delta < 0 ? 'lossage' : 'other';
      $msg = $this->t('Inventory updated. Adjustment: @delta.', ['@delta' => $delta > 0 ? '+' . $delta : $delta]);
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

      $this->messenger()->addStatus($msg);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to update inventory: @error', ['@error' => $e->getMessage()]));
    }

    // Redirect back to current page if it's not the canonical node view.
    $route_name = \Drupal::routeMatch()->getRouteName();
    if ($route_name !== 'entity.node.canonical') {
      // If we are in a block or another page, stay there.
      return;
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
