<?php

declare(strict_types=1);

namespace Drupal\literals\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\literals\LiteralResolverManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add and edit form for a literal type.
 */
class LiteralTypeForm extends BundleEntityFormBase {

  /**
   * Constructs the form.
   *
   * @param \Drupal\literals\LiteralResolverManager $resolverManager
   *   The literal resolver plugin manager.
   */
  public function __construct(protected LiteralResolverManager $resolverManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('plugin.manager.literal_resolver'));
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\literals\Entity\LiteralType $type */
    $type = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $type->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $type->id(),
      '#machine_name' => [
        'exists' => '\Drupal\literals\Entity\LiteralType::load',
        'source' => ['label'],
      ],
      '#disabled' => !$type->isNew(),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $type->get('description'),
    ];
    $form['resolver'] = [
      '#type' => 'select',
      '#title' => $this->t('Resolver'),
      '#description' => $this->t('What the value of a literal of this type is. Fixed once literals exist.'),
      '#options' => $this->resolverManager->getOptions(),
      '#default_value' => $type->getResolver(),
      '#required' => TRUE,
      '#disabled' => !$type->isNew(),
    ];
    $form['validate_as'] = [
      '#type' => 'select',
      '#title' => $this->t('Validate as'),
      '#description' => $this->t('How text values are checked when saved.'),
      '#options' => [
        'string' => $this->t('Any text'),
        'int' => $this->t('Whole number'),
        'phone' => $this->t('Phone number'),
        'email' => $this->t('Email address'),
      ],
      '#default_value' => $type->getValidateAs(),
      '#states' => ['visible' => [':input[name="resolver"]' => ['value' => 'text']]],
    ];

    return $this->protectBundleIdElement($form);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the %label literal type.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
