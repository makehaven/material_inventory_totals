<?php

namespace Drupal\material_inventory_totals\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\material_inventory_totals\CountReasons;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Staff list of higher-than-expected counts the counter could not explain.
 *
 * Counters pick "Not sure, staff will check" so they can save and move on.
 * The quantity is already applied; staff only decide what the extra was, and
 * the adjustment's reason is changed to match. Nothing here moves stock.
 */
class CountReviewForm extends FormBase {

  /**
   * Most rows shown at once.
   */
  protected const LIMIT = 200;

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
    return 'material_inventory_count_review_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $storage = $this->entityTypeManager->getStorage('material_inventory');
    $query = $storage->getQuery()
      ->condition('type', 'inventory_adjustment')
      ->condition('field_inventory_change_reason', CountReasons::NEEDS_REVIEW)
      ->sort('created', 'DESC')
      ->range(0, self::LIMIT)
      ->accessCheck(FALSE);
    $material_filter = (int) $this->getRequest()->query->get('material');
    if ($material_filter) {
      $query->condition('field_inventory_ref_material', $material_filter);
    }
    $adjustments = $storage->loadMultiple($query->execute());

    $choices = CountReasons::positiveChoices();
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Counts that came in higher than the system count, where the counter was not sure why. The counted number is already in the system; only the reason is open. Check deliveries, receipts and backstock, then pick what it was.') . '</p>'
      . '<ul><li><strong>' . $choices[CountReasons::RESTOCK_UNLOGGED]['label'] . '</strong>: ' . $choices[CountReasons::RESTOCK_UNLOGGED]['help'] . '</li>'
      . '<li><strong>' . $choices[CountReasons::COUNT_FOUND]['label'] . '</strong>: ' . $choices[CountReasons::COUNT_FOUND]['help'] . '</li></ul>',
    ];
    if ($material_filter) {
      $form['filter'] = [
        '#markup' => '<p>' . $this->t('Showing one item. <a href=":url">Show all</a>', [':url' => Url::fromRoute('material_inventory_totals.count_review')->toString()]) . '</p>',
      ];
    }

    $form['rows'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Counted'),
        $this->t('Item'),
        $this->t('Extra'),
        $this->t('Counted by'),
        $this->t('Notes'),
        $this->t('It was'),
      ],
      '#empty' => $this->t('Nothing to review.'),
    ];

    foreach ($adjustments as $id => $adjustment) {
      $material = $adjustment->get('field_inventory_ref_material')->entity;
      $owner = $adjustment->hasField('uid') ? $adjustment->get('uid')->entity : NULL;
      $row = [];
      $row['created'] = ['#markup' => $this->dateFormatter->format($adjustment->get('created')->value, 'short')];
      $row['material'] = $material
        ? ['#type' => 'link', '#title' => $material->label(), '#url' => $material->toUrl()]
        : ['#markup' => $this->t('(deleted item)')];
      $row['quantity'] = ['#markup' => '+' . (int) $adjustment->get('field_inventory_quantity_change')->value];
      $row['owner'] = ['#markup' => $owner ? $owner->getDisplayName() : ''];
      $row['memo'] = ['#plain_text' => (string) $adjustment->get('field_inventory_change_memo')->value];
      $row['actions'] = ['#type' => 'container'];
      foreach ([CountReasons::RESTOCK_UNLOGGED, CountReasons::COUNT_FOUND] as $code) {
        $row['actions'][$code] = [
          '#type' => 'submit',
          '#value' => $choices[$code]['label'],
          '#name' => 'resolve_' . $id . '_' . $code,
          '#adjustment_id' => $id,
          '#resolve_reason' => $code,
          '#attributes' => ['class' => ['btn-sm', 'me-1', 'mb-1']],
        ];
      }
      $form['rows'][$id] = $row;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $id = $trigger['#adjustment_id'] ?? NULL;
    $reason = $trigger['#resolve_reason'] ?? NULL;
    if (!$id || !in_array($reason, [CountReasons::RESTOCK_UNLOGGED, CountReasons::COUNT_FOUND], TRUE)) {
      return;
    }

    $adjustment = $this->entityTypeManager->getStorage('material_inventory')->load($id);
    // Someone else may have resolved it in the meantime.
    if (!$adjustment || $adjustment->get('field_inventory_change_reason')->value !== CountReasons::NEEDS_REVIEW) {
      $this->messenger()->addWarning($this->t('That count was already reviewed.'));
      return;
    }

    $note = $this->t('Reviewed by @name', ['@name' => $this->currentUser()->getDisplayName()]);
    $memo = trim((string) $adjustment->get('field_inventory_change_memo')->value);
    $adjustment->set('field_inventory_change_reason', $reason);
    $adjustment->set('field_inventory_change_memo', mb_substr($memo === '' ? (string) $note : $memo . ' | ' . $note, 0, 255));
    // The quantity is unchanged, so the material's totals do not move.
    $adjustment->save();

    $material = $adjustment->get('field_inventory_ref_material')->entity;
    $this->messenger()->addStatus($this->t('@item: recorded as "@reason".', [
      '@item' => $material ? $material->label() : $this->t('Item'),
      '@reason' => CountReasons::positiveChoices()[$reason]['label'],
    ]));
  }

}
