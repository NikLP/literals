<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires a literal's key to be unique within its pool.
 *
 * Unique per pool, not site-wide: tokens carry the pool, and a site-wide
 * rule would reveal that a key exists in a pool the editor cannot see.
 */
#[Constraint(
  id: 'LiteralKeyUnique',
  label: new TranslatableMarkup('Literal key unique per pool', [], ['context' => 'Validation']),
)]
final class LiteralKeyUniqueConstraint extends SymfonyConstraint {

  /**
   * The message shown when the key is already used in the pool.
   */
  public string $message = 'The key %key is already used in this pool.';

}
