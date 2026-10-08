<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists literal types with their resolver, validation and usage.
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
   * @param \Drupal\Core\Entity\EntityStorageInterface $literalStorage
   *   The literal storage.
   * @param \Drupal\literals\LiteralResolverManager $resolverManager
   *   The literal resolver plugin manager.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, protected EntityStorageInterface $literalStorage, protected LiteralResolverManager $resolverManager) {
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
      $manager->getStorage('literal'),
      $container->get('plugin.manager.literal_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Name');
    $header['id'] = $this->t('Machine name');
    $header['resolver'] = $this->t('Resolver');
    $header['validate_as'] = $this->t('Validated as');
    $header['description'] = $this->t('Description');
    $header['count'] = $this->t('Literals');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\literals\Entity\LiteralType $entity */
    $options = $this->resolverManager->getOptions();
    $resolver = $entity->getResolver();
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['resolver'] = $options[$resolver] ?? $resolver;
    $row['validate_as'] = $resolver === 'text' ? $entity->getValidateAs() : '-';
    $row['description'] = $entity->get('description');
    $row['count'] = $this->getCounts()[$entity->id()] ?? 0;
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
      $rows = $this->literalStorage->getAggregateQuery()
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
