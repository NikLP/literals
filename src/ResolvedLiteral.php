<?php

declare(strict_types=1);

namespace Drupal\literals;

/**
 * A literal's resolved value with what a consumer needs to present it.
 *
 * Consumers (a chat reply, a search widget, a token, an MCP caller) render
 * this themselves; the resolver only says what the value is.
 */
final class ResolvedLiteral {

  /**
   * The value kind for a link (an absolute URL).
   */
  public const KIND_URL = 'url';

  /**
   * The value kind for a phone number.
   */
  public const KIND_PHONE = 'phone';

  /**
   * The value kind for an email address.
   */
  public const KIND_EMAIL = 'email';

  /**
   * The value kind for any other text.
   */
  public const KIND_TEXT = 'text';

  /**
   * Constructs a resolved literal.
   *
   * @param string $value
   *   The exact resolved value.
   * @param string $label
   *   A human label for the value, such as link text.
   * @param string $kind
   *   One of the KIND_* constants.
   */
  public function __construct(
    public readonly string $value,
    public readonly string $label,
    public readonly string $kind = self::KIND_TEXT,
  ) {}

  /**
   * Returns the resolved literal as a plain array.
   *
   * @return array{value: string, label: string, kind: string}
   *   The value, label and kind.
   */
  public function toArray(): array {
    return ['value' => $this->value, 'label' => $this->label, 'kind' => $this->kind];
  }

}
