<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires a literal's key (its ID) to be unreserved and unchanged.
 *
 * Uniqueness is core's UniqueField constraint. Facts and text embed
 * [literal:key], so a changed key would silently turn every such token into
 * a redaction.
 */
#[Constraint(
  id: 'LiteralKey',
  label: new TranslatableMarkup('Literal key', [], ['context' => 'Validation']),
)]
final class LiteralKeyConstraint extends SymfonyConstraint {

  /**
   * The message shown when the key collides with an entity token name.
   */
  public string $reservedMessage = 'The key %key is reserved (it is the name of a literal field or entity token). Choose another.';

  /**
   * The message shown when an existing literal's key is changed.
   */
  public string $immutableMessage = 'The key of an existing literal cannot change. Create a new literal instead.';

}
