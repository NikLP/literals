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
   * Names the model choose() would use now.
   *
   * The finder keys its outcome cache on it, so a model swap is not served
   * the old model's answers.
   *
   * @return string|null
   *   An ID naming the provider and model, or NULL when none is configured.
   */
  public function modelId(): ?string;

  /**
   * Chooses among candidates by key and gist only.
   *
   * Values are never sent to the model.
   *
   * @param string $question
   *   The plain-language question.
   * @param \Drupal\literals\Entity\Literal[] $candidates
   *   Candidates the asker is allowed to see, already filtered.
   * @param string|null $context
   *   Who is being asked and why, put before the instructions. NULL uses the
   *   site's configured context.
   * @param array|null $thresholds
   *   Overrides of match_threshold and choice_margin for this call, or NULL
   *   for the site settings.
   *
   * @return \Drupal\literals_finder\Finder\LiteralFindResult
   *   Match, ambiguous (the near-tied options) or none.
   *
   * @throws \RuntimeException
   *   When no decision model is configured or the call fails.
   */
  public function choose(string $question, array $candidates, ?string $context = NULL, ?array $thresholds = NULL): LiteralFindResult;

}
