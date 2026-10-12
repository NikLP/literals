<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists literal types with their usage.
 */
class LiteralTypeListBuilder extends ConfigEntityListBuilder {

  /**
   * Literal counts keyed by type ID, built on first use.
   *
   * @var array<string, int>|null
   */
  protected ?array $counts = NULL;

  /**
   * Constructs the list builder.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The literal type storage.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, protected EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $manager = $container->get('entity_type.manager');
    return new static(
      $entity_type,
      $manager->getStorage($entity_type->id()),
      $manager,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Name');
    $header['count'] = ['data' => $this->t('# in use'), 'style' => 'text-align: center'];
    $header['status'] = ['data' => $this->t('Enabled'), 'style' => 'text-align: center'];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\literals\Entity\LiteralType $entity */
    $row['label'] = $entity->label();
    $row['count'] = [
      'data' => $this->getCounts()[$entity->id()] ?? 0,
      'style' => 'text-align: center',
    ];
    $row['status'] = [
      'data' => $entity->status() ? $this->t('Yes') : $this->t('No'),
      'style' => 'text-align: center',
    ];
    return $row + parent::buildRow($entity);
  }

  /**
   * Returns literal counts keyed by type ID.
   *
   * @return array<string, int>
   *   Counts, absent for types with none.
   */
  protected function getCounts(): array {
    if ($this->counts === NULL) {
      $this->counts = [];
      $rows = $this->entityTypeManager->getStorage('literal')->getAggregateQuery()
        ->accessCheck(FALSE)
        ->groupBy('type')
        ->aggregate('id', 'COUNT')
        ->execute();
      foreach ($rows as $row) {
        $this->counts[$row['type']] = (int) $row['id_count'];
      }
    }
    return $this->counts;
  }

}
