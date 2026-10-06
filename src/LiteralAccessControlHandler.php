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
 * Viewing is decided by the literal's audience (anonymous, signed-in users or
 * restricted); editing and deleting by flat permissions, with the flat
 * administer permission as the bypass. An unpublished literal (a draft
 * revision awaiting review) is visible only to someone who can edit it, so
 * a draft value is never served.
 */
class LiteralAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $admin = AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission());

    switch ($operation) {
      case 'view':
        $published = $entity instanceof EntityPublishedInterface ? $entity->isPublished() : TRUE;
        $audience = in_array($entity->getAudience(), LiteralAudience::visibleTo($account), TRUE)
          ? AccessResult::allowed()
          : AccessResult::neutral();
        if (!$published) {
          $audience = $audience->andIf(AccessResult::allowedIfHasPermission($account, 'edit literals'));
        }
        // The answer depends on being signed in and on the permission set (the
        // restricted audience and the admin bypass), not on the individual.
        return $audience->orIf($admin)
          ->addCacheableDependency($entity)
          ->addCacheContexts(['user.roles:authenticated', 'user.permissions']);

      case 'update':
        return AccessResult::allowedIfHasPermission($account, 'edit literals')->orIf($admin);

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'delete literals')->orIf($admin);
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'create literals')
      ->orIf(AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission()));
  }

}
