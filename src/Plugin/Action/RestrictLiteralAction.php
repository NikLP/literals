<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stages literals to be marked restricted, pending confirmation.
 */
#[Action(
  id: 'literal_restrict',
  action_label: new TranslatableMarkup('Mark restricted'),
  type: 'literal',
  confirm_form_route_name: 'literals.restriction_confirm',
)]
class RestrictLiteralAction extends RestrictionActionBase {

  /**
   * {@inheritdoc}
   */
  protected function targetRestricted(): bool {
    return TRUE;
  }

}
