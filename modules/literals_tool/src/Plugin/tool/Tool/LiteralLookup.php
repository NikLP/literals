<?php

declare(strict_types=1);

namespace Drupal\literals_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralReader;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns an exact value, by key or by plain-language question.
 *
 * With a key it is a plain read. With a question it asks the finder (only
 * when literals_finder is enabled), which returns a match, an ambiguity or
 * nothing, never a guess. Either way the value is resolved and access-checked
 * for the calling account, and a missing literal and one the caller may not
 * see give the same "not found" answer.
 */
#[Tool(
  id: 'literal_lookup',
  label: new TranslatableMarkup('Look up a literal'),
  description: new TranslatableMarkup('Returns an exact value (a phone number, a URL, a name) kept as a literal, found by its key or by a plain-language question. Never guesses: an unclear question returns candidates or nothing.'),
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
      description: new TranslatableMarkup('What is wanted, in plain words, e.g. "the library phone number". Used only when no key is given.'),
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
    'value' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Value'),
      description: new TranslatableMarkup('The exact value of the matched literal. Empty unless the outcome is match.'),
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

  /**
   * The literal reader.
   */
  protected LiteralReader $reader;

  /**
   * The finder, when literals_finder is enabled.
   */
  protected ?object $finder = NULL;

  /**
   * {@inheritdoc}
   *
   * See aim_tool's AimRemember for why create() rather than the constructor.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->reader = $container->get('literals.reader');
    $instance->finder = $container->has('literals_finder.finder') ? $container->get('literals_finder.finder') : NULL;
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $key = trim((string) ($values['key'] ?? ''));
    $question = trim((string) ($values['question'] ?? ''));

    if ($key !== '') {
      return $this->byKey($key);
    }
    if ($question === '') {
      return ExecutableResult::failure(new TranslatableMarkup('Give a key or a question.'), NULL);
    }
    if (!$this->finder) {
      return ExecutableResult::failure(new TranslatableMarkup('Looking up by question needs the literals_finder module. Give a key.'), NULL);
    }
    return $this->byQuestion($question);
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
    $value = $this->reader->read($key, $this->currentUser, new CacheableMetadata());
    if ($value === NULL || $value === '') {
      return $this->notFound();
    }
    return ExecutableResult::success(
      new TranslatableMarkup('Found literal @key.', ['@key' => $key]),
      ['outcome' => self::MATCH, 'key' => $key, 'value' => $value],
    );
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
    $result = $finder->find($question, $this->currentUser);
    $keys = array_map(fn (Literal $literal): string => (string) $literal->get('key')->value, $result->literals);

    if ($result->outcome === self::MATCH && $keys) {
      $value = $result->literals[0]->resolve($this->currentUser, new CacheableMetadata());
      if ($value !== NULL && $value !== '') {
        return ExecutableResult::success(
          new TranslatableMarkup('Found literal @key.', ['@key' => $keys[0]]),
          ['outcome' => self::MATCH, 'key' => $keys[0], 'value' => $value],
        );
      }
      return $this->notFound();
    }
    if ($result->outcome === self::AMBIGUOUS && $keys) {
      return ExecutableResult::success(
        new TranslatableMarkup('More than one literal fits: @keys. Ask again with a key or a clearer question.', ['@keys' => implode(', ', $keys)]),
        ['outcome' => self::AMBIGUOUS, 'key' => implode(',', $keys), 'value' => ''],
      );
    }
    return $this->notFound();
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
      ['outcome' => self::NONE, 'key' => '', 'value' => ''],
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowedIfHasPermission($account, 'use literal lookup tool');
    return $return_as_object ? $access : $access->isAllowed();
  }

}
