<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\LiteralResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Attribute\LiteralResolver;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralResolverBase;
use Drupal\literals\ResolvedLiteral;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One field's value on one entity, stored as "entity_type:id:field_name".
 *
 * The source module keeps owning the value (Site Settings, Config Pages, any
 * node field); the literal adds the gist and its own view rule on top. The
 * viewer needs the literal's view rule, view access to the entity and view
 * access to the field: the intersection, the stricter of the two access
 * models.
 */
#[LiteralResolver(
  id: 'field',
  label: new TranslatableMarkup('Field'),
  description: new TranslatableMarkup('One field on one entity as type:id:field, e.g. node:12:field_phone. Resolves to the field value.'),
)]
class FieldResolver extends LiteralResolverBase {

  /**
   * Field types whose value is formatted text, stripped to plain text.
   */
  protected const FORMATTED = ['text', 'text_long', 'text_with_summary'];

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
   * Loads the field a stored value points at.
   *
   * The entity type is before the first colon and the field name after the
   * last, so an entity ID may itself contain colons.
   *
   * @param string $value
   *   The stored "entity_type:id:field_name" string.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|null
   *   The field items, or NULL if the value is malformed or nothing is there.
   */
  protected function load(string $value): ?FieldItemListInterface {
    $value = trim($value);
    $first = strpos($value, ':');
    $last = strrpos($value, ':');
    if ($first === FALSE || $last === $first) {
      return NULL;
    }
    $type = substr($value, 0, $first);
    $id = substr($value, $first + 1, $last - $first - 1);
    $field = substr($value, $last + 1);
    if ($id === '' || $field === '' || !$this->entityTypeManager->hasDefinition($type)) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($type)->load($id);
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($field)) {
      return NULL;
    }
    return $entity->get($field);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(Literal $literal): array {
    $value = (string) $literal->get('value')->value;
    return $this->load($value) ? [] : [(string) new TranslatableMarkup('No field found for "@value". Use entity_type:id:field_name, e.g. node:12:field_phone.', ['@value' => $value])];
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?string {
    $items = $this->load((string) $literal->get('value')->value);
    if (!$items) {
      return NULL;
    }
    $entity = $items->getEntity();
    $metadata->addCacheableDependency($entity);
    $entity_access = $entity->access('view', $account, TRUE);
    $field_access = $items->access('view', $account, TRUE);
    $metadata->addCacheableDependency($entity_access);
    $metadata->addCacheableDependency($field_access);
    if (!$entity_access->isAllowed() || !$field_access->isAllowed() || $items->isEmpty()) {
      return NULL;
    }
    return $this->value($items);
  }

  /**
   * Reads the field as one plain string.
   *
   * A link field gives its first item's absolute URL. Anything else gives
   * each item's main property, joined with ", ", with formatted text
   * stripped of markup.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The non-empty field items.
   *
   * @return string
   *   The value.
   */
  protected function value(FieldItemListInterface $items): string {
    $definition = $items->getFieldDefinition();
    if ($definition->getType() === 'link') {
      /** @var \Drupal\link\LinkItemInterface $link */
      $link = $items->first();
      return $link->getUrl()->setAbsolute()->toString();
    }
    $property = $definition->getFieldStorageDefinition()->getMainPropertyName();
    $values = [];
    foreach ($items as $item) {
      $value = (string) $item->get($property)->getValue();
      $values[] = in_array($definition->getType(), self::FORMATTED, TRUE) ? trim(strip_tags($value)) : $value;
    }
    return implode(', ', $values);
  }

  /**
   * {@inheritdoc}
   */
  protected function kind(Literal $literal): string {
    $type = $this->load((string) $literal->get('value')->value)?->getFieldDefinition()->getType();
    return match ($type) {
      'telephone' => ResolvedLiteral::KIND_PHONE,
      'email' => ResolvedLiteral::KIND_EMAIL,
      'link' => ResolvedLiteral::KIND_URL,
      default => ResolvedLiteral::KIND_TEXT,
    };
  }

}
