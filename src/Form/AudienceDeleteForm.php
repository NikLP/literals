<?php

declare(strict_types=1);

namespace Drupal\literals\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Confirm form to delete a literal audience, refused while literals use it.
 */
class AudienceDeleteForm extends EntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $count = (int) $this->entityTypeManager->getStorage('literal')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('audience', $this->entity->id())
      ->count()
      ->execute();
    if ($count > 0) {
      $form['#title'] = $this->getQuestion();
      $form['message'] = [
        '#markup' => $this->formatPlural($count, '%audience is used by 1 literal. Change its audience before deleting this one.', '%audience is used by @count literals. Change their audience before deleting this one.', ['%audience' => $this->entity->label()]),
      ];
      return $form;
    }
    return parent::buildForm($form, $form_state);
  }

}
