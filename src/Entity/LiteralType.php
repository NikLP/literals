<?php

declare(strict_types=1);

namespace Drupal\literals\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Form\LiteralTypeForm;

/**
 * Defines the literal type config entity, the bundle of literal.
 *
 * A type says what sort of value its literals hold (text, token, entity,
 * URL: a LiteralResolver plugin) and carries that resolver's settings, e.g. a Text
 * type validated as a phone number. Fieldable, so a type can carry extra
 * fields beside the shared name, key, value and gist.
 */
#[ConfigEntityType(
  id: 'literal_type',
  label: new TranslatableMarkup('Literal type'),
  label_collection: new TranslatableMarkup('Literal types'),
  label_singular: new TranslatableMarkup('literal type'),
  label_plural: new TranslatableMarkup('literal types'),
  config_prefix: 'literal_type',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => EntityListBuilder::class,
    'form' => [
      'add' => LiteralTypeForm::class,
      'edit' => LiteralTypeForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'add-form' => '/admin/structure/literal-types/add',
    'edit-form' => '/admin/structure/literal-types/{literal_type}/edit',
    'collection' => '/admin/structure/literal-types',
  ],
  admin_permission: 'administer literals',
  bundle_of: 'literal',
  config_export: [
    'id',
    'label',
    'description',
    'resolver',
    'validate_as',
  ],
)]
class LiteralType extends ConfigEntityBundleBase {

  /**
   * The machine name, e.g. "phone".
   */
  protected string $id;

  /**
   * Human-readable label.
   */
  protected string $label;

  /**
   * What this type is for.
   */
  protected string $description = '';

  /**
   * The LiteralResolver plugin ID that reads and checks this type's values.
   */
  protected string $resolver = 'text';

  /**
   * For the text resolver: how values are validated on save.
   *
   * One of string, int, phone, email or url.
   */
  protected string $validate_as = 'string';

  /**
   * Returns the resolver plugin ID.
   */
  public function getResolver(): string {
    return $this->resolver;
  }

  /**
   * Returns how text values are validated.
   */
  public function getValidateAs(): string {
    return $this->validate_as;
  }

}
