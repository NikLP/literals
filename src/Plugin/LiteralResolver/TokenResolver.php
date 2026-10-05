<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\LiteralResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Attribute\LiteralResolver;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralResolverBase;
use Drupal\user\Entity\User;

/**
 * A token string, replaced at read time, e.g. "[site:name]".
 */
#[LiteralResolver(
  id: 'token',
  label: new TranslatableMarkup('Token'),
  description: new TranslatableMarkup('Token text resolved when read, e.g. [site:name].'),
)]
class TokenResolver extends LiteralResolverBase {

  /**
   * {@inheritdoc}
   */
  public function validate(Literal $literal): array {
    $value = (string) $literal->get('value')->value;
    $token_service = \Drupal::token();
    $info = $token_service->getInfo();
    $available = $info['tokens'] ?? [];
    $found = $token_service->scan($value);
    if (!$found) {
      return [(string) new TranslatableMarkup('The value contains no token. Use the Text resolver for plain text.')];
    }
    $unknown = [];
    $unsupported = [];
    foreach ($found as $type => $tokens) {
      foreach (array_keys($tokens) as $name) {
        $token = "[$type:$name]";
        if (!isset($available[$type][explode(':', (string) $name)[0]])) {
          $unknown[] = $token;
        }
        elseif ($type === 'current-user' || (!empty($info['types'][$type]['needs-data']) && $type !== 'user')) {
          // Session-bound, or needs an entity this resolver cannot supply.
          $unsupported[] = $token;
        }
      }
    }
    $errors = [];
    if ($unknown) {
      $errors[] = (string) new TranslatableMarkup('Unknown token: @tokens', ['@tokens' => implode(', ', $unknown)]);
    }
    if ($unsupported) {
      $errors[] = (string) new TranslatableMarkup('Not supported here: @tokens. Use [user:...] for the viewing account; entity tokens need an entity target.', ['@tokens' => implode(', ', $unsupported)]);
    }
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?string {
    $value = (string) $literal->get('value')->value;
    $token_service = \Drupal::token();
    // [user:...] always means the account asking, never the session user.
    $data = [];
    if (isset($token_service->scan($value)['user'])) {
      $data['user'] = User::load($account->id());
      $metadata->addCacheContexts(['user']);
    }
    $bubbleable = new BubbleableMetadata();
    $text = $token_service->replace($value, $data, ['clear' => TRUE, 'literals_account' => $account], $bubbleable);
    $metadata->addCacheableDependency($bubbleable);
    return $text;
  }

}
