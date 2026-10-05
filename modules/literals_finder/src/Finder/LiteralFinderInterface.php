<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Finder;

use Drupal\Core\Session\AccountInterface;

/**
 * Finds the literal a plain-language question is asking for.
 */
interface LiteralFinderInterface {

  /**
   * Looks a literal up by question.
   *
   * Candidates are filtered by the asker's view access before the gate or
   * the chooser sees any gist.
   *
   * @param string $question
   *   The plain-language question.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The asker. Defaults to the current user.
   *
   * @return \Drupal\literals_finder\Finder\LiteralFindResult
   *   Match, ambiguous or none. Never a guess.
   */
  public function find(string $question, ?AccountInterface $account = NULL): LiteralFindResult;

}
