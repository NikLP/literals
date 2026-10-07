<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates a literal's value against its resolver.
 *
 * Runs in the entity layer (not Form API #pattern), so agents, tools and
 * Drush get the same check as the edit form (ADR-0040 "Types and resolvers").
 */
#[Constraint(
  id: 'LiteralValue',
  label: new TranslatableMarkup('Literal value', [], ['context' => 'Validation']),
)]
final class LiteralValueConstraint extends SymfonyConstraint {

}
