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
   * A shortfall worth $50 or more, or any shortfall on an item with backstock.
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
   * Works out the flags for one count.
   *
   * @param array $count
   *   Keyed by:
   *   - delta: (int) counted total minus system count.
   *   - system_before: (int) system count just before the count.
   *   - previous_delta: (int|null) delta of the item's previous count.
   *   - restock_since_previous: (bool) a delivery was logged since then.
   *   - unit_cost: (float) store price per unit.
   *   - has_backstock: (bool) the item has a backstock location.
   *
   * @return string[]
   *   Flag codes, in the order of labels().
   */
  public static function evaluate(array $count): array {
    $delta = (int) $count['delta'];
    if ($delta === 0) {
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
    if ($delta < 0 && ($size * (float) $count['unit_cost'] >= self::LOSS_VALUE || !empty($count['has_backstock']))) {
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
      self::LOSS => new TranslatableMarkup('Loss worth checking (over $50, or the item has backstock)'),
    ];
  }

}
