<?php

declare(strict_types=1);

namespace Drupal\literals_tool\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for the literals:lookup tool.
 */
class LiteralsToolSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'literals_tool_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['literals_tool.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['question_context'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Question context'),
      '#description' => $this->t('Who is asking when a question comes in through the tool (an MCP client, an agent), put to the finder in place of its site context. State it positively, e.g. "Staff of the Harbourside Community Library are asking the library\'s own records"; a negation ("not the library") over-steers. Leave empty to use the finder\'s site context. Set by an administrator only: callers cannot supply it.'),
      '#default_value' => $this->config('literals_tool.settings')->get('question_context'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('literals_tool.settings')
      ->set('question_context', trim((string) $form_state->getValue('question_context')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
