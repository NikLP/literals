<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\LiteralKind;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Attribute\LiteralKind;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralKindBase;

/**
 * A token string, replaced at read time, e.g. "[site:name]".
 */
#[LiteralKind(
  id: 'token',
  label: new TranslatableMarkup('Token'),
  description: new TranslatableMarkup('Token text resolved when read, e.g. [site:name].'),
)]
class TokenKind extends LiteralKindBase {

  /**
   * {@inheritdoc}
   */
  public function validate(Literal $literal): array {
    $value = (string) $literal->get('value')->value;
    $token_service = \Drupal::token();
    $unknown = [];
    $available = $token_service->getInfo()['tokens'] ?? [];
    foreach ($token_service->scan($value) as $type => $tokens) {
      foreach (array_keys($tokens) as $name) {
        if (!isset($available[$type][explode(':', (string) $name)[0]])) {
          $unknown[] = "[$type:$name]";
        }
      }
    }
    if (!$token_service->scan($value)) {
      return [(string) new TranslatableMarkup('The value contains no token. Use the Text kind for plain text.')];
    }
    return $unknown ? [(string) new TranslatableMarkup('Unknown token: @tokens', ['@tokens' => implode(', ', $unknown)])] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?string {
    $bubbleable = new BubbleableMetadata();
    $text = \Drupal::token()->replace((string) $literal->get('value')->value, [], ['clear' => TRUE], $bubbleable);
    $metadata->addCacheableDependency($bubbleable);
    return $text;
  }

}
