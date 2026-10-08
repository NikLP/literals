<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for the literals finder: the chooser and the outcome cache.
 */
class LiteralsFinderSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'literals_finder_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['literals_finder.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('literals_finder.settings');

    $form['chooser'] = [
      '#type' => 'details',
      '#title' => $this->t('Chooser'),
      '#open' => TRUE,
    ];
    $form['chooser']['match_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Match threshold'),
      '#description' => $this->t('Chooser probability needed for a match.'),
      '#step' => 0.01,
      '#min' => 0,
      '#max' => 1,
      '#default_value' => $config->get('match_threshold'),
    ];
    $form['chooser']['choice_margin'] = [
      '#type' => 'number',
      '#title' => $this->t('Choice margin'),
      '#description' => $this->t('Chooser lead that separates a match from a tie.'),
      '#step' => 0.01,
      '#min' => 0,
      '#max' => 1,
      '#default_value' => $config->get('choice_margin'),
    ];
    $form['chooser']['chooser_context'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Context'),
      '#description' => $this->t('Who is being asked, put before the instructions, e.g. "Questions are put to the website of the Harbourside Community Library, so \'you\' and \'your\' mean the library." Without it, a question like "what is your phone number" is read as not naming anything.'),
      '#default_value' => $config->get('chooser_context'),
    ];
    $form['chooser']['chooser_instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Instructions'),
      '#description' => $this->t('Put to the chooser model. Leave empty to use the built-in default.'),
      '#default_value' => $config->get('chooser_instructions'),
    ];

    $form['miss_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Miss cache lifetime'),
      '#description' => $this->t('Seconds a "none" outcome is cached.'),
      '#min' => 0,
      '#default_value' => $config->get('miss_ttl'),
    ];
    $form['max_question_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Longest question'),
      '#description' => $this->t('Characters of a question put to the chooser; a longer one is cut. Bounds the prompt size and cost of a lookup, whoever calls the finder.'),
      '#min' => 20,
      '#default_value' => $config->get('max_question_length'),
    ];
    $form['log_audit'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Write an audit line per lookup'),
      '#default_value' => $config->get('log_audit'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('literals_finder.settings');
    $config->set('log_audit', (bool) $form_state->getValue('log_audit'));
    foreach (['match_threshold', 'choice_margin'] as $key) {
      $config->set($key, (float) $form_state->getValue($key));
    }
    $config->set('miss_ttl', (int) $form_state->getValue('miss_ttl'));
    $config->set('max_question_length', (int) $form_state->getValue('max_question_length'));
    $config->set('chooser_instructions', (string) $form_state->getValue('chooser_instructions'));
    $config->set('chooser_context', (string) $form_state->getValue('chooser_context'));
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
