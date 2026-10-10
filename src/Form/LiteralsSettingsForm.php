<?php

declare(strict_types=1);

namespace Drupal\literals\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for the base literals module.
 */
class LiteralsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'literals_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['literals.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['log_audit'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log audit lines'),
      '#description' => $this->t('Log literal writes and reads to the literals channel: IDs, keys and outcomes, never descriptions or values.'),
      '#config_target' => 'literals.settings:log_audit',
    ];
    return parent::buildForm($form, $form_state);
  }

}
