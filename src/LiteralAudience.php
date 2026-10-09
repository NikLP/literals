<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Session\AccountInterface;
use Drupal\literals\Entity\Audience;

/**
 * Who a literal is visible to.
 *
 * One audience per literal, a literal_audience config entity. An account sees
 * the audiences whose "view {id} literals" permission it holds, so roles are
 * granted audiences on the normal permissions page. The shipped audiences
 * are "anonymous" (granted to everyone), "authenticated" (signed-in users)
 * and "restricted" (granted to nobody until a site decides). The same value
 * answers a single access check and a list filter, because the set an
 * account can see is just a list of values to match against. An audience
 * that no longer exists is visible to nobody.
 */
final class LiteralAudience {

  /**
   * Visible to everyone, anonymous visitors included (by default grant).
   */
  public const ANONYMOUS = 'anonymous';

  /**
   * Visible to any signed-in account (by default grant).
   */
  public const AUTHENTICATED = 'authenticated';

  /**
   * Visible only to accounts granted it explicitly.
   */
  public const RESTRICTED = 'restricted';

  /**
   * Returns the audience values an account can see.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return string[]
   *   The IDs of the audiences whose permission the account holds.
   */
  public static function visibleTo(AccountInterface $account): array {
    $values = [];
    foreach (self::load() as $audience) {
      if ($account->hasPermission($audience->getPermissionName())) {
        $values[] = (string) $audience->id();
      }
    }
    return $values;
  }

  /**
   * Returns the options for the audience select.
   *
   * @return array
   *   Labels keyed by audience ID, in weight order.
   */
  public static function options(): array {
    $options = [];
    foreach (self::load() as $audience) {
      $options[$audience->id()] = $audience->label();
    }
    return $options;
  }

  /**
   * Loads the audiences in weight order.
   *
   * @return \Drupal\literals\Entity\Audience[]
   *   The audiences keyed by ID.
   */
  protected static function load(): array {
    $audiences = \Drupal::entityTypeManager()->getStorage('literal_audience')->loadMultiple();
    uasort($audiences, [Audience::class, 'sort']);
    return $audiences;
  }

}
