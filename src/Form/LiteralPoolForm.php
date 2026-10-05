<?php

declare(strict_types=1);

namespace Drupal\literals\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Add and edit form for a literal pool.
 */
class LiteralPoolForm extends BundleEntityFormBase {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $pool = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $pool->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $pool->id(),
      '#machine_name' => ['exists' => '\Drupal\literals\Entity\LiteralPool::load'],
      '#disabled' => !$pool->isNew(),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $pool->get('description'),
    ];

    $form['value_pattern'] = [
      '#type' => 'select',
      '#title' => $this->t('Value pattern'),
      '#description' => $this->t('How values in this pool are validated when saved.'),
      '#options' => [
        'string' => $this->t('Any text'),
        'int' => $this->t('Whole number'),
        'phone' => $this->t('Phone number'),
        'email' => $this->t('Email address'),
        'url' => $this->t('URL'),
      ],
      '#default_value' => $pool->getValuePattern(),
    ];

    return $this->protectBundleIdElement($form);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the %label literal pool.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
