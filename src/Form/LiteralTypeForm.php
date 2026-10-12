<?php

declare(strict_types=1);

namespace Drupal\literals\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\literals\LiteralResolverManager;
use Drupal\literals\Plugin\LiteralResolver\ContentResolver;
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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   */
  public function __construct(protected LiteralResolverManager $resolverManager, EntityTypeManagerInterface $entityTypeManager, protected EntityTypeBundleInfoInterface $bundleInfo) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('plugin.manager.literal_resolver'), $container->get('entity_type.manager'), $container->get('entity_type.bundle.info'));
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
      '#rows' => 2,
    ];

    $resolver_label = $this->resolverManager->getOptions()[$type->getResolver()] ?? $type->getResolver();
    $form['resolver'] = [
      '#type' => 'select',
      '#title' => $this->t('Value type'),
      '#description' => $this->t('What a literal of this type holds, such as text, a page or a token. Cannot be changed after the type is created.'),
      '#options' => $this->resolverManager->getOptions(),
      '#default_value' => $type->getResolver(),
      '#required' => TRUE,
      '#access' => $type->isNew(),
    ];

    if (!$type->isNew()) {
      // Shown as plain text: a disabled select looks broken.
      $form['resolver_display'] = [
        '#type' => 'item',
        '#title' => $this->t('Value type'),
        '#markup' => $resolver_label,
        '#weight' => -100,
        '#wrapper_attributes' => ['class' => ['container-inline']],
      ];
    }

    $form['target_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types'),
      '#description' => $this->t('The kinds of content a literal of this type can point at. Nothing is allowed until ticked, so new kinds of content stay out until you add them.'),
      '#options' => ContentResolver::bundleOptions($this->entityTypeManager, $this->bundleInfo),
      '#default_value' => $type->getTargetBundles(),
      '#states' => ['visible' => [':input[name="resolver"]' => ['value' => 'content']]],
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

    if (!$type->isNew()) {
      // The value type is fixed, so there is no select for #states to watch.
      foreach (['target_bundles' => 'content', 'validate_as' => 'text'] as $key => $resolver) {
        unset($form[$key]['#states']);
        $form[$key]['#access'] = $type->getResolver() === $resolver;
      }
    }

    return $this->protectBundleIdElement($form);
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    // Checked boxes only, as a plain list.
    $form_state->setValue('target_bundles', array_values(array_filter((array) $form_state->getValue('target_bundles'))));
    return parent::buildEntity($form, $form_state);
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
