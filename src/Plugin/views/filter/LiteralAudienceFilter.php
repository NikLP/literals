<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\views\filter;

use Drupal\literals\LiteralAudience;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;

/**
 * Filters literals by who they are visible to.
 */
#[ViewsFilter('literal_audience')]
class LiteralAudienceFilter extends InOperator {

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): array {
    $this->valueOptions ??= LiteralAudience::options();
    return $this->valueOptions;
  }

}
