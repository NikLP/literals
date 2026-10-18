<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Says how to clear the literals when they block uninstalling the module.
 *
 * Core's content validator already blocks the uninstall, but its message
 * points at a content listing that does not cover literals. This says plainly
 * that the literals must be deleted first, without developer jargon.
 */
class LiteralsUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  /**
   * Constructs the validator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public function validate($module) {
    if ($module !== 'literals') {
      return [];
    }
    $count = $this->entityTypeManager->getStorage('literal')->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    if (!$count) {
      return [];
    }
    return [
      $this->formatPlural(
        $count,
        'The site has 1 literal. Delete it before uninstalling Literals.',
        'The site has @count literals. Delete them all before uninstalling Literals.',
      ),
    ];
  }

}
