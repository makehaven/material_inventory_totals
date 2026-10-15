<?php

namespace Drupal\material_inventory_totals;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Reason codes recorded by a physical stock count.
 *
 * A count that comes in ABOVE the system count used to be filed as "other",
 * which hid two very different things under one code: stock that was
 * delivered or pulled from backstock without anyone logging a restock, and
 * stock that was genuinely miscounted or found. Mixing them made real
 * shrinkage invisible, because unlogged restocks cancelled out losses. The
 * counter now has to say which one it was.
 *
 * Every value here must be an allowed value of
 * field.storage.material_inventory.field_inventory_change_reason.
 */
final class CountReasons {

  /**
   * Count matched the system count.
   */
  public const VERIFIED = 'verification';

  /**
   * Count came in below the system count: loss / shrinkage.
   */
  public const LOSS = 'lossage';

  /**
   * Count came in above because stock arrived and nobody logged a restock.
   */
  public const RESTOCK_UNLOGGED = 'restock_unlogged';

  /**
   * Count came in above because the stock was already here (miscount, found).
   */
  public const COUNT_FOUND = 'count_found';

  /**
   * A delivery logged when it arrived. Not a count: it adds what was received.
   */
  public const RESTOCK = 'restock';

  /**
   * Legacy code for a positive count whose cause was not given.
   *
   * Still written by API clients that do not send positive_reason yet.
   */
  public const UNCLASSIFIED = 'other';

  /**
   * Choices offered when a count comes in above the system count.
   *
   * @return array<string, array{label: \Drupal\Core\StringTranslation\TranslatableMarkup, help: \Drupal\Core\StringTranslation\TranslatableMarkup}>
   *   Keyed by reason code.
   */
  public static function positiveChoices(): array {
    return [
      self::RESTOCK_UNLOGGED => [
        'label' => new TranslatableMarkup("Restock that wasn't logged"),
        'help' => new TranslatableMarkup('A delivery came in or backstock was put out, and nobody logged it as a restock.'),
      ],
      self::COUNT_FOUND => [
        'label' => new TranslatableMarkup('Count correction (found)'),
        'help' => new TranslatableMarkup('The stock was already here: last count was off, or items turned up somewhere else.'),
      ],
    ];
  }

  /**
   * Works out the reason code for a count.
   *
   * @param int $delta
   *   Counted total minus system count.
   * @param string|null $positive_reason
   *   The counter's choice when $delta is positive.
   *
   * @return string
   *   The reason code to store.
   */
  public static function forDelta(int $delta, ?string $positive_reason = NULL): string {
    if ($delta === 0) {
      return self::VERIFIED;
    }
    if ($delta < 0) {
      return self::LOSS;
    }
    return isset(self::positiveChoices()[$positive_reason]) ? $positive_reason : self::UNCLASSIFIED;
  }

}
