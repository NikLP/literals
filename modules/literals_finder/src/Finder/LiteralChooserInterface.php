<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Finder;

/**
 * Picks one literal from a menu, or none, with a model.
 */
interface LiteralChooserInterface {

  /**
   * Whether a decision model is configured to choose with.
   */
  public function isAvailable(): bool;

  /**
   * Chooses among candidates by key and gist only.
   *
   * Values are never sent to the model.
   *
   * @param string $question
   *   The plain-language question.
   * @param \Drupal\literals\Entity\Literal[] $candidates
   *   Candidates the asker is allowed to see, already filtered.
   *
   * @return \Drupal\literals_finder\Finder\LiteralFindResult
   *   Match, ambiguous (the near-tied options) or none.
   *
   * @throws \RuntimeException
   *   When no decision model is configured or the call fails.
   */
  public function choose(string $question, array $candidates): LiteralFindResult;

}
