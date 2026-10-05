<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\literals\Entity\Literal;
use Drupal\literals_finder\Finder\LiteralEmbedder;
use Psr\Log\LoggerInterface;

/**
 * Hook implementations for the literals finder.
 */
class LiteralsFinderHooks {

  use StringTranslationTrait;

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\literals_finder\Finder\LiteralEmbedder $embedder
   *   The gist embedder.
   * @param \Psr\Log\LoggerInterface $logger
   *   The literals logger channel.
   */
  public function __construct(
    protected LiteralEmbedder $embedder,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Implements hook_entity_base_field_info().
   *
   * Derived data for the gate. Not revisionable, so it lives on the base row
   * and describes the published gist.
   *
   * @return array
   *   Base field definitions for literals.
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() !== 'literal') {
      return [];
    }
    $fields['gist_vector'] = BaseFieldDefinition::create('string_long')
      ->setLabel($this->t('Gist vector'))
      ->setDescription($this->t('JSON embedding of the gist, written at save. Derived, never edited.'))
      ->setProvider('literals_finder');
    $fields['gist_vector_model'] = BaseFieldDefinition::create('string')
      ->setLabel($this->t('Gist vector model'))
      ->setDescription($this->t('The embedding model that made the vector, as provider__model.'))
      ->setSetting('max_length', 255)
      ->setProvider('literals_finder');
    return $fields;
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for literals.
   *
   * Stores the gist's embedding on the row when the gate is on. A failed
   * embedding never blocks the save: the finder treats a literal without a
   * current vector as always-a-candidate and queues it.
   */
  #[Hook('literal_presave')]
  public function literalPresave(Literal $literal): void {
    if (!$this->embedder->isAvailable()) {
      return;
    }
    $changed = $literal->isNew()
      || (string) $literal->get('gist')->value !== (string) $literal->original?->get('gist')->value
      || !$this->embedder->isCurrent($literal);
    if (!$changed) {
      return;
    }
    try {
      $this->embedder->embedLiteral($literal);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Gist embedding failed for literal @id: @class.', [
        '@id' => $literal->id() ?? 'new',
        '@class' => get_class($e),
      ]);
    }
  }

}
