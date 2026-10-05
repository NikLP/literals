<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Runs a field's text through the literals write guardrails, when available.
 *
 * Attached to the gist and value fields. Like aim's AimGuardrails, it runs at
 * entity validation, so a form shows a field error; a programmatic writer
 * calls validate() before save(). A no-op unless a guardrail service
 * (literals_finder) is installed.
 */
#[Constraint(
  id: 'LiteralGuardrails',
  label: new TranslatableMarkup('Literal write guardrails', [], ['context' => 'Validation']),
)]
final class LiteralGuardrailsConstraint extends SymfonyConstraint {

  /**
   * Skip guardrails that would send the text to a model.
   */
  public bool $deterministicOnly = FALSE;

  /**
   * Constructs the constraint.
   *
   * @param array|null $options
   *   Options from the field definition: deterministicOnly.
   * @param array|null $groups
   *   Validation groups.
   * @param mixed $payload
   *   Arbitrary payload.
   */
  public function __construct(?array $options = NULL, ?array $groups = NULL, mixed $payload = NULL) {
    parent::__construct(NULL, $groups, $payload);
    $this->deterministicOnly = (bool) ($options['deterministicOnly'] ?? FALSE);
  }

}
