<?php

namespace Drupal\material_inventory_totals\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\material_inventory_totals\CountReasons;
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

    // Logging a delivery is a different job from counting: point people at
    // it before they count, so restocks stop surfacing as count corrections.
    $restock_url = Url::fromRoute('eck.entity.add', [
      'eck_entity_type' => 'material_inventory',
      'eck_entity_bundle' => 'inventory_adjustment',
    ], [
      'query' => [
        'material' => $node->id(),
        'reason' => 'restock',
        'destination' => Url::fromRoute('<current>')->toString(),
      ],
    ]);
    if ($restock_url->access()) {
      $form['restock_link'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['alert', 'alert-light', 'border', 'py-2', 'mb-3', 'material-inventory-restock-link'],
        ],
        'text' => [
          '#markup' => $this->t('Putting out a delivery or backstock? <a href=":url" class="btn btn-sm btn-outline-primary ms-1">Log a restock</a> first, then count.', [
            ':url' => $restock_url->toString(),
          ]),
        ],
      ];
    }

    // Check for last physical inventory count from dedicated field.
    $last_check_text = $this->t('Never');
    if ($node->hasField('field_material_last_inventoried') && !$node->get('field_material_last_inventoried')->isEmpty()) {
      /** @var \Drupal\Core\Datetime\DateFormatterInterface $date_formatter */
      $date_formatter = \Drupal::service('date.formatter');
      $ago = $date_formatter->formatTimeDiffSince($node->get('field_material_last_inventoried')->value);
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
         '#markup' => '<div class="alert alert-info py-2" role="alert" style="border-left: 5px solid #0dcaf0; background-color: #cff4fc; margin-bottom: 15px;"><strong>' . $this->t('⚠️ BACKSTOCK LOCATION') . '</strong>: ' . $this->t('@loc', ['@loc' => $backstock_location]) . '</div>',
       ];
    }

    $form['#attached']['library'][] = 'material_inventory_totals/inventory_count';
    $form['#attributes']['data-system-count'] = $current_count;

    // No server-side AJAX on this field: while a Drupal AJAX request is in
    // flight the triggering input is disabled, so tapping Save straight after
    // typing posted the form WITHOUT the count and recorded a false "verified,
    // no change". The follow-up question is toggled client-side instead and
    // enforced in validateForm().
    $form['total_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Actual Count (Total)'),
      '#required' => TRUE,
      '#min' => 0,
      '#default_value' => $current_count,
      '#attributes' => [
        'class' => ['form-control-lg', 'js-inventory-count-total'],
        'autofocus' => 'autofocus',
      ],
    ];

    $form['correction_guidance'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'material-inventory-corrections',
      ],
    ];

    // Above the system count, the counter must say whether this is stock that
    // arrived without a logged restock or stock that was already here.
    // Lumping both together under "other" hid real shrinkage.
    $options = [];
    $descriptions = [];
    foreach (CountReasons::positiveChoices() as $code => $choice) {
      $options[$code] = $choice['label'];
      $descriptions[$code] = ['#description' => $choice['help']];
    }
    $form['correction_guidance']['positive'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['alert', 'alert-warning', 'py-2', 'mt-2', 'mb-2', 'js-inventory-count-positive']],
    ];
    $form['correction_guidance']['positive']['positive_reason'] = [
      '#type' => 'radios',
      '#title' => $this->t('Counted more than the system count? Say why.'),
      '#description' => $this->t('Only needed when your count is higher than the system count.'),
      '#options' => $options,
    ] + $descriptions;

    $form['correction_guidance']['loss_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Fewer than the system count: the difference is recorded as loss.'),
      '#attributes' => [
        'class' => ['alert', 'alert-secondary', 'py-1', 'mt-2', 'mb-2', 'js-inventory-count-loss'],
        'role' => 'status',
        'hidden' => 'hidden',
      ],
    ];

    $form['correction_guidance']['notes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Notes'),
      '#title_display' => 'invisible',
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
    // A missing count must never fall back to the default and be saved as a
    // "no change" verification.
    $input = $form_state->getUserInput();
    if (!isset($input['total_count']) || $input['total_count'] === '') {
      $form_state->setErrorByName('total_count', $this->t('Your count did not come through. Enter it again and press Save Count.'));
      return;
    }

    $new_total = $form_state->getValue('total_count');
    if (!is_numeric($new_total)) {
      $form_state->setErrorByName('total_count', $this->t('Count must be a number.'));
      return;
    }

    $node = $form_state->get('material_node');
    $summary = $this->calculator->recalculate((int) $node->id(), FALSE);
    $delta = (int) $new_total - (int) ($summary['calculated_count'] ?? 0);
    if ($delta > 0 && !isset(CountReasons::positiveChoices()[$form_state->getValue('positive_reason')])) {
      $form_state->setErrorByName('positive_reason', $this->t("You counted @delta more than the system count. Choose whether that is a restock that wasn't logged or a count correction.", ['@delta' => $delta]));
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
    
    $reason = CountReasons::forDelta($delta, $form_state->getValue('positive_reason'));
    if ($delta === 0) {
      $msg = $this->t('Inventory verified. No changes needed.');
    }
    elseif ($reason === CountReasons::RESTOCK_UNLOGGED) {
      $msg = $this->t("Inventory updated: +@delta recorded as a restock that wasn't logged.", ['@delta' => $delta]);
    }
    elseif ($reason === CountReasons::COUNT_FOUND) {
      $msg = $this->t('Inventory updated: +@delta recorded as a count correction (found).', ['@delta' => $delta]);
    }
    else {
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
      // Saving the adjustment triggers material_inventory_totals_entity_insert(),
      // which applies the delta and stamps field_material_last_inventoried on
      // the material for physical-count reasons like these.
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

}
