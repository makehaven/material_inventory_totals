<?php

namespace Drupal\material_inventory_totals;

/**
 * Figures shared by the finalized inventory page and its summary email.
 *
 * Works on the array InventorySessionManager::summarize() returns. Weighed or
 * estimated items and items priced by use are counted, but the system has no
 * number to check them against, so they cannot match or miss.
 */
final class InventorySummary {

  /**
   * How many counted items could be checked against the system.
   */
  public static function checkable(array $summary): int {
    return (int) $summary['matched'] + (int) $summary['over']['n'] + (int) $summary['short']['n'];
  }

  /**
   * The share of checkable items that matched, or NULL if there were none.
   */
  public static function matchPercent(array $summary): ?int {
    $checkable = self::checkable($summary);
    return $checkable ? (int) round((int) $summary['matched'] / $checkable * 100) : NULL;
  }

  /**
   * A reason label without "Count: ", which the table's context already says.
   */
  public static function reasonLabel(string $label): string {
    return ucfirst(preg_replace('/^Count:\s*/i', '', $label));
  }

}
