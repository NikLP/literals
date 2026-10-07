<?php

declare(strict_types=1);

namespace Drupal\literals_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralReader;
use Drupal\literals\ResolvedLiteral;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Looks up a literal by key, by search words, or by question.
 *
 * With a key it is a plain read. With a question it asks the finder (only
 * when literals_finder is enabled), which returns a match, an ambiguity or
 * nothing, never a guess. Either way the value is resolved and access-checked
 * for the calling account, and a missing literal and one the caller may not
 * see give the same "not found" answer.
 */
#[Tool(
  id: 'literals:lookup',
  label: new TranslatableMarkup('Look up a literal'),
  description: new TranslatableMarkup('Returns an exact value (a phone number, a URL, a name) kept as a literal. Give a key when it is known; search to list candidate literals by typing words from their name or description; or question to have a model pick one from a plain-language question (never guesses: an unclear question returns candidates or nothing).'),
  operation: ToolOperation::Read,
  input_definitions: [
    'key' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Key'),
      description: new TranslatableMarkup('The literal key, when it is known. Takes precedence over question.'),
      required: FALSE,
    ),
    'question' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Question'),
      description: new TranslatableMarkup('What is wanted, in plain words, e.g. "the library phone number". Used only when no key is given. Needs the literals_finder module.'),
      required: FALSE,
    ),
    'search' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search words'),
      description: new TranslatableMarkup('Words from a literal name, key or description, e.g. "phone". Returns the matching literals (key, name, description) to choose from, never values. Used only when no key or question is given. Needs the literals_search module.'),
      required: FALSE,
    ),
  ],
  output_definitions: [
    'outcome' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Outcome'),
      description: new TranslatableMarkup('match, ambiguous or none.'),
    ),
    'key' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Key'),
      description: new TranslatableMarkup('The key of the matched literal, or the candidate keys when ambiguous.'),
    ),
    'candidates' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Candidates'),
      description: new TranslatableMarkup('One line per candidate literal, "key: name - description", when the outcome is ambiguous or a search was made. Call again with a key to get a value.'),
    ),
    'value' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Value'),
      description: new TranslatableMarkup('The exact value of the matched literal. Empty unless the outcome is match.'),
    ),
    'label' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Label'),
      description: new TranslatableMarkup('A human label for the value, usable as link text. Empty unless the outcome is match.'),
    ),
    'kind' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Kind'),
      description: new TranslatableMarkup('What the value is: url, phone, email or text. Empty unless the outcome is match.'),
    ),
    'items' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Items'),
      description: new TranslatableMarkup('A JSON list of the literals involved. For a match: one {key, label, kind, value}. For ambiguous or search: {key, label, gist} per candidate, never values.'),
    ),
  ],
)]
final class LiteralLookup extends ToolBase {

  /**
   * Outcomes, matching the finder's, which may not be installed.
   */
  protected const MATCH = 'match';

  protected const AMBIGUOUS = 'ambiguous';

  protected const NONE = 'none';

  protected const CANDIDATES = 'candidates';

  /**
   * The literal reader.
   */
  protected LiteralReader $reader;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The finder, when literals_finder is enabled.
   */
  protected ?object $finder = NULL;

  /**
   * The search, when literals_search is enabled.
   */
  protected ?object $search = NULL;

  /**
   * {@inheritdoc}
   *
   * See aim_tool's AimRemember for why create() rather than the constructor.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->reader = $container->get('literals.reader');
    $instance->configFactory = $container->get('config.factory');
    $instance->finder = $container->has('literals_finder.finder') ? $container->get('literals_finder.finder') : NULL;
    $instance->search = $container->has('literals_search.search') ? $container->get('literals_search.search') : NULL;
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $key = trim((string) ($values['key'] ?? ''));
    $question = trim((string) ($values['question'] ?? ''));
    $words = trim((string) ($values['search'] ?? ''));

    if ($key !== '') {
      return $this->byKey($key);
    }
    if ($question !== '') {
      if (!$this->finder) {
        return ExecutableResult::failure(new TranslatableMarkup('Looking up by question needs the literals_finder module. Give a key or search words.'), NULL);
      }
      return $this->byQuestion($question);
    }
    if ($words !== '') {
      if (!$this->search) {
        return ExecutableResult::failure(new TranslatableMarkup('Searching needs the literals_search module. Give a key.'), NULL);
      }
      return $this->bySearch($words);
    }
    return ExecutableResult::failure(new TranslatableMarkup('Give a key, a question or search words.'), NULL);
  }

  /**
   * Looks a literal up by key.
   *
   * @param string $key
   *   The literal key.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result.
   */
  protected function byKey(string $key): ExecutableResult {
    $item = $this->reader->readItem($key, $this->currentUser, new CacheableMetadata());
    return $item && $item->value !== '' ? $this->matched($key, $item) : $this->notFound();
  }

  /**
   * Looks a literal up by question, through the finder.
   *
   * @param string $question
   *   The question.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result.
   */
  protected function byQuestion(string $question): ExecutableResult {
    /** @var \Drupal\literals_finder\Finder\LiteralFinderInterface $finder */
    $finder = $this->finder;
    // An admin-set context for this medium, never a caller input.
    $context = trim((string) $this->configFactory->get('literals_tool.settings')->get('question_context'));
    $result = $finder->find($question, $this->currentUser, $context !== '' ? $context : NULL);
    $keys = array_map(fn (Literal $literal): string => (string) $literal->get('key')->value, $result->literals);

    if ($result->outcome === self::MATCH && $keys) {
      $item = $result->literals[0]->resolveItem($this->currentUser, new CacheableMetadata());
      return $item && $item->value !== '' ? $this->matched($keys[0], $item) : $this->notFound();
    }
    if ($result->outcome === self::AMBIGUOUS && $keys) {
      return ExecutableResult::success(
        new TranslatableMarkup('More than one literal fits: @keys. Ask again with a key or a clearer question.', ['@keys' => implode(', ', $keys)]),
        $this->output(self::AMBIGUOUS, implode(',', $keys), [
          'candidates' => $this->describe($result->literals),
          'items' => $this->candidateItems($result->literals),
        ]),
      );
    }
    return $this->notFound();
  }

  /**
   * Lists literals matching typed words, for the caller to choose from.
   *
   * @param string $words
   *   The search words.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result: candidates, never values.
   */
  protected function bySearch(string $words): ExecutableResult {
    /** @var \Drupal\literals_search\LiteralSearch $search */
    $search = $this->search;
    $literals = array_values($search->search($words, $this->currentUser, 10));
    if (!$literals) {
      return $this->notFound();
    }
    $keys = array_map(fn (Literal $literal): string => (string) $literal->get('key')->value, $literals);
    return ExecutableResult::success(
      new TranslatableMarkup('Found @count literal(s). Call again with a key to get a value.', ['@count' => count($literals)]),
      $this->output(self::CANDIDATES, implode(',', $keys), [
        'candidates' => $this->describe($literals),
        'items' => $this->candidateItems($literals),
      ]),
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
      $lines[] = $literal->get('key')->value . ': ' . $literal->label() . ($gist !== '' ? ' - ' . $gist : '');
    }
    return implode("\n", $lines);
  }

  /**
   * Builds the not-found result: the same for missing and not visible.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result.
   */
  protected function notFound(): ExecutableResult {
    return ExecutableResult::success(
      new TranslatableMarkup('No matching literal found.'),
      $this->output(self::NONE, ''),
    );
  }

  /**
   * Builds the match result.
   *
   * @param string $key
   *   The literal key.
   * @param \Drupal\literals\ResolvedLiteral $item
   *   The resolved literal.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result.
   */
  protected function matched(string $key, ResolvedLiteral $item): ExecutableResult {
    return ExecutableResult::success(
      new TranslatableMarkup('Found literal @key.', ['@key' => $key]),
      $this->output(self::MATCH, $key, $item->toArray() + [
        'items' => json_encode([['key' => $key] + $item->toArray()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ]),
    );
  }

  /**
   * Lists literals as JSON candidates (key, label, gist), never values.
   *
   * @param \Drupal\literals\Entity\Literal[] $literals
   *   The literals.
   *
   * @return string
   *   A JSON list.
   */
  protected function candidateItems(array $literals): string {
    $items = [];
    foreach ($literals as $literal) {
      $items[] = [
        'key' => (string) $literal->get('key')->value,
        'label' => (string) $literal->label(),
        'gist' => $literal->getGist(),
      ];
    }
    return json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Builds the output values with every output defined.
   *
   * @param string $outcome
   *   The outcome.
   * @param string $key
   *   The key, or the comma-separated candidate keys.
   * @param array $values
   *   Any of candidates, value, label, kind and items.
   *
   * @return array
   *   The complete output values.
   */
  protected function output(string $outcome, string $key, array $values = []): array {
    return $values + [
      'outcome' => $outcome,
      'key' => $key,
      'candidates' => '',
      'value' => '',
      'label' => '',
      'kind' => '',
      'items' => '[]',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowedIfHasPermission($account, 'use literal lookup tool');
    return $return_as_object ? $access : $access->isAllowed();
  }

}
