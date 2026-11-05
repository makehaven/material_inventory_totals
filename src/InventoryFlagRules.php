<?php

namespace Drupal\material_inventory_totals;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Decides which counts in an inventory need a second look.
 *
 * Tested against a year of counts (2025-10 to 2026-10): together these flag
 * 136 of 142 high counts and about 20-45 items per monthly inventory. Most
 * high counts sat next to a low count on the same item, which is a counting
 * swing (usually backstock in one count and not the next), not a real gain.
 *
 * A difference worth less than RECHECK_FLOOR is recorded but never flagged: in
 * the November 2026 inventory 12 of 23 rechecks were under that, worth $16.80
 * together, and a recheck costs paid staff time. Any shortfall on an item with
 * backstock was flagged until then too. Forgetting backstock tends to show as
 * a big jump or a swing, which are still flagged.
 */
final class InventoryFlagRules {

  /**
   * The count went the other way from the item's previous count.
   */
  public const SWING = 'swing';

  /**
   * The count went up and no delivery was logged since the previous count.
   */
  public const ROSE_UNLOGGED = 'rose_unlogged';

  /**
   * The count is more than 25% and more than 3 units off the system count.
   */
  public const BIG_JUMP = 'big_jump';

  /**
   * A shortfall worth $50 or more.
   */
  public const LOSS = 'loss';

  /**
   * A big jump must be more than this many units.
   */
  public const JUMP_MIN_UNITS = 3;

  /**
   * A big jump must be more than this share of the system count.
   */
  public const JUMP_SHARE = 0.25;

  /**
   * A shortfall worth at least this much (store price) is flagged.
   */
  public const LOSS_VALUE = 50.0;

  /**
   * A difference worth less than this (store price) is not rechecked.
   */
  public const RECHECK_FLOOR = 5.0;

  /**
   * Works out the flags for one count.
   *
   * @param array $count
   *   Keyed by:
   *   - delta: (int) counted total minus system count.
   *   - system_before: (int) system count just before the count.
   *   - previous_delta: (int|null) delta of the item's previous count.
   *   - restock_since_previous: (bool) a delivery was logged since then.
   *   - unit_cost: (float) store price per unit.
   *
   * @return string[]
   *   Flag codes, in the order of labels().
   */
  public static function evaluate(array $count): array {
    $delta = (int) $count['delta'];
    if ($delta === 0) {
      return [];
    }
    // An item with no price stays eligible: its value is unknown, not small.
    $unit_cost = (float) $count['unit_cost'];
    if ($unit_cost > 0 && abs($delta) * $unit_cost < self::RECHECK_FLOOR) {
      return [];
    }
    $flags = [];
    $previous = $count['previous_delta'] ?? NULL;
    if ($previous !== NULL && $previous !== 0 && ($delta > 0) !== ($previous > 0)) {
      $flags[] = self::SWING;
    }
    if ($delta > 0 && empty($count['restock_since_previous'])) {
      $flags[] = self::ROSE_UNLOGGED;
    }
    $size = abs($delta);
    if ($size > self::JUMP_MIN_UNITS && $size > self::JUMP_SHARE * abs((int) $count['system_before'])) {
      $flags[] = self::BIG_JUMP;
    }
    if ($delta < 0 && $size * $unit_cost >= self::LOSS_VALUE) {
      $flags[] = self::LOSS;
    }
    return $flags;
  }

  /**
   * What each flag means, for the person rechecking.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Keyed by flag code.
   */
  public static function labels(): array {
    return [
      self::SWING => new TranslatableMarkup('Went the other way from the last count'),
      self::ROSE_UNLOGGED => new TranslatableMarkup('Went up, no delivery logged'),
      self::BIG_JUMP => new TranslatableMarkup('Big jump from the system count'),
      self::LOSS => new TranslatableMarkup('Loss worth checking ($50 or more)'),
    ];
  }

}
