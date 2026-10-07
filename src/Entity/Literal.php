<?php

declare(strict_types=1);

namespace Drupal\literals\Entity;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Form\LiteralForm;
use Drupal\literals\LiteralAccessControlHandler;
use Drupal\literals\LiteralAudience;
use Drupal\literals\LiteralResolverInterface;
use Drupal\literals\LiteralViewsData;
use Drupal\literals\ResolvedLiteral;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the literal content entity.
 *
 * An exact value (the payload) with a plain-language gist (the address that
 * is matched). A literal is content, not config: types are config and go
 * through config sync, the literals themselves are editable on production.
 * Revisionable, with a published status that Content Moderation can drive,
 * so a changed gist or value can sit as a draft until a person promotes it
 * (ADR-0040 pieces 7 and 12).
 */
#[ContentEntityType(
  id: 'literal',
  label: new TranslatableMarkup('Literal'),
  label_collection: new TranslatableMarkup('Literals'),
  label_singular: new TranslatableMarkup('literal'),
  label_plural: new TranslatableMarkup('literals'),
  handlers: [
    'list_builder' => EntityListBuilder::class,
    'access' => LiteralAccessControlHandler::class,
    'views_data' => LiteralViewsData::class,
    'form' => [
      'default' => LiteralForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
    'bundle' => 'type',
    'label' => 'name',
    'published' => 'status',
    'owner' => 'uid',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log',
  ],
  bundle_entity_type: 'literal_type',
  links: [
    'add-page' => '/admin/content/literals/add',
    'add-form' => '/admin/content/literals/add/{literal_type}',
    'canonical' => '/admin/content/literals/{literal}',
    'edit-form' => '/admin/content/literals/{literal}/edit',
    'delete-form' => '/admin/content/literals/{literal}/delete',
    'collection' => '/admin/content/literals',
  ],
  admin_permission: 'administer literals',
  show_revision_ui: TRUE,
  base_table: 'literal',
  revision_table: 'literal_revision',
)]
class Literal extends EditorialContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {

  use EntityOwnerTrait;
  use EntityChangedTrait;

  /**
   * Resolves the value according to the literal's resolver.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account the value is for. Defaults to the current user.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $metadata
   *   Collects cache metadata of everything consulted.
   *
   * @return string|null
   *   The resolved value, or NULL when it cannot be resolved for the account.
   */
  public function resolve(?AccountInterface $account = NULL, ?CacheableMetadata $metadata = NULL): ?string {
    return $this->getResolverPlugin()->resolve($this, $account ?? \Drupal::currentUser(), $metadata ?? new CacheableMetadata());
  }

  /**
   * Resolves the literal to its value plus a label and a kind.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account the value is for. Defaults to the current user.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $metadata
   *   Collects cache metadata of everything consulted.
   *
   * @return \Drupal\literals\ResolvedLiteral|null
   *   The resolved literal, or NULL when it cannot be resolved for the account.
   */
  public function resolveItem(?AccountInterface $account = NULL, ?CacheableMetadata $metadata = NULL): ?ResolvedLiteral {
    return $this->getResolverPlugin()->resolveItem($this, $account ?? \Drupal::currentUser(), $metadata ?? new CacheableMetadata());
  }

  /**
   * Returns the gist, the plain-language description that is matched.
   *
   * Every reader goes through here, so the gist can later come from
   * somewhere other than the stored field (ADR-0047's tracked gists).
   *
   * @return string
   *   The trimmed gist, empty when there is none.
   */
  public function getGist(): string {
    return trim((string) $this->get('gist')->value);
  }

  /**
   * Returns the resolver plugin that reads this literal's value.
   *
   * @return \Drupal\literals\LiteralResolverInterface
   *   The resolver plugin of the literal's type.
   */
  public function getResolverPlugin(): LiteralResolverInterface {
    return \Drupal::service('plugin.manager.literal_resolver')->createInstance($this->getType()->getResolver());
  }

  /**
   * Returns the literal's type.
   */
  public function getType(): LiteralType {
    return LiteralType::load($this->bundle());
  }

  /**
   * Returns who can see the literal: "public", "authenticated" or a role ID.
   */
  public function getAudience(): string {
    return (string) $this->get('audience')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The human label, e.g. "Main phone number".'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0]);

    $fields['key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Key'))
      ->setDescription(t('Machine name, unique across literals. The exact-lookup handle and the option ID handed to the chooser.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->addConstraint('LiteralKeyUnique')
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 5]);

    $fields['audience'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Visible to'))
      ->setDescription(t('Who can see this literal, its description and its value.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDefaultValue(LiteralAudience::AUTHENTICATED)
      ->setSetting('allowed_values_function', 'literals_audience_options')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 2]);

    $fields['value'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Value'))
      ->setDescription(t('The exact thing returned, read according to its resolver. Never embedded, paraphrased or sent to the chooser.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->addConstraint('LiteralValue')
      // The value never leaves the site, so no model-backed guardrail sees it.
      ->addConstraint('LiteralGuardrails', ['deterministicOnly' => TRUE])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 10]);

    $fields['gist'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Gist'))
      ->setDescription(t('A short description of what the value is, in plain words. This is the only part that is matched.'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->addConstraint('LiteralGuardrails')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 15]);

    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']
      ->setLabel(t('Author'))
      ->setDescription(t('The user who created the literal.'))
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', ['region' => 'hidden']);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time the literal was created.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['region' => 'hidden']);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the literal was last saved.'))
      ->setRevisionable(TRUE);

    return $fields;
  }

}
