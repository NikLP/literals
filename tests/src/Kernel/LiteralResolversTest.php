<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\literals\Entity\Literal;
use Drupal\literals\Entity\LiteralType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

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
    $literal = Literal::create([
      'type' => $type,
      'name' => 'n',
      'id' => 'k_' . $type,
      'value' => $value,
    ]);
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
    $this->assertFalse($this->valid('url', '/no/such/page'), 'A path that matches no route');
    $this->assertTrue($this->valid('url', '/user/login'));
    $literal = $this->createLiteral('login', '/user/login', ['type' => 'url']);
    $resolved = $literal->resolve(new AnonymousUserSession(), new CacheableMetadata());
    $this->assertStringEndsWith('/user/login', $resolved);
    $this->assertStringStartsWith('http', $resolved);
  }

  /**
   * Each resolver reports a label and a kind with the value.
   */
  public function testResolveItemCarriesLabelAndKind(): void {
    $anon = new AnonymousUserSession();
    $text = $this->createLiteral('hours', 'Mon-Fri 9-17', ['name' => 'Opening hours'])->resolveItem($anon, new CacheableMetadata());
    $this->assertSame(['value' => 'Mon-Fri 9-17', 'label' => 'Opening hours', 'kind' => 'text'], $text->toArray());

    $link = $this->createLiteral('login', '/user/login', ['type' => 'url', 'name' => 'Sign in'])->resolveItem($anon, new CacheableMetadata());
    $this->assertSame('url', $link->kind);
    $this->assertSame('Sign in', $link->label);
    $this->assertStringEndsWith('/user/login', $link->value);

    $page = $this->createPage('Open page');
    $viewer = $this->createUser(['access content']);
    $entity = $this->createLiteral('open_page', 'node:' . $page->id(), ['type' => 'entity', 'name' => 'Ignored name'])->resolveItem($viewer, new CacheableMetadata());
    $this->assertSame('Open page', $entity->label, 'Entity literals label with the entity title');
    $this->assertSame('url', $entity->kind);

    $denied = $this->createLiteral('admin_page', '/admin/config', ['type' => 'url'])->resolveItem($anon, new CacheableMetadata());
    $this->assertNull($denied);
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
   * The [user:...] token means the account asked for, not the session user.
   */
  public function testTokenResolvesForTheGivenAccount(): void {
    $literal = $this->createLiteral('whoami', 'Hello [user:name]', ['type' => 'token']);
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

  /**
   * The field resolver validates entity_type:id:field_name.
   */
  public function testFieldValidation(): void {
    $uid = $this->member->id();
    $this->assertTrue($this->valid('field', "user:$uid:name"));
    $this->assertFalse($this->valid('field', "user:$uid"), 'No field name');
    $this->assertFalse($this->valid('field', "user:$uid:field_nope"), 'No such field');
    $this->assertFalse($this->valid('field', 'user:99999:name'), 'No such entity');
    $this->assertFalse($this->valid('field', "nosuchtype:$uid:name"), 'No such entity type');
  }

  /**
   * The value needs both entity and field view access, and has a kind.
   */
  public function testFieldResolvesWithBothAccessChecks(): void {
    $this->member->set('mail', 'member@example.com')->save();
    $uid = $this->member->id();
    $name = $this->createLiteral('member_name', "user:$uid:name", ['type' => 'field']);
    $mail = $this->createLiteral('member_mail', "user:$uid:mail", ['type' => 'field']);
    $viewer = $this->createUser(['access user profiles']);

    // No "access user profiles": the entity itself is not viewable.
    $this->assertNull($name->resolve($this->restrictedViewer));
    // Entity viewable, name field viewable.
    $this->assertSame('member', $name->resolve($viewer));
    // Entity viewable, but core denies another account's mail field.
    $this->assertNull($mail->resolve($viewer));
    // The account itself may see its own mail; it is an email address.
    $item = $mail->resolveItem($this->member);
    $this->assertSame('member@example.com', $item?->value);
    $this->assertSame('mailto:member@example.com', $item?->href());
  }

  /**
   * Field access and the entity become cache dependencies.
   */
  public function testFieldBubblesCacheMetadata(): void {
    $uid = $this->member->id();
    $literal = $this->createLiteral('member_name', "user:$uid:name", ['type' => 'field']);
    $metadata = new CacheableMetadata();
    $literal->resolve($this->createUser(['access user profiles']), $metadata);
    $this->assertContains('user:' . $uid, $metadata->getCacheTags());
  }

  /**
   * Text values validated as whole numbers and email addresses.
   */
  public function testIntAndEmailValidation(): void {
    LiteralType::create(['id' => 'int', 'label' => 'Number', 'resolver' => 'text', 'validate_as' => 'int'])->save();
    LiteralType::create(['id' => 'email', 'label' => 'Email', 'resolver' => 'text', 'validate_as' => 'email'])->save();
    $this->assertTrue($this->valid('int', '42'));
    $this->assertTrue($this->valid('int', '-3'));
    $this->assertFalse($this->valid('int', '4.2'));
    $this->assertFalse($this->valid('int', 'forty'));
    $this->assertTrue($this->valid('email', 'help@example.com'));
    $this->assertFalse($this->valid('email', 'help at example'));
  }

  /**
   * The entity label is the link text; no canonical page means no value.
   */
  public function testEntityLabelAndNoCanonical(): void {
    $page = $this->createPage('Open page');
    $literal = $this->createLiteral('open_page', 'node:' . $page->id(), ['type' => 'entity', 'name' => 'Our page']);
    $this->assertSame('Open page', $literal->resolveItem($this->createUser(['access content']))?->label);
    // A literal type has an edit form but no canonical page.
    $typed = $this->createLiteral('a_type', 'literal_type:text', ['type' => 'entity']);
    $this->assertNull($typed->resolve($this->admin));
  }

  /**
   * A [user:...] token for an anonymous viewer resolves without leftovers.
   */
  public function testTokenForAnonymousViewer(): void {
    $literal = $this->createLiteral('greeting', 'Hello [user:name]', ['type' => 'token']);
    $value = $literal->resolve(new AnonymousUserSession());
    $this->assertIsString($value);
    $this->assertStringStartsWith('Hello', $value);
    $this->assertStringNotContainsString('[user:', $value);
  }

}
