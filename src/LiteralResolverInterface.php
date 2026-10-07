<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\literals\Entity\Literal;

/**
 * A resolver of literal value: plain text, a token, an entity, a URL.
 *
 * The stored value is always a string. The resolver says what it means and how
 * to turn it into the thing handed to the caller (ADR-0040 "value resolvers").
 */
interface LiteralResolverInterface {

  /**
   * Returns the problems with a literal's stored value.
   *
   * @param \Drupal\literals\Entity\Literal $literal
   *   The literal being saved.
   *
   * @return string[]
   *   Error messages, empty when the value is valid.
   */
  public function validate(Literal $literal): array;

  /**
   * Resolves a literal's stored value to what the caller receives.
   *
   * @param \Drupal\literals\Entity\Literal $literal
   *   The literal.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the value is for; resolvers that point at other things check
   *   that account's access to them.
   * @param \Drupal\Core\Cache\CacheableMetadata $metadata
   *   Collects the cache metadata of everything consulted.
   *
   * @return string|null
   *   The resolved value, or NULL when it cannot be resolved for this
   *   account (missing target, no access).
   */
  public function resolve(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?string;

  /**
   * Resolves a literal to its value plus a label and a kind.
   *
   * @param \Drupal\literals\Entity\Literal $literal
   *   The literal.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the value is for.
   * @param \Drupal\Core\Cache\CacheableMetadata $metadata
   *   Collects the cache metadata of everything consulted.
   *
   * @return \Drupal\literals\ResolvedLiteral|null
   *   The resolved literal, or NULL when resolve() would give NULL. The value
   *   may be empty (a token that expands to nothing); callers decide whether
   *   that is an answer.
   */
  public function resolveItem(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?ResolvedLiteral;

}
