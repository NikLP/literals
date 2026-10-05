<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for the literals finder: the embedding gate and the chooser.
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

    $form['gate'] = [
      '#type' => 'details',
      '#title' => $this->t('Embedding gate'),
      '#open' => TRUE,
      '#description' => $this->t('Stored gist embeddings narrow the candidates before the chooser model runs. The thresholds are untuned placeholders.'),
    ];
    $form['gate']['gate_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use the gate before the chooser'),
      '#default_value' => $config->get('gate_enabled'),
    ];
    $form['gate']['gate_min_similarity'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum similarity'),
      '#description' => $this->t('Cosine similarity below which nothing is close.'),
      '#step' => 0.01,
      '#min' => 0,
      '#max' => 1,
      '#default_value' => $config->get('gate_min_similarity'),
    ];
    $form['gate']['gate_margin'] = [
      '#type' => 'number',
      '#title' => $this->t('Decisive margin'),
      '#description' => $this->t('Lead over the runner-up that decides without the chooser.'),
      '#step' => 0.01,
      '#min' => 0,
      '#max' => 1,
      '#default_value' => $config->get('gate_margin'),
    ];
    $form['gate']['gate_top'] = [
      '#type' => 'number',
      '#title' => $this->t('Candidates passed to the chooser'),
      '#min' => 1,
      '#default_value' => $config->get('gate_top'),
    ];

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
    foreach (['gate_enabled', 'log_audit'] as $key) {
      $config->set($key, (bool) $form_state->getValue($key));
    }
    foreach (['gate_min_similarity', 'gate_margin', 'match_threshold', 'choice_margin'] as $key) {
      $config->set($key, (float) $form_state->getValue($key));
    }
    foreach (['gate_top', 'miss_ttl'] as $key) {
      $config->set($key, (int) $form_state->getValue($key));
    }
    $config->set('chooser_instructions', (string) $form_state->getValue('chooser_instructions'));
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
