<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Checks that no other literal in the same pool has this key.
 */
final class LiteralKeyUniqueConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $items, Constraint $constraint): void {
    $key = $items->value ?? NULL;
    if ($key === NULL || $key === '') {
      return;
    }
    $entity = $items->getEntity();
    $query = $this->entityTypeManager->getStorage('literal')->getQuery()
      ->accessCheck(FALSE)
      ->condition('pool', $entity->bundle())
      ->condition('key', $key);
    if (!$entity->isNew()) {
      $query->condition('id', $entity->id(), '<>');
    }
    if ($query->range(0, 1)->execute()) {
      $this->context->addViolation($constraint->message, ['%key' => $key]);
    }
  }

}
