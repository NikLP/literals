<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Finder;

/**
 * The outcome of a finder lookup: a match, an ambiguous tie, or nothing.
 *
 * Carries literals, never values: the caller resolves a value itself, for its
 * own account, through Literal::resolve().
 */
final class LiteralFindResult {

  public const MATCH = 'match';

  public const AMBIGUOUS = 'ambiguous';

  public const NONE = 'none';

  /**
   * Constructs a result.
   *
   * @param string $outcome
   *   One of the outcome constants.
   * @param \Drupal\literals\Entity\Literal[] $literals
   *   One literal for a match, the tied candidates for ambiguous, else none.
   * @param string $tier
   *   What decided it: "cache", "chooser" or "pool".
   * @param string $reason
   *   A short machine reason, for example "no_candidates" or "no_backend".
   */
  public function __construct(
    public readonly string $outcome,
    public readonly array $literals = [],
    public readonly string $tier = 'pool',
    public readonly string $reason = '',
  ) {}

}
