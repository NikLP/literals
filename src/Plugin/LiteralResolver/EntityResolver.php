<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\LiteralResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Attribute\LiteralResolver;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralResolverBase;

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
    $manager = \Drupal::entityTypeManager();
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

}
