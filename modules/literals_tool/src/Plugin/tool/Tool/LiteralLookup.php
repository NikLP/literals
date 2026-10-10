<?php

declare(strict_types=1);

namespace Drupal\literals_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\LiteralLookup as LiteralLookupService;
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
 * The Tool API face of the literals.lookup service (which see), for sites
 * without aim; aim sites use aim_tool's aim_literal, the same lookup under
 * aim's name.
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
      description: new TranslatableMarkup('Words from a literal name, key or description, e.g. "phone". Returns the matching literals (key, name, description) to choose from, never values. Used only when no key or question is given.'),
      required: FALSE,
    ),
  ],
  output_definitions: [
    'outcome' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Outcome'),
      description: new TranslatableMarkup('match, ambiguous, candidates or none.'),
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
  ],
)]
final class LiteralLookup extends ToolBase {

  /**
   * The shared literal lookup.
   */
  protected LiteralLookupService $lookup;

  /**
   * {@inheritdoc}
   *
   * See aim_tool's AimRemember for why create() rather than the constructor.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->lookup = $container->get('literals.lookup');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $result = $this->lookup->lookup($values, $this->currentUser, 'literals:lookup');
    return $result['success']
      ? ExecutableResult::success($result['message'], $result['values'])
      : ExecutableResult::failure($result['message'], NULL);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowedIfHasPermission($account, 'use literal lookup tool');
    return $return_as_object ? $access : $access->isAllowed();
  }

}
