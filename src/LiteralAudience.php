<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\Role;

/**
 * Who a literal is visible to.
 *
 * One value per literal: "public" (everyone, anonymous included),
 * "authenticated" (any signed-in account) or a role ID. The same value
 * answers a single access check and a list filter, because the set an
 * account can see is just a list of values to match against.
 */
final class LiteralAudience {

  /**
   * Visible to everyone, including anonymous.
   */
  public const PUBLIC = 'public';

  /**
   * Visible to any signed-in account.
   */
  public const AUTHENTICATED = 'authenticated';

  /**
   * Returns the audience values an account can see.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return string[]
   *   Audience values: public, authenticated if signed in, and the
   *   account's role IDs.
   */
  public static function visibleTo(AccountInterface $account): array {
    $values = [self::PUBLIC];
    if ($account->isAuthenticated()) {
      $values[] = self::AUTHENTICATED;
    }
    return array_values(array_unique(array_merge($values, $account->getRoles())));
  }

  /**
   * Returns the options for the audience select.
   *
   * @return array
   *   Labels keyed by audience value.
   */
  public static function options(): array {
    $options = [
      self::PUBLIC => t('Everyone (including anonymous)'),
      self::AUTHENTICATED => t('Signed-in users'),
    ];
    foreach (Role::loadMultiple() as $id => $role) {
      if (!in_array($id, ['anonymous', 'authenticated'], TRUE)) {
        $options[$id] = t('Role: @role', ['@role' => $role->label()]);
      }
    }
    return $options;
  }

}
