<?php

declare(strict_types=1);

namespace Drupal\literals\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Form\LiteralPoolForm;

/**
 * Defines the literal pool config entity.
 *
 * A pool is the bundle of literal and the permission boundary: view,
 * create, edit and delete are granted per pool, so a staff-only pool is
 * invisible to everyone without its permissions (ADR-0040 Addendum 3).
 */
#[ConfigEntityType(
  id: 'literal_pool',
  label: new TranslatableMarkup('Literal pool'),
  label_collection: new TranslatableMarkup('Literal pools'),
  label_singular: new TranslatableMarkup('literal pool'),
  label_plural: new TranslatableMarkup('literal pools'),
  config_prefix: 'literal_pool',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => EntityListBuilder::class,
    'form' => [
      'add' => LiteralPoolForm::class,
      'edit' => LiteralPoolForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
    ],
  ],
  links: [
    'add-form' => '/admin/structure/literal-pools/add',
    'edit-form' => '/admin/structure/literal-pools/{literal_pool}/edit',
    'collection' => '/admin/structure/literal-pools',
  ],
  admin_permission: 'administer literals',
  bundle_of: 'literal',
  config_export: [
    'id',
    'label',
    'description',
    'value_pattern',
  ],
)]
class LiteralPool extends ConfigEntityBundleBase {

  /**
   * The machine name, e.g. "public" or "staff".
   */
  protected string $id;

  /**
   * Human-readable label.
   */
  protected string $label;

  /**
   * What this pool is for.
   */
  protected string $description = '';

  /**
   * How literal values in this pool are validated.
   *
   * One of string, int, phone, email or url. Applied as a constraint in the
   * entity layer, so agents and tools are checked as well as forms.
   */
  protected string $value_pattern = 'string';

  /**
   * Returns the value pattern for literals in this pool.
   */
  public function getValuePattern(): string {
    return $this->value_pattern;
  }

  /**
   * Returns the permission that grants viewing literals in this pool.
   */
  public function getViewPermission(): string {
    return 'view ' . $this->id() . ' literals';
  }

  /**
   * Returns the permission that grants creating literals in this pool.
   */
  public function getCreatePermission(): string {
    return 'create ' . $this->id() . ' literals';
  }

  /**
   * Returns the permission that grants editing literals in this pool.
   */
  public function getEditPermission(): string {
    return 'edit ' . $this->id() . ' literals';
  }

  /**
   * Returns the permission that grants deleting literals in this pool.
   */
  public function getDeletePermission(): string {
    return 'delete ' . $this->id() . ' literals';
  }

}
