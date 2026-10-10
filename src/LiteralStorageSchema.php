<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Storage schema for literals: a unique index on the key.
 *
 * The LiteralKeyUnique constraint is a query, so two simultaneous saves can
 * both pass it; the index makes the database refuse the second. Only the
 * base table: revisions legitimately repeat a key.
 */
class LiteralStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping): array {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);
    if ($storage_definition->getName() === 'key' && $table_name === $this->storage->getBaseTable()) {
      $schema['unique keys'][$this->getEntityIndexName($this->entityType, 'key')] = ['key'];
    }
    return $schema;
  }

}
