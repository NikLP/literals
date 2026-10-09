<?php

declare(strict_types=1);

namespace Drupal\literals\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Form\AudienceDeleteForm;
use Drupal\literals\Form\AudienceForm;
use Drupal\literals\LiteralAudienceListBuilder;

/**
 * Defines the literal audience config entity: who may see a literal.
 *
 * Each audience carries a generated "view {id} literals" permission, so roles
 * are granted audiences on the normal permissions page. The shipped
 * audiences are anonymous, authenticated and restricted.
 */
#[ConfigEntityType(
  id: 'literal_audience',
  label: new TranslatableMarkup('Literal audience'),
  label_collection: new TranslatableMarkup('Literal audiences'),
  label_singular: new TranslatableMarkup('literal audience'),
  label_plural: new TranslatableMarkup('literal audiences'),
  config_prefix: 'literal_audience',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'weight' => 'weight',
  ],
  handlers: [
    'list_builder' => LiteralAudienceListBuilder::class,
    'form' => [
      'add' => AudienceForm::class,
      'edit' => AudienceForm::class,
      'delete' => AudienceDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'add-form' => '/admin/config/literals/audiences/add',
    'edit-form' => '/admin/config/literals/audiences/{literal_audience}/edit',
    'delete-form' => '/admin/config/literals/audiences/{literal_audience}/delete',
    'collection' => '/admin/config/literals/audiences',
  ],
  admin_permission: 'administer literals',
  config_export: [
    'id',
    'label',
    'description',
    'weight',
  ],
)]
class Audience extends ConfigEntityBase {

  /**
   * The machine name, e.g. "staff".
   */
  protected string $id;

  /**
   * Human-readable label.
   */
  protected string $label;

  /**
   * Who this audience is for.
   */
  protected string $description = '';

  /**
   * Sort weight in the audience select and the list.
   */
  protected int $weight = 0;

  /**
   * Returns the description.
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * Returns the name of the permission that grants this audience.
   */
  public function getPermissionName(): string {
    return 'view ' . $this->id() . ' literals';
  }

}
