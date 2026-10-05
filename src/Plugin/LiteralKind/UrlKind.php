<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\LiteralKind;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\literals\Attribute\LiteralKind;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralKindBase;

/**
 * An external URL or an internal path, resolved to an absolute URL.
 */
#[LiteralKind(
  id: 'url',
  label: new TranslatableMarkup('URL or path'),
  description: new TranslatableMarkup('An https URL or an internal path such as /news. Internal paths are access checked.'),
)]
class UrlKind extends LiteralKindBase {

  /**
   * Builds the Url object for a stored value, or NULL if it is not one.
   */
  protected function toUrl(string $value): ?Url {
    $value = trim($value);
    try {
      if (preg_match('/^https?:\/\//i', $value) && UrlHelper::isValid($value, TRUE)) {
        return Url::fromUri($value);
      }
      if (str_starts_with($value, '/')) {
        return Url::fromUserInput($value);
      }
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(Literal $literal): array {
    $value = (string) $literal->get('value')->value;
    return $this->toUrl($value) ? [] : [(string) new TranslatableMarkup('Use an http(s) URL or an internal path starting with a slash.')];
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?string {
    $url = $this->toUrl((string) $literal->get('value')->value);
    if (!$url) {
      return NULL;
    }
    if (!$url->isExternal()) {
      $access = $url->access($account, TRUE);
      $metadata->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        return NULL;
      }
    }
    return $url->setAbsolute()->toString();
  }

}
