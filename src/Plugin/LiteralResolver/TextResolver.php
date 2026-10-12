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
 * A plain text value.
 *
 * No external URLs: a link literal uses the url resolver, which accepts
 * internal paths only and checks route access.
 */
#[LiteralResolver(
  id: 'text',
  label: new TranslatableMarkup('Text'),
  description: new TranslatableMarkup('The exact text returned, e.g. a phone number.'),
)]
class TextResolver extends LiteralResolverBase {

  /**
   * {@inheritdoc}
   */
  public function validate(Literal $literal): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?string {
    return (string) $literal->get('value')->value;
  }

}
