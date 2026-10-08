<?php

declare(strict_types=1);

namespace Drupal\literals\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Confirm form to delete a literal type, refused while literals use it.
 */
class LiteralTypeDeleteForm extends EntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $count = (int) $this->entityTypeManager->getStorage('literal')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $this->entity->id())
      ->count()
      ->execute();
    if ($count > 0) {
      $form['#title'] = $this->getQuestion();
      $form['message'] = [
        '#markup' => $this->formatPlural($count, '%type is used by 1 literal. Delete or re-type it before deleting the type.', '%type is used by @count literals. Delete or re-type them before deleting the type.', ['%type' => $this->entity->label()]),
      ];
      return $form;
    }
    return parent::buildForm($form, $form_state);
  }

}
