<?php

declare(strict_types=1);

namespace Drupal\literals;

/**
 * Checks text being saved on a literal against write guardrails.
 *
 * Optional: an implementation is provided by a module that depends on
 * drupal/ai (literals_finder). Without one, saves are not guardrail-checked.
 */
interface LiteralGuardrailsInterface {

  /**
   * Runs text through the guardrail set.
   *
   * @param string $text
   *   The text.
   * @param bool $deterministic_only
   *   TRUE to skip any guardrail that would send the text to a model. Used for
   *   a literal's value, which never leaves the site.
   *
   * @return string
   *   The text, possibly rewritten by a guardrail.
   *
   * @throws \InvalidArgumentException
   *   If the guardrails reject the text.
   */
  public function check(string $text, bool $deterministic_only): string;

}
