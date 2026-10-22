<?php

namespace Drupal\material_inventory_totals\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\material_inventory_totals\CountReasons;
use Drupal\material_inventory_totals\InventoryFlagRules;
use Drupal\material_inventory_totals\Service\InventorySessionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Recheck one flagged count: confirm it, recount it, or leave it for staff.
 */
class InventoryFlagForm extends FormBase {

  /**
   * How many recent adjustments to show for context.
   */
  protected const HISTORY = 6;

  /**
   * Constructs the form.
   */
  public function __construct(
    protected InventorySessionManager $sessions,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('material_inventory_totals.sessions'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'material_inventory_flag_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $flag = NULL) {
    $flag = $this->sessions->flag((int) $flag);
    if (!$flag) {
      throw new NotFoundHttpException();
    }
    $form_state->set('flag_id', $flag->id);
    $form['#cache']['max-age'] = 0;
    $material = $this->entityTypeManager->getStorage('node')->load($flag->material_id);
    $adjustment = $this->entityTypeManager->getStorage('material_inventory')->load($flag->adjustment_id);

    if ($flag->status !== 'open') {
      $form['done'] = ['#markup' => '<p>' . $this->t('This item was already rechecked.') . '</p>'];
      $form['back'] = $this->backLink();
      return $form;
    }

    $delta = (int) $flag->delta;
    $labels = InventoryFlagRules::labels();
    $why = array_map(fn($code) => Html::escape((string) ($labels[$code] ?? $code)), array_filter(explode(',', $flag->flags)));
    $counter = $adjustment && $adjustment->hasField('uid') ? $adjustment->get('uid')->entity : NULL;

    $form['item'] = [
      '#markup' => '<h2>' . Html::escape($material ? $material->label() : '#' . $flag->material_id) . '</h2>'
      . '<p>' . $this->t('Counted @delta compared with the system@who@when.', [
        '@delta' => sprintf('%+d', $delta),
        '@who' => $counter ? ' ' . $this->t('by @name', ['@name' => $counter->getDisplayName()]) : '',
        '@when' => $adjustment ? ', ' . $this->dateFormatter->format($adjustment->get('created')->value, 'short') : '',
      ]) . '</p>'
      . '<p><strong>' . $this->t('Flagged because:') . '</strong> ' . implode('; ', $why) . '</p>',
    ];
    if ($material && $material->hasField('field_material_backstock') && !$material->get('field_material_backstock')->isEmpty()) {
      $form['backstock'] = [
        '#markup' => '<p><strong>' . $this->t('Backstock:') . '</strong> ' . Html::escape($material->get('field_material_backstock')->value) . ' ' . $this->t('(include it in the count)') . '</p>',
      ];
    }
    $form['history'] = $this->historyTable((int) $flag->material_id);

    $form['decision'] = [
      '#type' => 'radios',
      '#title' => $this->t('What did you find?'),
      '#required' => TRUE,
      '#options' => [
        'recount' => $this->t('I recounted it'),
        'confirm' => $this->t('The count was right'),
        'not_sure' => $this->t('Not sure, staff will check'),
      ],
    ];
    $form['total'] = [
      '#type' => 'number',
      '#title' => $this->t('What is there now (shelf + backstock)'),
      '#min' => 0,
      '#description' => $this->t('System count now: @n. The original count is corrected so stock matches what you counted.', ['@n' => $this->sessions->stockNow((int) $flag->material_id)]),
      '#states' => ['visible' => [':input[name="decision"]' => ['value' => 'recount']]],
    ];
    $choices = CountReasons::positiveChoices();
    unset($choices[CountReasons::NEEDS_REVIEW]);
    $form['positive_reason'] = [
      '#type' => 'radios',
      '#title' => $this->t('It is higher than the system expected. Why?'),
      '#options' => array_map(fn($c) => $c['label'], $choices),
      '#description' => $this->t('Needed when the count stays above the system count. Not sure? Choose "Not sure, staff will check" above.'),
      '#states' => ['invisible' => [':input[name="decision"]' => ['value' => 'not_sure']]],
    ];
    if ($delta <= 0) {
      $form['positive_reason']['#states'] = ['visible' => [':input[name="decision"]' => ['value' => 'recount']]];
    }
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Note'),
      '#maxlength' => 200,
      '#placeholder' => $this->t('e.g. 50 more in back room'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save'), '#button_type' => 'primary'];
    $form['actions']['back'] = $this->backLink();
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $flag = $this->sessions->flag((int) $form_state->get('flag_id'));
    if (!$flag || $flag->status !== 'open') {
      return;
    }
    $decision = $form_state->getValue('decision');
    $reason = $form_state->getValue('positive_reason');
    $valid_reason = $reason && isset(CountReasons::positiveChoices()[$reason]);
    if ($decision === 'confirm' && (int) $flag->delta > 0 && !$valid_reason) {
      $form_state->setErrorByName('positive_reason', $this->t('Say why it is higher, or choose "Not sure, staff will check".'));
    }
    if ($decision === 'recount') {
      $total = $form_state->getValue('total');
      if ($total === '' || $total === NULL || !is_numeric($total) || (int) $total < 0) {
        $form_state->setErrorByName('total', $this->t('Enter what you counted.'));
        return;
      }
      $now = $this->sessions->stockNow((int) $flag->material_id);
      $corrected = (int) $flag->delta + ((int) $total - $now);
      if ($corrected > 0 && !$valid_reason) {
        $form_state->setErrorByName('positive_reason', $this->t('Your recount is still @n above the system. Say why, or choose "Not sure, staff will check".', ['@n' => $corrected]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $flag = $this->sessions->flag((int) $form_state->get('flag_id'));
    $form_state->setRedirect('material_inventory_totals.inventory');
    if (!$flag || $flag->status !== 'open') {
      $this->messenger()->addWarning($this->t('Someone already rechecked this item.'));
      return;
    }
    $uid = (int) $this->currentUser()->id();
    $note = trim((string) $form_state->getValue('note'));
    $reason = $form_state->getValue('positive_reason') ?: NULL;
    switch ($form_state->getValue('decision')) {
      case 'recount':
        $this->sessions->recount($flag, $uid, (int) $form_state->getValue('total'), $reason, $note);
        $this->messenger()->addStatus($this->t('Recount saved; stock now matches what you counted.'));
        break;

      case 'confirm':
        $this->sessions->confirm($flag, $uid, $reason, $note);
        $this->messenger()->addStatus($this->t('Count confirmed.'));
        break;

      case 'not_sure':
        $this->sessions->notSure($flag, $uid, $note);
        $this->messenger()->addStatus($this->t('Left for staff to check.'));
        break;
    }
  }

  /**
   * The item's latest adjustments, so the person can see what happened.
   */
  protected function historyTable(int $material_id): array {
    $storage = $this->entityTypeManager->getStorage('material_inventory');
    $ids = $storage->getQuery()
      ->condition('type', 'inventory_adjustment')
      ->condition('field_inventory_ref_material', $material_id)
      ->sort('id', 'DESC')
      ->range(0, self::HISTORY)
      ->accessCheck(FALSE)
      ->execute();
    $reasons = $this->sessions->reasonLabels();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $a) {
      $reason = $a->get('field_inventory_change_reason')->value;
      $rows[] = [
        $this->dateFormatter->format($a->get('created')->value, 'short'),
        (string) ($reasons[$reason] ?? $reason),
        sprintf('%+d', (int) $a->get('field_inventory_quantity_change')->value),
        (string) $a->get('field_inventory_change_memo')->value,
      ];
    }
    return [
      '#type' => 'table',
      '#caption' => $this->t('Recent activity'),
      '#header' => [$this->t('When'), $this->t('What'), $this->t('Change'), $this->t('Note')],
      '#rows' => $rows,
    ];
  }

  /**
   * Link back to the inventory page.
   */
  protected function backLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Back to the inventory'),
      '#url' => Url::fromRoute('material_inventory_totals.inventory'),
    ];
  }

}
