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
   * Candidates are filtered by the asker's view access before the chooser
   * sees any gist.
   *
   * @param string $question
   *   The plain-language question.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The asker. Defaults to the current user.
   * @param string|null $context
   *   Who is being asked and why (for example, which website or role the
   *   question is put on behalf of), given to the chooser model. It replaces
   *   the site's configured context for this lookup. NULL uses the default.
   *
   * @return \Drupal\literals_finder\Finder\LiteralFindResult
   *   Match, ambiguous or none. Never a guess.
   */
  public function find(string $question, ?AccountInterface $account = NULL, ?string $context = NULL): LiteralFindResult;

}
