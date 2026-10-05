<?php

declare(strict_types=1);

namespace Drupal\literals\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a literal kind: how a literal's stored value is read and checked.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class LiteralKind extends Plugin {

  /**
   * Constructs a LiteralKind attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   What to put in the value field for this kind.
   * @param class-string|null $deriver
   *   The deriver class, if any.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
