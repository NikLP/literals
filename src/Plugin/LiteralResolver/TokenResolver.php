<?php

declare(strict_types=1);

namespace Drupal\literals\Plugin\LiteralResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Token;
use Drupal\literals\Attribute\LiteralResolver;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralResolverBase;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Constructs the resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Utility\Token $tokenService
   *   The token service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected Token $tokenService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('token'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(Literal $literal): array {
    $value = (string) $literal->get('value')->value;
    $info = $this->tokenService->getInfo();
    $available = $info['tokens'] ?? [];
    $found = $this->tokenService->scan($value);
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
    // [user:...] always means the account asking, never the session user.
    $data = [];
    if (isset($this->tokenService->scan($value)['user'])) {
      $data['user'] = User::load($account->id());
      $metadata->addCacheContexts(['user']);
    }
    $bubbleable = new BubbleableMetadata();
    $text = $this->tokenService->replace($value, $data, ['clear' => TRUE, 'literals_account' => $account], $bubbleable);
    $metadata->addCacheableDependency($bubbleable);
    return $text;
  }

}
