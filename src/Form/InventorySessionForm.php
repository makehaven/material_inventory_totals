<?php

namespace Drupal\material_inventory_totals\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\material_inventory_totals\InventoryFlagRules;
use Drupal\material_inventory_totals\InventorySummary;
use Drupal\material_inventory_totals\Service\InventorySessionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The store inventory page: start, complete, recheck flags, finalize.
 */
class InventorySessionForm extends FormBase {

  /**
   * Permission that may finalize an inventory (managers).
   */
  public const FINALIZE_PERMISSION = 'finalize store inventory';

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
    return 'material_inventory_session_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#cache']['max-age'] = 0;
    $can_finalize = $this->currentUser()->hasPermission(self::FINALIZE_PERMISSION);
    $requested = (int) $this->getRequest()->query->get('session');
    $session = $requested ? $this->sessions->load($requested) : $this->sessions->current();
    $form_state->set('session_id', $session->id ?? NULL);

    if ($session && $session->finalized) {
      $form['summary'] = $this->summaryBuild($session);
      $form['flags'] = $this->flagsTable($session, FALSE);
      $form['oddities'] = $this->odditiesLink();
      $form['back'] = [
        '#type' => 'link',
        '#title' => $this->t('Back to the current inventory'),
        '#url' => Url::fromRoute('material_inventory_totals.inventory'),
      ];
      return $form;
    }

    if (!$session) {
      $form['intro'] = [
        '#markup' => '<p>' . $this->t('No inventory is open. Start one before counting; every count saved after that, on the website or in the app, is checked when you click "Inventory complete". Counts at other times work as usual. Weighed or estimated items (glazes, roll paper) are counted but not checked.') . '</p>',
      ];
      $form['start'] = [
        '#type' => 'submit',
        '#value' => $this->t('Start inventory'),
        '#name' => 'start',
        '#button_type' => 'primary',
      ];
      $form['oddities'] = $this->odditiesLink();
      $form['history'] = $this->historyBuild();
      return $form;
    }

    $started = $this->t('Started @date by @name.', [
      '@date' => $this->dateFormatter->format($session->started, 'medium'),
      '@name' => $this->userName((int) $session->started_by),
    ]);

    if (!$session->completed) {
      $form['status'] = [
        '#markup' => '<p><strong>' . $this->t('Inventory in progress.') . '</strong> ' . $started . ' '
        . $this->formatPlural($this->sessions->countsSoFar($session), '1 item counted so far.', '@count items counted so far.') . '</p>'
        . '<p>' . $this->t('When counting is done, click "Inventory complete". The site then lists the counts that look wrong so someone can recheck them.') . '</p>',
      ];
      $form['complete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Inventory complete'),
        '#name' => 'complete',
        '#button_type' => 'primary',
      ];
      return $form;
    }

    $open = $this->sessions->openFlagCount((int) $session->id);
    $form['status'] = [
      '#markup' => '<p><strong>' . $this->t('Counting done; rechecking.') . '</strong> ' . $started . ' '
      . $this->t('Completed @date.', ['@date' => $this->dateFormatter->format($session->completed, 'medium')]) . ' '
      . $this->formatPlural($this->sessions->countsSoFar($session), '1 item counted so far.', '@count items counted so far.') . '</p>'
      . '<p>' . $this->t('Counts saved before "Finalize", such as weighed items done later, are included in the summary.') . '</p>'
      . '<p>' . ($open
          ? $this->formatPlural($open, '1 item still needs a recheck. Recount it, confirm the count, or mark it "Not sure".', '@count items still need a recheck. Recount each one, confirm the count, or mark it "Not sure".')
          : $this->t('Every flagged item has been handled.')) . '</p>',
    ];
    $form['flags'] = $this->flagsTable($session, TRUE);

    if (!$can_finalize) {
      $form['finalize_note'] = [
        '#markup' => '<p class="description">' . $this->t('When every item is handled, a manager finalizes the inventory.') . '</p>',
      ];
    }
    else {
      $form['finalize'] = [
        '#type' => 'submit',
        '#value' => $this->t('Finalize inventory'),
        '#name' => 'finalize',
        '#button_type' => 'primary',
        '#disabled' => $open > 0,
      ];
      $form['finalize_help'] = [
        '#markup' => '<p class="description">' . $this->t('Finalizing saves a summary and emails it to staff. Items marked "Not sure" stay on the <a href=":url">count review list</a>.', [
          ':url' => Url::fromRoute('material_inventory_totals.count_review')->toString(),
        ]) . '</p>',
      ];
    }
    $form['oddities'] = $this->odditiesLink();
    return $form;
  }

  /**
   * A line pointing to the oddities report, for those who may see it.
   */
  protected function odditiesLink(): array {
    $url = Url::fromRoute('material_inventory_totals.inventory_oddities');
    return [
      '#markup' => '<p>' . $this->t('<a href=":url">See what looks odd</a>: counts that went up, items losing stock and unusually small sales.', [':url' => $url->toString()]) . '</p>',
      '#access' => $url->access(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $action = $form_state->getTriggeringElement()['#name'] ?? '';
    $uid = (int) $this->currentUser()->id();
    $can_finalize = $this->currentUser()->hasPermission(self::FINALIZE_PERMISSION);
    $session = $form_state->get('session_id') ? $this->sessions->load((int) $form_state->get('session_id')) : NULL;

    try {
      if ($action === 'start') {
        $this->sessions->start($uid);
        $this->messenger()->addStatus($this->t('Inventory started. Count as usual, on the website or in the app.'));
      }
      elseif ($action === 'complete' && $session && !$session->completed) {
        $flagged = $this->sessions->complete($session, $uid);
        $this->messenger()->addStatus($flagged
          ? $this->formatPlural($flagged, 'Counting closed. 1 item needs a recheck.', 'Counting closed. @count items need a recheck.')
          : $this->t('Counting closed. Nothing looks wrong.'));
      }
      elseif ($action === 'finalize' && $session && $can_finalize) {
        $this->sessions->finalize($session, $uid);
        $this->messenger()->addStatus($this->t('Inventory finalized and the summary emailed to staff.'));
        $form_state->setRedirect('material_inventory_totals.inventory', [], ['query' => ['session' => $session->id]]);
      }
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

  /**
   * The table of flagged counts.
   */
  protected function flagsTable(object $session, bool $actions): array {
    $labels = InventoryFlagRules::labels();
    $status_labels = [
      'open' => $this->t('Needs recheck'),
      'confirmed' => $this->t('Count confirmed'),
      'recounted' => $this->t('Recounted'),
      'not_sure' => $this->t('Not sure (staff)'),
    ];
    $table = [
      '#type' => 'table',
      '#header' => [
        $this->t('Item'),
        $this->t('Count vs system'),
        $this->t('Why it was flagged'),
        $this->t('Status'),
        '',
      ],
      '#empty' => $this->t('No counts were flagged.'),
    ];
    $flags = $this->sessions->flags((int) $session->id);
    $materials = $this->entityTypeManager->getStorage('node')->loadMultiple(array_map(fn($f) => $f->material_id, $flags));
    foreach ($flags as $flag) {
      $material = $materials[$flag->material_id] ?? NULL;
      $why = array_map(fn($code) => (string) ($labels[$code] ?? $code), array_filter(explode(',', $flag->flags)));
      $status = (string) $status_labels[$flag->status];
      if ($flag->status !== 'open' && $flag->resolved_by) {
        $status .= ' · ' . $this->userName((int) $flag->resolved_by);
      }
      $row = [
        'item' => $material
          ? ['#type' => 'link', '#title' => $material->label(), '#url' => $material->toUrl()]
          : ['#markup' => '#' . $flag->material_id],
        'delta' => ['#markup' => sprintf('%+d', $flag->delta) . ($flag->value > 0 ? ' <span class="description">($' . number_format((float) $flag->value, 2) . ')</span>' : '')],
        'why' => ['#markup' => implode('<br>', array_map('htmlspecialchars', $why))],
        'status' => ['#plain_text' => $status . ($flag->note ? ' — ' . $flag->note : '')],
        'action' => [],
      ];
      if ($actions && $flag->status === 'open') {
        $row['action'] = [
          '#type' => 'link',
          '#title' => $this->t('Recheck'),
          '#url' => Url::fromRoute('material_inventory_totals.inventory_flag', ['flag' => $flag->id]),
          '#attributes' => ['class' => ['button', 'button--small']],
        ];
      }
      $table[$flag->id] = $row;
    }
    return $table;
  }

  /**
   * A finalized inventory's summary.
   */
  protected function summaryBuild(object $session): array {
    $s = json_decode((string) $session->summary, TRUE) ?: $this->sessions->summarize($session);
    $none = $this->t('—');
    $money = fn($value) => '$' . number_format((float) $value, 2);
    $total_cell = fn($text) => ['data' => ['#markup' => '<strong>' . $text . '</strong>']];

    $results = [
      [$this->t('Matched the system'), $s['matched'], $none],
      [$this->t('Higher than expected'), $s['over']['n'], $money($s['over']['value'])],
      [$this->t('Lower than expected'), $s['short']['n'], $money($s['short']['value'])],
      [$this->t('Weighed or estimated (not checked)'), $s['estimated'] ?? 0, $none],
    ];
    if (!empty($s['checked'])) {
      $results[] = [$this->t('Priced by use (notes only)'), $s['checked'], $none];
    }
    $results[] = [
      $total_cell($this->t('Total items counted')),
      $total_cell((string) $s['items_counted']),
      '',
    ];

    $build = [
      'heading' => [
        '#markup' => '<p><strong>' . $this->t('Inventory finalized @date by @name.', [
          '@date' => $this->dateFormatter->format($session->finalized, 'medium'),
          '@name' => $this->userName((int) $session->finalized_by),
        ]) . '</strong></p>',
      ],
    ];
    $percent = InventorySummary::matchPercent($s);
    if ($percent !== NULL) {
      $build['match_rate'] = [
        '#markup' => '<p>' . $this->t('@m of the @c items we could check matched the system (@p%).', [
          '@m' => $s['matched'],
          '@c' => InventorySummary::checkable($s),
          '@p' => $percent,
        ]) . '</p>',
      ];
    }
    $build['results'] = [
      '#type' => 'table',
      '#header' => [$this->t('Count result'), $this->t('Items'), $this->t('Value of the difference')],
      '#rows' => $results,
    ];
    if (!empty($s['over']['by_reason'])) {
      $reasons = [];
      foreach ($s['over']['by_reason'] as $reason => $n) {
        $reasons[] = [InventorySummary::reasonLabel((string) $reason), $n];
      }
      $build['reasons'] = [
        '#type' => 'table',
        '#header' => [$this->t('Why counts came out higher'), $this->t('Items')],
        '#rows' => $reasons,
      ];
    }
    $build['rechecks'] = [
      '#type' => 'table',
      '#header' => [$this->t('Recheck result'), $this->t('Items')],
      '#rows' => [
        [$this->t('Count confirmed'), $s['flags']['confirmed']],
        [$this->t('Recounted'), $s['flags']['recounted']],
        [$this->t('Not sure (staff)'), $s['flags']['not_sure']],
        [$total_cell($this->t('Total flagged for recheck')), $total_cell((string) $s['flags']['total'])],
      ],
    ];
    $build['rechecks_note'] = [
      '#markup' => '<p class="description">' . $this->t('Flagged items are part of the counts above, not extra items.') . '</p>',
    ];
    return $build;
  }

  /**
   * Links to past inventories.
   */
  protected function historyBuild(): array {
    $items = [];
    foreach ($this->sessions->history() as $past) {
      $items[] = [
        '#type' => 'link',
        '#title' => $this->t('Inventory finalized @date', ['@date' => $this->dateFormatter->format($past->finalized, 'medium')]),
        '#url' => Url::fromRoute('material_inventory_totals.inventory', [], ['query' => ['session' => $past->id]]),
      ];
    }
    return $items ? ['#theme' => 'item_list', '#title' => $this->t('Past inventories'), '#items' => $items] : [];
  }

  /**
   * Display name for a user ID.
   */
  protected function userName(int $uid): string {
    $account = $uid ? $this->entityTypeManager->getStorage('user')->load($uid) : NULL;
    return $account ? $account->getDisplayName() : (string) $this->t('someone');
  }

}
