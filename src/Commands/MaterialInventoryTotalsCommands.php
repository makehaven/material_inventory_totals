<?php

namespace Drupal\material_inventory_totals\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\material_inventory_totals\Service\InventoryTotalsCalculator;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for material inventory totals.
 */
class MaterialInventoryTotalsCommands extends DrushCommands {

  /**
   * Calculator service.
   *
   * @var \Drupal\material_inventory_totals\Service\InventoryTotalsCalculator
   */
  protected InventoryTotalsCalculator $calculator;

  /**
   * Node storage.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected $nodeStorage;

  /**
   * Constructs the commands service.
   */
  public function __construct(InventoryTotalsCalculator $calculator, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->calculator = $calculator;
    $this->nodeStorage = $entity_type_manager->getStorage('node');
  }

  /**
   * Rebuilds cached inventory totals for materials.
   *
   * @command material-inventory-totals:rebuild
   * @option nid Rebuild a single material by node ID.
   * @option limit Limit how many materials to process.
   *
   * @aliases mitt-rebuild material-inventory-totals-rebuild
   */
  public function rebuild(array $options = ['nid' => NULL, 'limit' => NULL]): void {
    $nid = $options['nid'] !== NULL ? (int) $options['nid'] : NULL;
    $limit = $options['limit'] !== NULL ? (int) $options['limit'] : NULL;

    $nids = [];
    if ($nid !== NULL) {
      $nids = [$nid];
    }
    else {
      $query = $this->nodeStorage->getQuery()
        ->condition('type', 'material')
        ->sort('nid', 'ASC')
        ->accessCheck(FALSE);

      if ($limit !== NULL && $limit > 0) {
        $query->range(0, $limit);
      }

      $nids = $query->execute();
    }

    if (empty($nids)) {
      $this->logger()->note('No materials matched the provided criteria.');
      return;
    }

    $processed = 0;
    foreach ($nids as $material_id) {
      $summary = $this->calculator->recalculate((int) $material_id);
      $processed++;

      if (empty($summary)) {
        $this->logger()->warning('Skipped material @nid because it could not be loaded.', ['@nid' => $material_id]);
        continue;
      }

      if (!empty($summary['mismatch'])) {
        $this->logger()->notice('Material @nid totals corrected from @stored to @actual.', [
          '@nid' => $material_id,
          '@stored' => $summary['stored_count'],
          '@actual' => $summary['calculated_count'],
        ]);
      }
      else {
        $this->logger()->notice('Material @nid totals already accurate (count: @count).', [
          '@nid' => $material_id,
          '@count' => $summary['calculated_count'],
        ]);
      }
    }

    $this->logger()->notice('Processed @count material nodes.', ['@count' => $processed]);
  }
}
