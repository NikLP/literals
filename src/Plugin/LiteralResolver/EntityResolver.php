<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\LiteralResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Attribute\LiteralResolver;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralResolverBase;
use Drupal\literals\ResolvedLiteral;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A pointer to an entity, stored as "entity_type:id", resolved to its URL.
 */
#[LiteralResolver(
  id: 'entity',
  label: new TranslatableMarkup('Entity'),
  description: new TranslatableMarkup('An entity as type:id, e.g. node:12. Resolves to its URL.'),
)]
class EntityResolver extends LiteralResolverBase {

  /**
   * Constructs the resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * Loads the entity a stored value points at.
   *
   * @param string $value
   *   The stored "entity_type:id" string.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity, or NULL if the value is malformed or nothing is there.
   */
  protected function load(string $value): ?EntityInterface {
    [$type, $id] = array_pad(explode(':', trim($value), 2), 2, '');
    $manager = $this->entityTypeManager;
    if ($id === '' || !$manager->hasDefinition($type)) {
      return NULL;
    }
    return $manager->getStorage($type)->load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(Literal $literal): array {
    $value = (string) $literal->get('value')->value;
    return $this->load($value) ? [] : [(string) new TranslatableMarkup('No entity found for "@value". Use entity_type:id, e.g. node:12.', ['@value' => $value])];
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?string {
    $entity = $this->load((string) $literal->get('value')->value);
    if (!$entity) {
      return NULL;
    }
    $metadata->addCacheableDependency($entity);
    $access = $entity->access('view', $account, TRUE);
    $metadata->addCacheableDependency($access);
    if (!$access->isAllowed() || !$entity->hasLinkTemplate('canonical')) {
      return NULL;
    }
    return $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
  }

  /**
   * {@inheritdoc}
   */
  protected function label(Literal $literal): string {
    $entity = $this->load((string) $literal->get('value')->value);
    return $entity ? (string) $entity->label() : parent::label($literal);
  }

  /**
   * {@inheritdoc}
   */
  protected function kind(Literal $literal): string {
    return ResolvedLiteral::KIND_URL;
  }

}
