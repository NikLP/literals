<?php

declare(strict_types=1);

namespace Drupal\literals\Hook;

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\literals\LiteralAudience;

/**
 * Hook implementations for the literals module.
 */
class LiteralsHooks {

  /**
   * Implements hook_gin_content_form_routes().
   *
   * Gives the literal edit forms Gin's node-style sidebar layout.
   *
   * @return array
   *   Route names.
   */
  #[Hook('gin_content_form_routes')]
  public function ginContentFormRoutes(): array {
    return [
      'entity.literal.add_form',
      'entity.literal.edit_form',
    ];
  }

  /**
   * Implements hook_query_alter().
   *
   * Restricts literal queries to the audiences the account can see, so a
   * list never includes a literal (or its description) the viewer could not
   * open. Applies to entity queries that check access and to Views listing
   * literals.
   */
  #[Hook('query_alter')]
  public function queryAlter(AlterableInterface $query): void {
    if (!$query instanceof SelectInterface) {
      return;
    }
    if (!$query->hasTag('literal_access') && !$query->hasTag('views')) {
      return;
    }
    $alias = NULL;
    foreach ($query->getTables() as $table_alias => $info) {
      if (($info['table'] ?? NULL) === 'literal') {
        $alias = $table_alias;
        break;
      }
    }
    if ($alias === NULL) {
      return;
    }
    $account = $query->getMetaData('account') ?: \Drupal::currentUser();
    if ($account->hasPermission('administer literals')) {
      return;
    }
    $query->condition("$alias.audience", LiteralAudience::visibleTo($account), 'IN');
  }

}
