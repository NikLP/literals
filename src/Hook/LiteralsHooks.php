<?php

declare(strict_types=1);

namespace Drupal\literals\Hook;

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralAudience;
use Drupal\literals\LiteralReader;

/**
 * Hook implementations for the literals module.
 */
class LiteralsHooks {

  use StringTranslationTrait;

  /**
   * Constructs the hooks.
   *
   * @param \Drupal\literals\LiteralReader $reader
   *   The literal reader.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected LiteralReader $reader,
    protected Token $token,
    protected AccountInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_gin_content_form_routes().
   *
   * Gives the literal edit forms Gin's node-style sidebar layout.
   *
   * @return array
   *   Route names.
   */
  #[Hook('gin_content_form_routes')]
  public function ginContentFormRoutes(): array {
    return [
      'entity.literal.add_form',
      'entity.literal.edit_form',
    ];
  }

  /**
   * Implements hook_query_alter().
   *
   * Restricts literal queries to the audiences the account can see, so a
   * list never includes a literal (or its description) the viewer could not
   * open. Applies to entity queries that check access and to Views listing
   * literals.
   */
  #[Hook('query_alter')]
  public function queryAlter(AlterableInterface $query): void {
    if (!$query instanceof SelectInterface) {
      return;
    }
    if (!$query->hasTag('literal_access') && !$query->hasTag('views')) {
      return;
    }
    $alias = NULL;
    foreach ($query->getTables() as $table_alias => $info) {
      if (($info['table'] ?? NULL) === 'literal') {
        $alias = $table_alias;
        break;
      }
    }
    if ($alias === NULL) {
      return;
    }
    $account = $query->getMetaData('account') ?: \Drupal::currentUser();
    if ($account->hasPermission('administer literals')) {
      return;
    }
    $query->condition("$alias.audience", LiteralAudience::visibleTo($account), 'IN');
  }

  /**
   * Implements hook_token_info().
   *
   * One [literal:key] token per published literal, so the token browser lists
   * them and a token-resolver literal referencing an unknown key fails
   * validation.
   *
   * @return array
   *   Token types and tokens.
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    $info = [
      'types' => [
        'literal' => [
          'name' => $this->t('Literals'),
          'description' => $this->t('Exact values (phone numbers, URLs) kept as literals.'),
        ],
      ],
      'tokens' => ['literal' => []],
    ];
    $literals = $this->entityTypeManager->getStorage('literal')->loadByProperties(['status' => 1]);
    foreach ($literals as $literal) {
      $info['tokens']['literal'][(string) $literal->get('key')->value] = [
        'name' => $literal->label(),
        'description' => $this->t('The value of the literal "@name", if the viewer may see it.', ['@name' => $literal->label()]),
      ];
    }
    return $info;
  }

  /**
   * Implements hook_tokens().
   *
   * Unreadable literals (missing, unpublished, not visible to the account)
   * replace with nothing under the callers' usual "clear" option; the token
   * text never reveals which of those it was. The value is returned as plain
   * text, like core's own tokens: escaping is the caller's job.
   *
   * @return array
   *   Replacements keyed by original token text.
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    if ($type !== 'literal') {
      return [];
    }
    // A token literal resolving for another account passes that account.
    $account = ($options['literals_account'] ?? NULL) instanceof AccountInterface ? $options['literals_account'] : $this->currentUser;
    $replacements = [];
    foreach ($tokens as $name => $original) {
      $value = $this->reader->read((string) $name, $account, $bubbleable_metadata);
      if ($value !== NULL) {
        $replacements[$original] = $value;
      }
    }
    return $replacements;
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for literals.
   */
  #[Hook('literal_insert')]
  public function literalInsert(Literal $literal): void {
    $this->token->resetInfo();
    $this->reader->logAudit('Literal created: id @id, key @key, type @type, audience @audience, uid @uid.', $this->auditContext($literal));
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for literals.
   */
  #[Hook('literal_update')]
  public function literalUpdate(Literal $literal): void {
    $this->token->resetInfo();
    $this->reader->logAudit('Literal updated: id @id, key @key, type @type, audience @audience, uid @uid.', $this->auditContext($literal));
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for literals.
   */
  #[Hook('literal_delete')]
  public function literalDelete(Literal $literal): void {
    $this->token->resetInfo();
    $this->reader->logAudit('Literal deleted: id @id, key @key, type @type, audience @audience, uid @uid.', $this->auditContext($literal));
  }

  /**
   * Builds the audit placeholders: IDs and keys, never gist or value.
   *
   * @param \Drupal\literals\Entity\Literal $literal
   *   The literal.
   *
   * @return array
   *   Placeholder values.
   */
  protected function auditContext(Literal $literal): array {
    return [
      '@id' => $literal->id(),
      '@key' => (string) $literal->get('key')->value,
      '@type' => $literal->bundle(),
      '@audience' => $literal->getAudience(),
      '@uid' => $this->currentUser->id(),
    ];
  }

}
