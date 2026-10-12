<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for actions that stage a restricted-flag change for confirmation.
 *
 * Changing the flag either way alters who can read the value, so the change
 * waits for an explicit confirm (RestrictionConfirmForm).
 */
abstract class RestrictionActionBase extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs an action plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected PrivateTempStoreFactory $tempStoreFactory, protected AccountProxyInterface $currentUser) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('tempstore.private'), $container->get('current_user'));
  }

  /**
   * Returns the restricted value the confirmed change sets.
   *
   * @return bool
   *   TRUE to restrict, FALSE to unrestrict.
   */
  abstract protected function targetRestricted(): bool;

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities) {
    $ids = [];
    foreach ($entities as $entity) {
      $ids[] = $entity->id();
    }
    $this->tempStoreFactory->get('literals_restriction')->set((string) $this->currentUser->id(), [
      'restricted' => $this->targetRestricted(),
      'ids' => $ids,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function execute($object = NULL) {
    $this->executeMultiple([$object]);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $object->access('update', $account, TRUE);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
