<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Plain search over published literals: name, key and gist.
 *
 * No scoring and no decision: every word typed must appear in the name, key
 * or gist, and the matches come back in name order for a person (or an
 * agent) to pick from. Only literals the searcher may view are returned.
 */
class LiteralSearch {

  /**
   * The most words used from a search text.
   */
  protected const MAX_WORDS = 6;

  /**
   * Constructs the search.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, the default searcher.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Finds literals whose name, key or gist contain every word typed.
   *
   * @param string $text
   *   What was typed. Words are split on whitespace, case is ignored.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The searcher. Defaults to the current user.
   * @param int $limit
   *   The most results.
   *
   * @return \Drupal\literals\Entity\Literal[]
   *   Matching literals in name order, keyed by ID.
   */
  public function search(string $text, ?AccountInterface $account = NULL, int $limit = 10): array {
    $account ??= $this->currentUser;
    $words = array_slice(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY), 0, self::MAX_WORDS);
    if (!$words || $limit < 1) {
      return [];
    }
    $flags = LiteralVisibility::visibleFlags($account);
    if ($flags === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('literal');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('status', 1);
    if ($flags !== NULL) {
      $query->condition('restricted', $flags, 'IN');
    }
    foreach ($words as $word) {
      $any = $query->orConditionGroup()
        ->condition('name', $word, 'CONTAINS')
        ->condition('id', $word, 'CONTAINS')
        ->condition('gist', $word, 'CONTAINS');
      $query->condition($any);
    }
    // Over-fetch a little: the per-entity view check can drop a few.
    $ids = $query->sort('name')->range(0, $limit * 2)->execute();
    $found = [];
    foreach ($storage->loadMultiple($ids) as $id => $literal) {
      if ($literal->access('view', $account)) {
        $found[$id] = $literal;
        if (count($found) >= $limit) {
          break;
        }
      }
    }
    return $found;
  }

}
