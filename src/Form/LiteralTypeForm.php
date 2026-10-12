<?php

declare(strict_types=1);

namespace Drupal\literals\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\literals\Entity\LiteralType;
use Drupal\literals\LiteralResolverManager;
use Drupal\literals\Plugin\LiteralResolver\ContentResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edit form for a literal type.
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

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('A disabled type is not offered when adding a literal. A type that has literals cannot be disabled.'),
      '#default_value' => $type->status(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $type->get('description'),
      '#rows' => 2,
    ];

    $resolver_label = $this->resolverManager->getOptions()[$type->getResolver()] ?? $type->getResolver();
    $form['resolver_display'] = [
      '#type' => 'item',
      '#title' => $this->t('Value type'),
      '#markup' => $resolver_label,
      '#weight' => -100,
      '#wrapper_attributes' => ['class' => ['container-inline']],
    ];

    $form['target_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types'),
      '#description' => $this->t('The kinds of content a literal of this type can point at. Nothing is allowed until ticked, so new kinds of content stay out until you add them.'),
      '#options' => ContentResolver::bundleOptions($this->entityTypeManager, $this->bundleInfo),
      '#default_value' => $type->getTargetBundles(),
    ];

    $form['target_bundles']['#access'] = $type->getResolver() === 'content';

    return $form;
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (!$form_state->getValue('status') && LiteralType::load($this->entity->id())?->status()) {
      $count = $this->entityTypeManager->getStorage('literal')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $this->entity->id())
        ->count()
        ->execute();
      if ($count) {
        $form_state->setErrorByName('status', $this->t('Cannot disable %label: @count literals use it.', [
          '%label' => $this->entity->label(),
          '@count' => $count,
        ]));
      }
    }
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
