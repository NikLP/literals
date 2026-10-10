<?php

declare(strict_types=1);

namespace Drupal\literals\Hook;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralVisibility;
use Drupal\literals\LiteralReader;
use Drupal\literals\ResolvedLiteral;

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
   * Gives the literal edit forms Gin's node-style sidebar layout. Gin is an
   * undeclared optional dependency: without it this hook is never called.
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
   * Restricts literal queries to what the account can see
   * (LiteralVisibility), so a list never includes a literal (or its
   * description) the viewer could not open. Applies to entity queries that
   * check access and to Views listing literals.
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
    $account = $query->getMetaData('account') ?: $this->currentUser;
    $flags = LiteralVisibility::visibleFlags($account);
    if ($flags === NULL) {
      return;
    }
    if ($flags === []) {
      $query->alwaysFalse();
      return;
    }
    $query->condition("$alias.restricted", $flags, 'IN');
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
      $info['tokens']['literal'][$literal->id()] = [
        'name' => $literal->label(),
        'description' => $this->t('The value of the literal "@name", if the viewer may see it.', ['@name' => $literal->label()]),
      ];
      $info['tokens']['literal'][$literal->id() . ':link'] = [
        'name' => $this->t('@name (link)', ['@name' => $literal->label()]),
        'description' => $this->t('The literal "@name" as a link (HTML), if the viewer may see it. Text values come out as plain escaped text.', ['@name' => $literal->label()]),
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
      [$key, $format] = array_pad(explode(':', (string) $name, 2), 2, NULL);
      if ($format === 'link') {
        $item = $this->reader->readItem($key, $account, $bubbleable_metadata);
        if ($item !== NULL && $item->value !== '') {
          $replacements[$original] = $this->link($item);
        }
      }
      elseif ($format === NULL) {
        $value = $this->reader->read($key, $account, $bubbleable_metadata);
        if ($value !== NULL) {
          $replacements[$original] = $value;
        }
      }
    }
    return $replacements;
  }

  /**
   * Renders a resolved literal as an HTML link, or plain text if it has none.
   *
   * The link text is the value for a phone number or email address and the
   * literal's label for a URL. A link is returned as markup so the token
   * service does not escape it; text stays a plain string, which it escapes.
   *
   * @param \Drupal\literals\ResolvedLiteral $item
   *   The resolved literal.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The link markup, or the plain value.
   */
  protected function link(ResolvedLiteral $item): MarkupInterface|string {
    $href = $item->href();
    if ($href === NULL) {
      return $item->value;
    }
    $text = $item->kind === ResolvedLiteral::KIND_URL ? $item->label : $item->value;
    return Markup::create('<a href="' . Html::escape($href) . '">' . Html::escape($text) . '</a>');
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for literals.
   */
  #[Hook('literal_insert')]
  public function literalInsert(Literal $literal): void {
    $this->token->resetInfo();
    $this->reader->logAudit('Literal created: key @key, type @type, restricted @restricted, uid @uid.', $this->auditContext($literal));
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for literals.
   */
  #[Hook('literal_update')]
  public function literalUpdate(Literal $literal): void {
    $this->token->resetInfo();
    $this->reader->logAudit('Literal updated: key @key, type @type, restricted @restricted, uid @uid.', $this->auditContext($literal));
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for literals.
   */
  #[Hook('literal_delete')]
  public function literalDelete(Literal $literal): void {
    $this->token->resetInfo();
    $this->reader->logAudit('Literal deleted: key @key, type @type, restricted @restricted, uid @uid.', $this->auditContext($literal));
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
      '@key' => $literal->id(),
      '@type' => $literal->bundle(),
      '@restricted' => (int) $literal->isRestricted(),
      '@uid' => $this->currentUser->id(),
    ];
  }

}
