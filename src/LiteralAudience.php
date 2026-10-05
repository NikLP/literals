<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Session\AccountInterface;

/**
 * Who a literal is visible to.
 *
 * One value per literal: "anonymous" (everyone, anonymous included),
 * "authenticated" (any signed-in account) or "restricted" (accounts with the
 * "view restricted literals" permission, so roles are granted it on the
 * normal permissions page). The same value
 * answers a single access check and a list filter, because the set an
 * account can see is just a list of values to match against.
 */
final class LiteralAudience {

  /**
   * Visible to everyone, anonymous visitors included.
   */
  public const ANONYMOUS = 'anonymous';

  /**
   * Visible to any signed-in account.
   */
  public const AUTHENTICATED = 'authenticated';

  /**
   * Visible only to accounts with the "view restricted literals" permission.
   */
  public const RESTRICTED = 'restricted';

  /**
   * Returns the audience values an account can see.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return string[]
   *   Audience values: anonymous, authenticated if signed in, restricted if the
   *   account holds the permission.
   */
  public static function visibleTo(AccountInterface $account): array {
    $values = [self::ANONYMOUS];
    if ($account->isAuthenticated()) {
      $values[] = self::AUTHENTICATED;
    }
    if ($account->hasPermission('view restricted literals')) {
      $values[] = self::RESTRICTED;
    }
    return $values;
  }

  /**
   * Returns the options for the audience select.
   *
   * @return array
   *   Labels keyed by audience value.
   */
  public static function options(): array {
    $options = [
      self::ANONYMOUS => t('Anonymous (visible to everyone)'),
      self::AUTHENTICATED => t('Signed-in users'),
      self::RESTRICTED => t('Restricted (needs the "View restricted literals" permission)'),
    ];
    return $options;
  }

}
