<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the value field through the literal's kind plugin.
 */
final class LiteralValueConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $items, Constraint $constraint): void {
    $value = $items->value ?? NULL;
    if ($value === NULL || $value === '') {
      return;
    }
    $literal = $items->getEntity();
    $plugin = $literal->getKindPlugin();
    foreach ($plugin->validate($literal) as $message) {
      $this->context->addViolation($message);
    }
  }

}
