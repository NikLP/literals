<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\literals\Entity\Literal;

/**
 * Tests each resolver's validation and its read-time resolution.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralResolversTest extends LiteralsKernelTestBase {

  /**
   * Whether a value validates for a type.
   */
  protected function valid(string $type, string $value): bool {
    $literal = Literal::create(['type' => $type, 'name' => 'n', 'key' => 'k_' . $type, 'value' => $value, 'audience' => 'anonymous']);
    return count($literal->validate()) === 0;
  }

  /**
   * The phone pattern accepts phone-ish strings only.
   */
  public function testPhoneValidation(): void {
    foreach (['+44 1223 000000', '(555) 010-0100', '555.010.0100', '+1 555 0102'] as $ok) {
      $this->assertTrue($this->valid('phone', $ok), $ok);
    }
    foreach (['call me', '12', 'abc123456', '+44 1223 000000 ext <b>', ''] as $bad) {
      // The empty string is skipped by the constraint (required handles it).
      if ($bad === '') {
        continue;
      }
      $this->assertFalse($this->valid('phone', $bad), $bad);
    }
  }

  /**
   * Plain text accepts anything and resolves to itself.
   */
  public function testTextResolves(): void {
    $literal = $this->createLiteral('hours', 'Mon-Fri 9-17');
    $this->assertSame('Mon-Fri 9-17', $literal->resolve(new AnonymousUserSession(), new CacheableMetadata()));
  }

  /**
   * The URL resolver takes internal paths only.
   */
  public function testUrlKind(): void {
    $this->assertFalse($this->valid('url', 'https://example.com'));
    $this->assertFalse($this->valid('url', '//evil.example/path'));
    $this->assertFalse($this->valid('url', 'news'));
    $this->assertFalse($this->valid('url', 'javascript:alert(1)'));
    $this->assertTrue($this->valid('url', '/user/login'));
    $literal = $this->createLiteral('login', '/user/login', ['type' => 'url']);
    $resolved = $literal->resolve(new AnonymousUserSession(), new CacheableMetadata());
    $this->assertStringEndsWith('/user/login', $resolved);
    $this->assertStringStartsWith('http', $resolved);
  }

  /**
   * An internal path the account cannot access resolves to NULL.
   */
  public function testUrlKindChecksAccess(): void {
    $literal = $this->createLiteral('admin_page', '/admin/config', ['type' => 'url']);
    $this->assertNull($literal->resolve(new AnonymousUserSession(), new CacheableMetadata()));
    $this->assertNull($literal->resolve($this->member, new CacheableMetadata()));
  }

  /**
   * The entity resolver needs a real entity and checks view access per account.
   */
  public function testEntityKind(): void {
    $this->assertFalse($this->valid('entity', 'node:999'));
    $this->assertFalse($this->valid('entity', 'nonsense'));
    $this->assertFalse($this->valid('entity', 'nosuchtype:1'));

    $published = $this->createPage('Open page');
    $draft = $this->createPage('Draft page', FALSE);
    $open = $this->createLiteral('open_page', 'node:' . $published->id(), ['type' => 'entity']);
    $hidden = $this->createLiteral('draft_page', 'node:' . $draft->id(), ['type' => 'entity']);

    $viewer = $this->createUser(['access content']);
    $this->assertStringContainsString('/node/' . $published->id(), $open->resolve($viewer, new CacheableMetadata()));
    $this->assertNull($open->resolve(new AnonymousUserSession(), new CacheableMetadata()), 'No access content permission');
    $this->assertNull($hidden->resolve($viewer, new CacheableMetadata()), 'Unpublished node');
  }

  /**
   * A deleted target resolves to NULL, never an error.
   */
  public function testEntityKindDeletedTarget(): void {
    $page = $this->createPage('Gone soon');
    $literal = $this->createLiteral('gone', 'node:' . $page->id(), ['type' => 'entity']);
    $page->delete();
    $viewer = $this->createUser(['access content']);
    $this->assertNull($literal->resolve($viewer, new CacheableMetadata()));
  }

  /**
   * Token validation: needs a known token, rejects session-bound ones.
   */
  public function testTokenValidation(): void {
    $this->assertTrue($this->valid('token', '[site:name]'));
    $this->assertFalse($this->valid('token', 'no token here'));
    $this->assertFalse($this->valid('token', '[nonexistent:thing]'));
    $this->assertFalse($this->valid('token', '[current-user:name]'));
    $this->assertFalse($this->valid('token', '[node:title]'));
    $this->assertTrue($this->valid('token', '[user:name]'));
  }

  /**
   * [user:...] means the account asked for, not the session user.
   */
  public function testTokenResolvesForTheGivenAccount(): void {
    $literal = $this->createLiteral('whoami', 'Hello [user:name]', ['type' => 'token', 'audience' => 'authenticated']);
    // The session user is the admin; the value is for the member.
    $this->setCurrentUser($this->admin);
    $metadata = new CacheableMetadata();
    $this->assertSame('Hello member', $literal->resolve($this->member, $metadata));
    $this->assertContains('user', $metadata->getCacheContexts());
  }

  /**
   * The value stored on the row is the raw token, never the resolved text.
   */
  public function testTokenValueStoredRaw(): void {
    $literal = $this->createLiteral('site', '[site:name]', ['type' => 'token']);
    $this->assertSame('[site:name]', $literal->get('value')->value);
  }

}
