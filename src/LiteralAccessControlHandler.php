<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for the literal entity type.
 *
 * Every operation is gated by a per-pool permission, with the flat
 * administer permission as the bypass. An unpublished literal (a draft
 * revision awaiting review) is visible only to someone who can edit it, so
 * a draft value is never served.
 */
class LiteralAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $pool = $entity->bundle();
    $admin = AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission());

    switch ($operation) {
      case 'view':
        $published = $entity instanceof EntityPublishedInterface ? $entity->isPublished() : TRUE;
        $view = AccessResult::allowedIfHasPermission($account, 'view ' . $pool . ' literals');
        if (!$published) {
          $view = AccessResult::allowedIfHasPermission($account, 'edit ' . $pool . ' literals');
        }
        return $view->orIf($admin)->addCacheableDependency($entity);

      case 'update':
        return AccessResult::allowedIfHasPermission($account, 'edit ' . $pool . ' literals')->orIf($admin);

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'delete ' . $pool . ' literals')->orIf($admin);
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'create ' . $entity_bundle . ' literals')
      ->orIf(AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission()));
  }

}
