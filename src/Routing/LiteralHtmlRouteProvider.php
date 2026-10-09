<?php

declare(strict_types=1);

namespace Drupal\literals\Routing;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;

/**
 * Serves the canonical URL of a literal as its edit form.
 *
 * A literal has no public page: its canonical URL would only render the entity
 * through a view display, which nothing here configures. The URL stays so
 * code that asks for it still gets a valid link, and it opens the edit form
 * for accounts allowed to update the literal and is denied for everyone else.
 */
class LiteralHtmlRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  protected function getCanonicalRoute(EntityTypeInterface $entity_type) {
    $route = parent::getCanonicalRoute($entity_type);
    $edit = $this->getEditFormRoute($entity_type);
    if ($route && $edit) {
      $route->setDefaults($edit->getDefaults());
      $route->setRequirements($edit->getRequirements());
    }
    return $route;
  }

}
