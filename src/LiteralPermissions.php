<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\BundlePermissionHandlerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\literals\Entity\LiteralPool;

/**
 * Provides dynamic per-pool permissions for literal pools.
 *
 * Called as a permission_callbacks entry in literals.permissions.yml. Each
 * generated permission carries its pool as a config dependency, so deleting
 * a pool removes the grant from every role.
 */
class LiteralPermissions implements ContainerInjectionInterface {

  use AutowireTrait;
  use BundlePermissionHandlerTrait;
  use StringTranslationTrait;

  /**
   * Constructs the permission handler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns per-pool view, create, edit and delete permissions.
   *
   * @return array
   *   Permission definitions keyed by machine name.
   */
  public function permissions(): array {
    return $this->generatePermissions(
      $this->entityTypeManager->getStorage('literal_pool')->loadMultiple(),
      [$this, 'buildPermissions'],
    );
  }

  /**
   * Returns the permissions for one pool.
   *
   * @param \Drupal\literals\Entity\LiteralPool $pool
   *   The pool.
   *
   * @return array
   *   An associative array of permission names and definitions.
   */
  protected function buildPermissions(LiteralPool $pool): array {
    $params = ['%label' => $pool->label()];

    return [
      $pool->getViewPermission() => [
        'title' => $this->t('%label: view literals', $params),
      ],
      $pool->getCreatePermission() => [
        'title' => $this->t('%label: create literals', $params),
      ],
      $pool->getEditPermission() => [
        'title' => $this->t('%label: edit literals', $params),
        'restrict access' => TRUE,
      ],
      $pool->getDeletePermission() => [
        'title' => $this->t('%label: delete literals', $params),
        'restrict access' => TRUE,
      ],
    ];
  }

}
