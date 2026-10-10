<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\views\access;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsAccess;
use Drupal\views\Plugin\views\access\AccessPluginBase;
use Symfony\Component\Routing\Route;

/**
 * Access for literal editors: "edit literals" or "administer literals".
 *
 * The literals list uses this instead of core's single-permission plugin so
 * that "administer literals" covers the list as it covers every literal
 * operation in the access handler.
 */
#[ViewsAccess(
  id: 'literal_editor',
  title: new TranslatableMarkup('Literal editors'),
  help: new TranslatableMarkup('Access for users who may edit or administer literals.'),
)]
class LiteralEditorAccess extends AccessPluginBase implements CacheableDependencyInterface {

  /**
   * The permissions, any one of which grants access.
   */
  protected const PERMISSIONS = ['edit literals', 'administer literals'];

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account): bool {
    foreach (self::PERMISSIONS as $permission) {
      if ($account->hasPermission($permission)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterRouteDefinition(Route $route): void {
    // A "+" between permissions means any one of them.
    $route->setRequirement('_permission', implode('+', self::PERMISSIONS));
  }

  /**
   * {@inheritdoc}
   */
  public function summaryTitle(): TranslatableMarkup {
    return new TranslatableMarkup('Literal editors');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['user.permissions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return [];
  }

}
