<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a "view {audience} literals" permission per literal audience.
 *
 * Each permission depends on its audience, so deleting an audience removes
 * the grant from every role.
 */
class LiteralAudiencePermissions implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  /**
   * Constructs the permission provider.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Returns the per-audience permissions.
   *
   * @return array
   *   Permission definitions keyed by machine name.
   */
  public function permissions(): array {
    $permissions = [];
    /** @var \Drupal\literals\Entity\Audience $audience */
    foreach ($this->entityTypeManager->getStorage('literal_audience')->loadMultiple() as $audience) {
      $permissions[$audience->getPermissionName()] = [
        'title' => $this->t('View %audience literals', ['%audience' => $audience->label()]),
        'description' => $this->t('See literals whose audience is %audience.', ['%audience' => $audience->label()]),
        'dependencies' => [$audience->getConfigDependencyKey() => [$audience->getConfigDependencyName()]],
      ];
    }
    return $permissions;
  }

}
