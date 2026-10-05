<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\views\EntityViewsData;

/**
 * Views data for literals.
 *
 * Gives the audience a dropdown filter of its options.
 */
class LiteralViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();
    $data[$this->entityType->getBaseTable()]['audience']['filter']['id'] = 'literal_audience';
    return $data;
  }

}
