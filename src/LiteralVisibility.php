<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Session\AccountInterface;

/**
 * Who a literal is visible to.
 *
 * Two flat permissions: "view literals" sees every literal not marked
 * restricted, "view restricted literals" sees every literal. Editors and
 * administrators see everything. The same answer serves a single access
 * check and a list filter, as the set of `restricted` flag values an
 * account may see.
 */
final class LiteralVisibility {

  /**
   * See literals not marked restricted.
   */
  public const VIEW = 'view literals';

  /**
   * See every literal, restricted ones included.
   */
  public const VIEW_RESTRICTED = 'view restricted literals';

  /**
   * Returns the `restricted` flag values an account may see.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return int[]|null
   *   NULL when the account sees every literal, else the visible flag values
   *   (empty when it sees none).
   */
  public static function visibleFlags(AccountInterface $account): ?array {
    if ($account->hasPermission('administer literals') || $account->hasPermission('edit literals') || $account->hasPermission(self::VIEW_RESTRICTED)) {
      return NULL;
    }
    return $account->hasPermission(self::VIEW) ? [0] : [];
  }

  /**
   * Whether an account may see a literal with the given flag.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param bool $restricted
   *   The literal's restricted flag.
   *
   * @return bool
   *   TRUE when visible.
   */
  public static function canSee(AccountInterface $account, bool $restricted): bool {
    $flags = self::visibleFlags($account);
    return $flags === NULL || in_array((int) $restricted, $flags, TRUE);
  }

}
