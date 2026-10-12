<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stages literals to be marked unrestricted, pending confirmation.
 */
#[Action(
  id: 'literal_unrestrict',
  action_label: new TranslatableMarkup('Mark unrestricted'),
  type: 'literal',
  confirm_form_route_name: 'literals.restriction_confirm',
)]
class UnrestrictLiteralAction extends RestrictionActionBase {

  /**
   * {@inheritdoc}
   */
  protected function targetRestricted(): bool {
    return FALSE;
  }

}
