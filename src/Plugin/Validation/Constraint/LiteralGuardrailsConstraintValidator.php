<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\literals\LiteralGuardrailsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates LiteralGuardrailsConstraint against a field's current text.
 */
final class LiteralGuardrailsConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   *
   * @param \Drupal\literals\LiteralGuardrailsInterface|null $guardrails
   *   The guardrail runner, if a module provides one.
   */
  public function __construct(
    protected ?LiteralGuardrailsInterface $guardrails,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->has('literals.guardrails') ? $container->get('literals.guardrails') : NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$this->guardrails || !$value instanceof FieldItemListInterface || $value->isEmpty()) {
      return;
    }
    $text = (string) $value->value;
    try {
      $checked = $this->guardrails->check($text, $constraint->deterministicOnly);
    }
    catch (\InvalidArgumentException $e) {
      $this->context->addViolation($e->getMessage());
      return;
    }
    if ($checked !== $text) {
      $value->setValue($checked);
    }
  }

}
