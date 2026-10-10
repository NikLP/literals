<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\literals\Entity\Literal;
use Psr\Log\LoggerInterface;

/**
 * Reads literals by key, for a given account.
 *
 * The one read path for callers that already know the key (the
 * [literal:key] token, the Tool API tool). Serves the published revision
 * only, and only when the account may view the literal. A missing key and an
 * inaccessible one are indistinguishable to the caller: both give NULL.
 */
class LiteralReader {

  /**
   * What replaceTokens() puts in place of a token it cannot read.
   */
  public const REDACTED = '[redacted]';

  /**
   * Keys being read right now, to stop token literals referencing each other.
   *
   * @var string[]
   */
  protected array $stack = [];

  /**
   * Constructs the reader.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, the default account.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The literals logger channel.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Loads the published literal with a key, if the account may view it.
   *
   * @param string $key
   *   The literal key.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account. Defaults to the current user.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $metadata
   *   Collects the cache metadata of what was consulted.
   *
   * @return \Drupal\literals\Entity\Literal|null
   *   The literal, or NULL when missing, unpublished or not viewable.
   */
  public function load(string $key, ?AccountInterface $account = NULL, ?CacheableMetadata $metadata = NULL): ?Literal {
    $account ??= $this->currentUser;
    $storage = $this->entityTypeManager->getStorage('literal');
    // Any literal change can add, remove or re-key a literal.
    $metadata?->addCacheTags(['literal_list']);
    // The key is the ID.
    $literal = $storage->load($key);
    if (!$literal instanceof Literal || !$literal->isPublished()) {
      return NULL;
    }
    $access = $literal->access('view', $account, TRUE);
    $metadata?->addCacheableDependency($access);
    $metadata?->addCacheableDependency($literal);
    return $access->isAllowed() ? $literal : NULL;
  }

  /**
   * Reads a literal's resolved value.
   *
   * @param string $key
   *   The literal key.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account. Defaults to the current user.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $metadata
   *   Collects the cache metadata of what was consulted.
   *
   * @return string|null
   *   The value, or NULL when it cannot be served to this account.
   */
  public function read(string $key, ?AccountInterface $account = NULL, ?CacheableMetadata $metadata = NULL): ?string {
    return $this->readItem($key, $account, $metadata)?->value;
  }

  /**
   * Reads a literal's resolved value with its label and kind.
   *
   * @param string $key
   *   The literal key.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account. Defaults to the current user.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $metadata
   *   Collects the cache metadata of what was consulted.
   *
   * @return \Drupal\literals\ResolvedLiteral|null
   *   The resolved literal, or NULL when it cannot be served to this account.
   */
  public function readItem(string $key, ?AccountInterface $account = NULL, ?CacheableMetadata $metadata = NULL): ?ResolvedLiteral {
    $account ??= $this->currentUser;
    $metadata ??= new CacheableMetadata();
    if (in_array($key, $this->stack, TRUE)) {
      $this->logger->warning('Literal @key references itself through [literal:...] tokens; not resolved.', ['@key' => $key]);
      return NULL;
    }
    $literal = $this->load($key, $account, $metadata);
    if (!$literal) {
      $this->logAudit('Literal read denied or missing: key @key, uid @uid.', ['@key' => $key, '@uid' => $account->id()]);
      return NULL;
    }
    $this->stack[] = $key;
    try {
      $item = $literal->resolveItem($account, $metadata);
    }
    finally {
      array_pop($this->stack);
    }
    $this->logAudit('Literal read: key @key, uid @uid, resolved @resolved.', [
      '@key' => $key,
      '@uid' => $account->id(),
      '@resolved' => $item === NULL ? 'no' : 'yes',
    ]);
    return $item;
  }

  /**
   * Resolves found literals for an account, dropping what resolves to nothing.
   *
   * A match or choice that cannot be resolved for this account downgrades to
   * none, and a match keeps only its first item.
   *
   * @param string $outcome
   *   The finder outcome: match, ambiguous or none.
   * @param \Drupal\literals\Entity\Literal[] $literals
   *   The found literals.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account. Defaults to the current user.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $metadata
   *   Collects the cache metadata of what was consulted.
   *
   * @return array{outcome: string, items: array<string, \Drupal\literals\ResolvedLiteral>}
   *   The outcome and the resolved items, keyed by literal key.
   */
  public function resolveFound(string $outcome, array $literals, ?AccountInterface $account = NULL, ?CacheableMetadata $metadata = NULL): array {
    $account ??= $this->currentUser;
    $metadata ??= new CacheableMetadata();
    $items = [];
    foreach ($literals as $literal) {
      $item = $literal->resolveItem($account, $metadata);
      if ($item && $item->value !== '') {
        $items[$literal->id()] = $item;
      }
    }
    if (!$items) {
      $outcome = 'none';
    }
    elseif ($outcome === 'match') {
      $items = array_slice($items, 0, 1, TRUE);
    }
    return ['outcome' => $outcome, 'items' => $items];
  }

  /**
   * Replaces [literal:key] and [literal:key:link] in plain text.
   *
   * For text a model or chat front end reads, so output is plain text and
   * Markdown, never HTML or the token service's escaping. A plain token
   * becomes the value; a link token becomes a Markdown link where the value
   * has a safe one, else the value. A literal that is missing, unpublished or
   * not visible to the account becomes REDACTED, the same for every cause, or
   * makes the whole call return NULL when $withholdIfRedacted is
   * set. Resolved values are not scanned again, so a value cannot inject
   * tokens.
   *
   * @param string $text
   *   The text, for example a fact.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The viewing account. Defaults to the current user.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $metadata
   *   Collects the cache metadata of what was consulted.
   * @param bool $withholdIfRedacted
   *   Return NULL instead of the text when any token cannot be read, for a
   *   caller that should not show a sentence with a hole in it.
   *
   * @return string|null
   *   The text with tokens replaced, or NULL when withheld.
   */
  public function replaceTokens(string $text, ?AccountInterface $account = NULL, ?CacheableMetadata $metadata = NULL, bool $withholdIfRedacted = FALSE): ?string {
    if (!str_contains($text, '[literal:')) {
      return $text;
    }
    $redacted = FALSE;
    $result = preg_replace_callback('/\[literal:([^\]:\s]+)(:link)?\]/', function (array $match) use ($account, $metadata, &$redacted): string {
      $item = $this->readItem($match[1], $account, $metadata);
      if ($item === NULL || $item->value === '') {
        $redacted = TRUE;
        return self::REDACTED;
      }
      $href = empty($match[2]) ? NULL : $item->href();
      if ($href === NULL) {
        return $item->value;
      }
      $label = $item->kind === ResolvedLiteral::KIND_URL ? $item->label : $item->value;
      $href = str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $href);
      return '[' . addcslashes($label, '[]\\') . '](' . $href . ')';
    }, $text) ?? $text;
    return $redacted && $withholdIfRedacted ? NULL : $result;
  }

  /**
   * Logs an info line if literals.settings:log_audit is on.
   *
   * Never the gist or the value, only IDs, keys and outcomes.
   *
   * @param string $message
   *   The log message, with placeholders.
   * @param array $context
   *   The placeholder values.
   */
  public function logAudit(string $message, array $context = []): void {
    if ($this->configFactory->get('literals.settings')->get('log_audit')) {
      $this->logger->info($message, $context);
    }
  }

}
