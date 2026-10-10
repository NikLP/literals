<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Checks the key: not reserved, unchanged on an existing literal.
 */
final class LiteralKeyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

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
    // [literal:key] shares a token namespace with the entity's own tokens
    // (the Token module adds [literal:url], [literal:name] and so on).
    $reserved = array_merge(array_keys($entity->getFieldDefinitions()), ['url', 'original', 'language', 'edit-url']);
    if (in_array($key, $reserved, TRUE)) {
      $this->context->addViolation($constraint->reservedMessage, ['%key' => $key]);
      return;
    }
    // A stored literal whose ID was changed in memory no longer matches a row.
    if (!$entity->isNew() && !$this->entityTypeManager->getStorage('literal')->loadUnchanged($key)) {
      $this->context->addViolation($constraint->immutableMessage);
    }
  }

}
