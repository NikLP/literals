<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires a literal's key to be unique.
 */
#[Constraint(
  id: 'LiteralKeyUnique',
  label: new TranslatableMarkup('Literal key unique', [], ['context' => 'Validation']),
)]
final class LiteralKeyUniqueConstraint extends SymfonyConstraint {

  /**
   * The message shown when the key is already used.
   */
  public string $message = 'The key %key is already used.';

  /**
   * The message shown when the key collides with an entity token name.
   */
  public string $reservedMessage = 'The key %key is reserved (it is the name of a literal field or entity token). Choose another.';

}
