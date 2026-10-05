<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\LiteralResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Attribute\LiteralResolver;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralResolverBase;

/**
 * A plain text value, optionally validated as a number, phone, email or URL.
 */
#[LiteralResolver(
  id: 'text',
  label: new TranslatableMarkup('Text'),
  description: new TranslatableMarkup('The exact text returned, e.g. a phone number.'),
)]
class TextResolver extends LiteralResolverBase {

  /**
   * Validation patterns by the "validate as" option.
   */
  public const PATTERNS = [
    'int' => '/^-?\d+$/',
    'phone' => '/^\+?[0-9 ()\-.]{6,25}$/',
    'url' => '/^https?:\/\/[^\s\/$.?#][^\s]*$/i',
  ];

  /**
   * {@inheritdoc}
   */
  public function validate(Literal $literal): array {
    $value = (string) $literal->get('value')->value;
    $format = $literal->getType()->getValidateAs();
    $valid = match ($format) {
      'int', 'phone', 'url' => (bool) preg_match(self::PATTERNS[$format], $value),
      'email' => (bool) filter_var($value, FILTER_VALIDATE_EMAIL),
      default => TRUE,
    };
    return $valid ? [] : [(string) new TranslatableMarkup('The value is not a valid @format.', ['@format' => $format])];
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?string {
    return (string) $literal->get('value')->value;
  }

}
