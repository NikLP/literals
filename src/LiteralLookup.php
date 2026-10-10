<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Entity\Literal;

/**
 * Looks up a literal by key, by search words, or by question.
 *
 * The one lookup behind every tool that exposes literals (literals_tool's
 * literals:lookup, aim_tool's aim_literal), so they cannot drift. Needs no
 * Tool API: callers wrap the plain result. With a key it is a plain read.
 * With a question it asks the finder (only when literals_finder is
 * enabled), which returns a match, an ambiguity or nothing, never a guess.
 * Either way the value is resolved and access-checked for the account, and
 * a missing literal and one the account may not see give the same "none".
 */
final class LiteralLookup {

  /**
   * Outcomes, matching the finder's, which may not be installed.
   */
  public const MATCH = 'match';

  public const AMBIGUOUS = 'ambiguous';

  public const NONE = 'none';

  public const CANDIDATES = 'candidates';

  /**
   * Constructs the lookup.
   *
   * @param \Drupal\literals\LiteralReader $reader
   *   The literal reader.
   * @param \Drupal\literals\LiteralSearch $search
   *   The literal search.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, the default account.
   * @param object|null $finder
   *   The literals_finder finder
   *   (\Drupal\literals_finder\Finder\LiteralFinderInterface), when enabled.
   */
  public function __construct(
    protected LiteralReader $reader,
    protected LiteralSearch $search,
    protected AccountInterface $currentUser,
    protected ?object $finder = NULL,
  ) {}

  /**
   * Looks a literal up.
   *
   * A key wins over a question, a question over search words.
   *
   * @param array $input
   *   Any of key, question and search.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account. Defaults to the current user.
   * @param string $caller
   *   The calling tool's name, for the audit line.
   *
   * @return array{success: bool, message: \Drupal\Core\StringTranslation\TranslatableMarkup, values: array<string, string>}
   *   Whether the call was valid, a message for the caller, and the values
   *   outcome, key, candidates, value, label and kind (all always set).
   */
  public function lookup(array $input, ?AccountInterface $account = NULL, string $caller = 'lookup'): array {
    $account ??= $this->currentUser;
    $key = trim((string) ($input['key'] ?? ''));
    $question = trim((string) ($input['question'] ?? ''));
    $words = trim((string) ($input['search'] ?? ''));

    if ($key !== '') {
      $mode = 'key';
      $result = $this->byKey($key, $account);
    }
    elseif ($question !== '') {
      $mode = 'question';
      $result = $this->finder
        ? $this->byQuestion($question, $account)
        : $this->failure(new TranslatableMarkup('Looking up by question needs the literals_finder module. Give a key or search words.'));
    }
    elseif ($words !== '') {
      $mode = 'search';
      $result = $this->bySearch($words, $account);
    }
    else {
      $mode = 'none';
      $result = $this->failure(new TranslatableMarkup('Give a key, a question or search words.'));
    }

    // Audit every call, so an agent that skips the tool or retries it shows
    // up. The mode, outcome and keys only: never the key typed, the question
    // or the search words (personal data), and never a value.
    $this->reader->logAudit('Literal lookup (@caller): mode @mode, outcome @outcome, keys @keys, uid @uid.', [
      '@caller' => $caller,
      '@mode' => $mode,
      '@outcome' => $result['success'] ? $result['values']['outcome'] : 'error',
      '@keys' => $result['values']['key'] !== '' ? $result['values']['key'] : '-',
      '@uid' => $account->id(),
    ]);
    return $result;
  }

  /**
   * Looks a literal up by key.
   *
   * @param string $key
   *   The literal key.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array
   *   The result, as lookup() returns it.
   */
  protected function byKey(string $key, AccountInterface $account): array {
    $item = $this->reader->readItem($key, $account, new CacheableMetadata());
    return $item && $item->value !== '' ? $this->matched($key, $item) : $this->notFound();
  }

  /**
   * Looks a literal up by question, through the finder.
   *
   * @param string $question
   *   The question.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array
   *   The result, as lookup() returns it.
   */
  protected function byQuestion(string $question, AccountInterface $account): array {
    /** @var \Drupal\literals_finder\Finder\LiteralFinderInterface $finder */
    $finder = $this->finder;
    // No per-call context: the finder uses its site context.
    $result = $finder->find($question, $account);
    $keys = array_map(fn (Literal $literal): string => (string) $literal->id(), $result->literals);

    if ($result->outcome === self::MATCH && $keys) {
      $resolved = $this->reader->resolveFound($result->outcome, $result->literals, $account);
      $item = reset($resolved['items']);
      return $item ? $this->matched($keys[0], $item) : $this->notFound();
    }
    if ($result->outcome === self::AMBIGUOUS && $keys) {
      return $this->success(
        new TranslatableMarkup('More than one literal fits: @keys. Ask again with a key or a clearer question.', ['@keys' => implode(', ', $keys)]),
        self::AMBIGUOUS,
        implode(',', $keys),
        ['candidates' => $this->describe($result->literals)],
      );
    }
    return $this->notFound();
  }

  /**
   * Lists literals matching typed words, for the caller to choose from.
   *
   * @param string $words
   *   The search words.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array
   *   The result, as lookup() returns it: candidates, never values.
   */
  protected function bySearch(string $words, AccountInterface $account): array {
    $literals = array_values($this->search->search($words, $account, 10));
    if (!$literals) {
      return $this->notFound();
    }
    $keys = array_map(fn (Literal $literal): string => (string) $literal->id(), $literals);
    return $this->success(
      new TranslatableMarkup('Found @count literal(s). Call again with a key to get a value.', ['@count' => count($literals)]),
      self::CANDIDATES,
      implode(',', $keys),
      ['candidates' => $this->describe($literals)],
    );
  }

  /**
   * Describes literals as "key: name - gist" lines, never values.
   *
   * @param \Drupal\literals\Entity\Literal[] $literals
   *   The literals.
   *
   * @return string
   *   One line per literal.
   */
  protected function describe(array $literals): string {
    $lines = [];
    foreach ($literals as $literal) {
      $gist = $literal->getGist();
      $lines[] = $literal->id() . ': ' . $literal->label() . ($gist !== '' ? ' - ' . $gist : '');
    }
    return implode("\n", $lines);
  }

  /**
   * Builds the not-found result: the same for missing and not visible.
   *
   * @return array
   *   The result, as lookup() returns it.
   */
  protected function notFound(): array {
    return $this->success(new TranslatableMarkup('No matching literal found.'), self::NONE, '');
  }

  /**
   * Builds the match result.
   *
   * @param string $key
   *   The literal key.
   * @param \Drupal\literals\ResolvedLiteral $item
   *   The resolved literal.
   *
   * @return array
   *   The result, as lookup() returns it.
   */
  protected function matched(string $key, ResolvedLiteral $item): array {
    return $this->success(new TranslatableMarkup('Found literal @key.', ['@key' => $key]), self::MATCH, $key, $item->toArray());
  }

  /**
   * Builds a successful result with every value set.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   The message.
   * @param string $outcome
   *   The outcome.
   * @param string $key
   *   The key, or the comma-separated candidate keys.
   * @param array $values
   *   Any of candidates, value, label and kind.
   *
   * @return array
   *   The result, as lookup() returns it.
   */
  protected function success(TranslatableMarkup $message, string $outcome, string $key, array $values = []): array {
    return [
      'success' => TRUE,
      'message' => $message,
      'values' => $values + [
        'outcome' => $outcome,
        'key' => $key,
        'candidates' => '',
        'value' => '',
        'label' => '',
        'kind' => '',
      ],
    ];
  }

  /**
   * Builds a failed result: invalid input, not an empty answer.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   The message.
   *
   * @return array
   *   The result, as lookup() returns it.
   */
  protected function failure(TranslatableMarkup $message): array {
    $result = $this->success($message, self::NONE, '');
    $result['success'] = FALSE;
    return $result;
  }

}
