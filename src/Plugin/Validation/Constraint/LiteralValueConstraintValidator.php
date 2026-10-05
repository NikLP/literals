<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\literals\Entity\LiteralPool;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the value field against the pool's value_pattern setting.
 */
final class LiteralValueConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Validation\ConstraintManager $constraintManager
   *   The validation constraint plugin manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConstraintManager $constraintManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('validation.constraint'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $items, Constraint $constraint): void {
    $value = $items->value ?? NULL;
    if ($value === NULL || $value === '') {
      return;
    }
    $pool = $this->entityTypeManager->getStorage('literal_pool')->load($items->getEntity()->bundle());
    if (!$pool instanceof LiteralPool) {
      return;
    }
    $pattern = $pool->getValuePattern();
    // Core's own constraint plugins, validated against the value's typed
    // data, so the pattern behaves like any other field constraint.
    $rule = match ($pattern) {
      'int' => $this->constraintManager->create('Regex', ['pattern' => '/^-?\d+$/']),
      'phone' => $this->constraintManager->create('Regex', ['pattern' => '/^\+?[0-9 ()\-.]{6,25}$/']),
      'email' => $this->constraintManager->create('Email', []),
      'url' => $this->constraintManager->create('Regex', ['pattern' => '/^https?:\/\/[^\s\/$.?#][^\s]*$/i']),
      default => NULL,
    };
    if ($rule === NULL) {
      return;
    }
    if (count($this->context->getValidator()->validate($items->first()->get('value'), $rule)) > 0) {
      $this->context->addViolation($constraint->message, ['@pattern' => $pattern]);
    }
  }

}
