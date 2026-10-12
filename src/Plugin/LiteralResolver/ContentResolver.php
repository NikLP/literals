<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\LiteralResolver;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Attribute\LiteralResolver;
use Drupal\literals\Entity\Literal;
use Drupal\user\EntityOwnerInterface;

/**
 * A pointer to a piece of editorial content, resolved to its URL.
 *
 * Stored as "entity_type:id" like the entity resolver, but limited to authored
 * content: editorial entity types that also have an owner (nodes, and any
 * custom content entity type that records its author). Terms, reusable blocks
 * and menu links have no owner, so they are left out without a deny list.
 */
#[LiteralResolver(
  id: 'content',
  label: new TranslatableMarkup('Content'),
  description: new TranslatableMarkup('A piece of content, picked by title. Resolves to its URL.'),
)]
class ContentResolver extends EntityResolver {

  /**
   * Returns the entity types a content literal can point at.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $manager
   *   The entity type manager.
   * @param string[]|null $allowed
   *   Strings like node:page to limit to (empty allows nothing), or NULL for
   *   every type.
   *
   * @return string[]
   *   Labels keyed by entity type ID.
   */
  public static function contentTypes(EntityTypeManagerInterface $manager, ?array $allowed = NULL): array {
    $types = [];
    foreach ($manager->getDefinitions() as $id => $definition) {
      if ($definition instanceof ContentEntityTypeInterface
        && $id !== 'literal'
        && ($allowed === NULL || static::allowedBundles($allowed, $id))
        && $definition->hasLinkTemplate('canonical')
        && is_subclass_of($definition->getClass(), EditorialContentEntityBase::class)
        && is_subclass_of($definition->getClass(), EntityOwnerInterface::class)) {
        $types[$id] = (string) $definition->getLabel();
      }
    }
    return $types;
  }

  /**
   * Returns the allowed bundles of one entity type.
   *
   * @param string[] $allowed
   *   Strings like node:page.
   * @param string $type
   *   The entity type ID.
   *
   * @return string[]
   *   Bundle IDs of that type; empty if none are allowed.
   */
  public static function allowedBundles(array $allowed, string $type): array {
    $bundles = [];
    foreach ($allowed as $item) {
      [$item_type, $bundle] = array_pad(explode(':', $item, 2), 2, '');
      if ($item_type === $type && $bundle !== '') {
        $bundles[] = $bundle;
      }
    }
    return $bundles;
  }

  /**
   * Returns every bundle a content literal can point at, for a checkbox list.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundle_info
   *   The bundle info service.
   *
   * @return string[]
   *   Labels such as "Content: Page" keyed by "entity_type:bundle".
   */
  public static function bundleOptions(EntityTypeManagerInterface $manager, EntityTypeBundleInfoInterface $bundle_info): array {
    $options = [];
    foreach (static::contentTypes($manager) as $type => $type_label) {
      foreach ($bundle_info->getBundleInfo($type) as $bundle => $info) {
        $options[$type . ':' . $bundle] = $type_label . ': ' . $info['label'];
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(Literal $literal): array {
    $value = trim((string) $literal->get('value')->value);
    $allowed = $literal->getType()->getTargetBundles();
    $type = explode(':', $value, 2)[0];
    if (!isset(static::contentTypes($this->entityTypeManager, $allowed)[$type])) {
      return [(string) new TranslatableMarkup('"@value" is not allowed here. Pick a page from the suggestions.', ['@value' => $value])];
    }
    $errors = parent::validate($literal);
    if (!$errors) {
      $entity = $this->load($value);
      if ($entity && !in_array($entity->bundle(), static::allowedBundles($allowed, $type), TRUE)) {
        return [(string) new TranslatableMarkup('"@value" is not allowed here. Pick a page from the suggestions.', ['@value' => $value])];
      }
    }
    return $errors;
  }

}
