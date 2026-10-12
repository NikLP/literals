<?php

declare(strict_types=1);

namespace Drupal\literals\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms a restricted-flag change on the staged literals.
 */
class RestrictionConfirmForm extends ConfirmFormBase {

  /**
   * The staged literal IDs.
   *
   * @var array
   */
  protected array $ids = [];

  /**
   * The restricted value to set.
   */
  protected bool $restricted = TRUE;

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   */
  public function __construct(protected AccountInterface $currentUser, protected EntityTypeManagerInterface $entityTypeManager, protected PrivateTempStoreFactory $tempStoreFactory) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('current_user'), $container->get('entity_type.manager'), $container->get('tempstore.private'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'literals_restriction_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    if ($this->restricted) {
      return $this->formatPlural(count($this->ids), 'Hide this literal from everyone without "View restricted literals"?', 'Hide these @count literals from everyone without "View restricted literals"?');
    }
    return $this->formatPlural(count($this->ids), 'Make this literal visible to everyone with "View literals"?', 'Make these @count literals visible to everyone with "View literals"?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    if ($this->restricted) {
      return $this->t('Their values, descriptions and gists will be limited to "View restricted literals" holders, and tokens and lookups will stop resolving for everyone else.');
    }
    return $this->t('Their values, descriptions and gists will no longer be limited to "View restricted literals" holders.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.literal.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->restricted ? $this->t('Mark restricted') : $this->t('Mark unrestricted');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $staged = $this->tempStoreFactory->get('literals_restriction')->get((string) $this->currentUser->id()) ?: [];
    $this->ids = $staged['ids'] ?? [];
    $this->restricted = (bool) ($staged['restricted'] ?? TRUE);
    if (!$this->ids) {
      return $this->redirect('entity.literal.collection');
    }
    $items = [];
    foreach ($this->entityTypeManager->getStorage('literal')->loadMultiple($this->ids) as $literal) {
      $items[] = $literal->label();
    }
    $form['items'] = ['#theme' => 'item_list', '#items' => $items];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $count = 0;
    foreach ($this->entityTypeManager->getStorage('literal')->loadMultiple($this->ids) as $literal) {
      if ($literal->isRestricted() !== $this->restricted && $literal->access('update')) {
        $literal->set('restricted', $this->restricted)->save();
        $count++;
      }
    }
    $this->tempStoreFactory->get('literals_restriction')->delete((string) $this->currentUser->id());
    if ($this->restricted) {
      $message = $this->formatPlural($count, 'Marked @count literal restricted.', 'Marked @count literals restricted.');
    }
    else {
      $message = $this->formatPlural($count, 'Marked @count literal unrestricted.', 'Marked @count literals unrestricted.');
    }
    $this->messenger()->addStatus($message);
    $form_state->setRedirect('entity.literal.collection');
  }

}
