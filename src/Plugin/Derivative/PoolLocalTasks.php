<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives one tab per literal pool on the Literals list.
 *
 * The pool page filters literals by access, so a pool the viewer cannot see
 * lists nothing.
 */
class PoolLocalTasks extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Constructs a PoolLocalTasks deriver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $weight = 0;
    foreach ($this->entityTypeManager->getStorage('literal_pool')->loadMultiple() as $pool) {
      $this->derivatives[$pool->id()] = [
        'title' => $pool->label(),
        'route_name' => 'view.literals.page_pool',
        'route_parameters' => ['arg_0' => $pool->id()],
        'base_route' => 'view.literals.page_all',
        'weight' => $weight++,
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
