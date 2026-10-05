<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\literals\Entity\Literal;
use Drupal\literals\Entity\LiteralType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Shared setup: schemas, the standard types, accounts and a literal factory.
 */
abstract class LiteralsKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'filter',
    'node',
    'views',
    'literals',
  ];

  /**
   * An account with no permissions, signed in.
   */
  protected UserInterface $member;

  /**
   * An account holding "view restricted literals".
   */
  protected UserInterface $restrictedViewer;

  /**
   * An account holding "administer literals".
   */
  protected UserInterface $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('literal');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node']);

    // Uid 1 bypasses every access check; keep it out of the way.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    LiteralType::create(['id' => 'text', 'label' => 'Text', 'resolver' => 'text'])->save();
    LiteralType::create(['id' => 'phone', 'label' => 'Phone', 'resolver' => 'text', 'validate_as' => 'phone'])->save();
    LiteralType::create(['id' => 'token', 'label' => 'Token', 'resolver' => 'token'])->save();
    LiteralType::create(['id' => 'entity', 'label' => 'Entity', 'resolver' => 'entity'])->save();
    LiteralType::create(['id' => 'url', 'label' => 'URL', 'resolver' => 'url'])->save();

    $this->member = $this->createUser([], 'member');
    $this->restrictedViewer = $this->createUser(['view restricted literals'], 'restricted_viewer');
    $this->admin = $this->createUser(['administer literals'], 'literal_admin');
  }

  /**
   * Creates and saves a literal, validating first.
   *
   * @param string $key
   *   The key.
   * @param string $value
   *   The value.
   * @param array $values
   *   Overrides: type, audience, status, gist, name.
   *
   * @return \Drupal\literals\Entity\Literal
   *   The saved literal.
   */
  protected function createLiteral(string $key, string $value, array $values = []): Literal {
    $literal = Literal::create($values + [
      'type' => 'text',
      'name' => ucfirst($key),
      'key' => $key,
      'value' => $value,
      'gist' => "The $key",
      'audience' => 'anonymous',
      'status' => 1,
    ]);
    $violations = $literal->validate();
    $this->assertCount(0, $violations, (string) $violations);
    $literal->save();
    return $literal;
  }

  /**
   * Creates a published page node.
   *
   * @param string $title
   *   The title.
   * @param bool $published
   *   Whether the node is published.
   *
   * @return \Drupal\node\Entity\Node
   *   The node.
   */
  protected function createPage(string $title, bool $published = TRUE): Node {
    if (!NodeType::load('page')) {
      NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    }
    $node = Node::create([
      'type' => 'page',
      'title' => $title,
      'status' => $published ? 1 : 0,
      'uid' => $this->admin->id(),
    ]);
    $node->save();
    return $node;
  }

}
