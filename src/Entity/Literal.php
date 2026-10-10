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
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Form\LiteralForm;
use Drupal\literals\LiteralAccessControlHandler;
use Drupal\literals\LiteralResolverInterface;
use Drupal\literals\ResolvedLiteral;
use Drupal\literals\Routing\LiteralHtmlRouteProvider;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the literal content entity.
 *
 * An exact value (the payload) with a plain-language gist (the address that
 * is matched). A literal is content, not config: types are config and go
 * through config sync, the literals themselves are editable on production.
 * Revisionable, with a published status that Content Moderation can drive,
 * so a changed gist or value can sit as a draft until a person promotes it
 * (ADR-0040 "Deferred and pinned").
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
    'views_data' => EntityViewsData::class,
    'form' => [
      'default' => LiteralForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => LiteralHtmlRouteProvider::class,
      'revision' => RevisionHtmlRouteProvider::class,
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
    'version-history' => '/admin/content/literals/{literal}/revisions',
    'revision-revert-form' => '/admin/content/literals/{literal}/revision/{literal_revision}/revert',
    'revision-delete-form' => '/admin/content/literals/{literal}/revision/{literal_revision}/delete',
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
    return \Drupal::service('plugin.manager.literal_resolver')->getResolver($this->getType()->getResolver());
  }

  /**
   * Returns the literal's type.
   */
  public function getType(): LiteralType {
    return LiteralType::load($this->bundle());
  }

  /**
   * Whether only "view restricted literals" holders may see the literal.
   */
  public function isRestricted(): bool {
    return (bool) $this->get('restricted')->value;
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

    // The ID is the key, a machine name, as core's Workspace entity does:
    // [literal:main_phone] reads the literal whose ID is main_phone. Set on
    // the form by a machine name element (LiteralForm), fixed once created.
    $fields['id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Key'))
      ->setDescription(t('Machine name and ID, unique across literals. The exact-lookup handle and the option ID handed to the chooser.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField')
      ->addConstraint('LiteralKey')
      ->addPropertyConstraints('value', ['Regex' => ['pattern' => '/^[a-z0-9_]+$/']]);

    $fields['restricted'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Restricted'))
      ->setDescription(t('Only accounts with "View restricted literals" can see this literal, its description and its value.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 2, 'settings' => ['display_label' => TRUE]]);

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
      ->setDescription(t('A short description of what the value is, in plain words. This is the only part that is matched. It matters mainly when the Literals finder is enabled; without the finder only plain word search reads it, so a clear name is enough.'))
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
