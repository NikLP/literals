<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\LiteralKind;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\literals\Attribute\LiteralKind;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralKindBase;

/**
 * An internal path, resolved to an absolute URL after an access check.
 */
#[LiteralKind(
  id: 'url',
  label: new TranslatableMarkup('Internal path'),
  description: new TranslatableMarkup('An internal path such as /news, access checked for the viewer.'),
)]
class UrlKind extends LiteralKindBase {

  /**
   * Builds the Url object for a stored value, or NULL if it is not a path.
   */
  protected function toUrl(string $value): ?Url {
    $value = trim($value);
    // Internal paths only: they can be access checked, external URLs cannot.
    if (!str_starts_with($value, '/') || str_starts_with($value, '//')) {
      return NULL;
    }
    try {
      return Url::fromUserInput($value);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validate(Literal $literal): array {
    $value = (string) $literal->get('value')->value;
    return $this->toUrl($value) ? [] : [(string) new TranslatableMarkup('Use an internal path starting with a slash, such as /news.')];
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?string {
    $url = $this->toUrl((string) $literal->get('value')->value);
    if (!$url) {
      return NULL;
    }
    $access = $url->access($account, TRUE);
    $metadata->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return NULL;
    }
    return $url->setAbsolute()->toString();
  }

}
