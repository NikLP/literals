<?php

declare(strict_types=1);

namespace Drupal\literals_chat;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\literals\ResolvedLiteral;
use Drupal\literals_finder\Finder\LiteralFinderInterface;
use Drupal\literals_finder\Finder\LiteralFindResult;
use Psr\Log\LoggerInterface;

/**
 * Answers a question from literals only, with fixed reply templates.
 *
 * The finder picks the literal (a typed decision, no text generation) and the
 * reply is a template around the exact resolved value. No chat model runs, and
 * the value never reaches any model.
 */
class LiteralsChatResponder {

  /**
   * The longest question used, in characters.
   */
  public const MAX_QUESTION = 300;

  /**
   * Constructs the responder.
   *
   * @param \Drupal\literals_finder\Finder\LiteralFinderInterface $finder
   *   The literal finder.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, the default asker.
   * @param \Psr\Log\LoggerInterface $logger
   *   The literals logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected LiteralFinderInterface $finder,
    protected AccountInterface $currentUser,
    protected LoggerInterface $logger,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Answers a question.
   *
   * @param string $question
   *   The question as typed.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The asker. Defaults to the current user.
   *
   * @return array{outcome: string, message: string, items: array<int, array{label: string, kind: string, value: string}>}
   *   The outcome (match, ambiguous or none), a plain-text message and the
   *   resolved items to show with it. Values are already access checked.
   */
  public function answer(string $question, ?AccountInterface $account = NULL): array {
    $account ??= $this->currentUser;
    $question = mb_substr(trim($question), 0, self::MAX_QUESTION);
    $result = $this->finder->find($question, $account);

    $items = [];
    foreach ($result->literals as $literal) {
      $item = $literal->resolveItem($account, new CacheableMetadata());
      if ($item && $item->value !== '') {
        $items[] = $item->toArray();
      }
    }
    // A match or choice that cannot be resolved for this asker is a none.
    $outcome = $items ? $result->outcome : LiteralFindResult::NONE;
    if ($outcome === LiteralFindResult::MATCH) {
      $items = array_slice($items, 0, 1);
    }

    $this->audit($outcome, $result, count($items), $account);
    return [
      'outcome' => $outcome,
      'message' => $this->message($outcome, $items),
      'items' => $outcome === LiteralFindResult::NONE ? [] : $items,
    ];
  }

  /**
   * Renders an answer as Markdown for a chat front end.
   *
   * Labels and values are escaped, so a stored value can never inject
   * Markdown or HTML. Links are made only for kinds with a known-safe scheme.
   *
   * @param array $answer
   *   The result of answer().
   *
   * @return string
   *   Markdown: the message, then one bullet per item.
   */
  public function toMarkdown(array $answer): string {
    $lines = [$this->escape($answer['message'])];
    foreach ($answer['items'] as $item) {
      $label = $this->escape($item['label']);
      $value = $this->escape($item['value']);
      $href = (new ResolvedLiteral($item['value'], $item['label'], $item['kind']))->href();
      $href = $href !== NULL ? str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $href) : NULL;
      $lines[] = match (TRUE) {
        $href === NULL => "- $label: $value",
        $item['kind'] === ResolvedLiteral::KIND_URL => "- [$label]($href)",
        default => "- $label: [$value]($href)",
      };
    }
    return implode("\n", $lines);
  }

  /**
   * Backslash-escapes Markdown and HTML characters in plain text.
   *
   * Only characters that can start emphasis, code, a link or tag, a table
   * cell or an entity. Characters that matter only at the start of a line
   * (#, +, -, digits with a dot) are left alone, so line breaks are
   * collapsed to spaces: every line this builds then starts with a fixed
   * "- " or the fixed message.
   *
   * @param string $text
   *   The text.
   *
   * @return string
   *   The escaped text.
   */
  protected function escape(string $text): string {
    $text = preg_replace('/\s+/', ' ', $text);
    return preg_replace_callback('/[\\\\`*_\\[\\]()<>|~&]/', fn (array $m): string => '\\' . $m[0], $text);
  }

  /**
   * Builds the fixed reply text for an outcome.
   *
   * @param string $outcome
   *   The outcome.
   * @param array $items
   *   The resolved items.
   *
   * @return string
   *   Plain text; the client decides how to show the items.
   */
  protected function message(string $outcome, array $items): string {
    if ($outcome === LiteralFindResult::MATCH) {
      return match ($items[0]['kind']) {
        ResolvedLiteral::KIND_URL => 'Here you go:',
        default => $items[0]['label'] . ':',
      };
    }
    if ($outcome === LiteralFindResult::AMBIGUOUS) {
      return 'A few of these might be what you mean:';
    }
    return "I don't have that. Try asking another way.";
  }

  /**
   * Logs which path a question took, when the audit log is on.
   *
   * Never the question or the value: outcome, tier, reason, counts and uid.
   *
   * @param string $outcome
   *   The final outcome.
   * @param \Drupal\literals_finder\Finder\LiteralFindResult $result
   *   The finder's result.
   * @param int $shown
   *   How many items were shown.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The asker.
   */
  protected function audit(string $outcome, LiteralFindResult $result, int $shown, AccountInterface $account): void {
    if (!$this->configFactory->get('literals_finder.settings')->get('log_audit')) {
      return;
    }
    $this->logger->info('Chat: @outcome (finder @found via @tier, @reason), @shown shown, uid @uid, no chat model called.', [
      '@outcome' => $outcome,
      '@found' => $result->outcome,
      '@tier' => $result->tier,
      '@reason' => $result->reason ?: '-',
      '@shown' => $shown,
      '@uid' => $account->id(),
    ]);
  }

}
