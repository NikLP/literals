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
 * Viewing is decided by LiteralVisibility ("view literals", plus "view
 * restricted literals" for a literal marked restricted); creating, editing
 * and deleting by "edit literals", with "administer literals" as the
 * bypass. An unpublished literal (a draft
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
        $visible = LiteralVisibility::canSee($account, $entity->isRestricted())
          ? AccessResult::allowed()
          : AccessResult::neutral();
        if (!$published) {
          $visible = $visible->andIf(AccessResult::allowedIfHasPermission($account, 'edit literals'));
        }
        // The answer depends on the permission set, not on the individual.
        return $visible->orIf($admin)
          ->addCacheableDependency($entity)
          ->addCacheContexts(['user.permissions']);

      case 'update':
      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'edit literals')->orIf($admin);
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'edit literals')
      ->orIf(AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission()));
  }

}
