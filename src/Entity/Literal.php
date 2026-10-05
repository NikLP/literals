<?php

declare(strict_types=1);

namespace Drupal\literals\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\views\EntityViewsData;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\LiteralAccessControlHandler;

/**
 * Defines the literal content entity.
 *
 * An exact value (the payload) with a plain-language gist (the address that
 * is matched). A literal is content, not config: pools are config and go
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
    'views_data' => EntityViewsData::class,
    'form' => [
      'default' => ContentEntityForm::class,
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
    'bundle' => 'pool',
    'label' => 'name',
    'published' => 'status',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log',
  ],
  bundle_entity_type: 'literal_pool',
  field_ui_base_route: 'entity.literal_pool.edit_form',
  links: [
    'add-page' => '/admin/content/literals/add',
    'add-form' => '/admin/content/literals/add/{literal_pool}',
    'canonical' => '/admin/content/literals/{literal}',
    'edit-form' => '/admin/content/literals/{literal}/edit',
    'delete-form' => '/admin/content/literals/{literal}/delete',
    'collection' => '/admin/content/literals',
  ],
  admin_permission: 'administer literals',
  base_table: 'literal',
  revision_table: 'literal_revision',
)]
class Literal extends EditorialContentEntityBase {

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
      ->setDescription(t('Machine name, unique within the pool. The exact-lookup handle and the option ID handed to the chooser.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->addConstraint('LiteralKeyUnique')
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 5]);

    $fields['value'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Value'))
      ->setDescription(t('The exact thing returned. Never embedded, paraphrased or sent to the chooser.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->addConstraint('LiteralValue')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 10]);

    $fields['gist'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Gist'))
      ->setDescription(t('A short description of what the value is, in plain words. This is the only part that is matched.'))
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 15]);

    return $fields;
  }

}
