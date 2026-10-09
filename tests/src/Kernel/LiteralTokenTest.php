<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\literals\Entity\Literal;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the [literal:key] token.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralTokenTest extends LiteralsKernelTestBase {

  /**
   * Replaces tokens in text as the current user.
   */
  protected function replace(string $text, ?BubbleableMetadata $metadata = NULL): string {
    return (string) $this->container->get('token')->replace($text, [], ['clear' => TRUE], $metadata);
  }

  /**
   * A readable literal replaces; an unknown key clears.
   */
  public function testReplacesAndClears(): void {
    $this->createLiteral('main_phone', '+44 1223 000000', ['type' => 'phone']);
    $this->setCurrentUser(new AnonymousUserSession());
    $this->assertSame('Call +44 1223 000000.', $this->replace('Call [literal:main_phone].'));
    $this->assertSame('Call .', $this->replace('Call [literal:nosuch].'));
  }

  /**
   * The :link form is an HTML link for url, phone and email, text otherwise.
   */
  public function testLinkForm(): void {
    $this->createLiteral('main_phone', '+44 1223 000000', ['type' => 'phone']);
    $this->createLiteral('contact', 'help@example.com', ['type' => 'text', 'name' => 'Help']);
    $this->createLiteral('login', '/user/login', ['type' => 'url', 'name' => 'Sign <in>']);
    $this->createLiteral('hours', 'Mon-Fri <9>-17', ['name' => 'Hours']);
    $this->setCurrentUser(new AnonymousUserSession());
    $this->assertSame('<a href="tel:+441223000000">+44 1223 000000</a>', $this->replace('[literal:main_phone:link]'));
    $this->assertSame('Mon-Fri &lt;9&gt;-17', $this->replace('[literal:hours:link]'), 'Text has no link and is escaped');
    $this->assertSame('+44 1223 000000', $this->replace('[literal:main_phone]'), 'The bare form is unchanged');
    $login = $this->replace('[literal:login:link]');
    $this->assertStringStartsWith('<a href="http', $login);
    $this->assertStringContainsString('>Sign &lt;in&gt;</a>', $login);
    $this->assertSame('', $this->replace('[literal:main_phone:bogus]'), 'An unknown form clears');
    $this->assertSame('', $this->replace('[literal:nosuch:link]'));
  }

  /**
   * The :link form respects audience and bubbles cache metadata.
   */
  public function testLinkFormRespectsAudience(): void {
    $this->createLiteral('members', '+44 1111 000000', ['type' => 'phone', 'audience' => 'authenticated']);
    $this->setCurrentUser(new AnonymousUserSession());
    $metadata = new BubbleableMetadata();
    $this->assertSame('', $this->replace('[literal:members:link]', $metadata));
    $this->setCurrentUser($this->member);
    $this->assertSame('<a href="tel:+441111000000">+44 1111 000000</a>', $this->replace('[literal:members:link]'));
  }

  /**
   * The token browser lists the :link form.
   */
  public function testTokenInfoListsLinkForm(): void {
    $this->createLiteral('main_phone', '+44 1223 000000', ['type' => 'phone']);
    $info = $this->container->get('token')->getInfo()['tokens']['literal'];
    $this->assertArrayHasKey('main_phone:link', $info);
  }

  /**
   * The token never reveals a literal the viewer cannot see.
   */
  public function testRespectsAudience(): void {
    $this->createLiteral('internal', 'internal-only', ['audience' => 'restricted']);
    $this->createLiteral('members', 'members-only', ['audience' => 'authenticated']);
    $text = '[literal:internal]|[literal:members]';
    $this->setCurrentUser(new AnonymousUserSession());
    $this->assertSame('|', $this->replace($text));
    $this->setCurrentUser($this->member);
    $this->assertSame('|members-only', $this->replace($text));
    $this->setCurrentUser($this->restrictedViewer);
    $this->assertSame('internal-only|members-only', $this->replace($text));
  }

  /**
   * Drafts are never served through the token.
   */
  public function testDraftNotServed(): void {
    $this->createLiteral('draft', 'draft-value', ['status' => 0]);
    $this->setCurrentUser($this->admin);
    $this->assertSame('', $this->replace('[literal:draft]'));
  }

  /**
   * Bubbleable metadata names what the replacement depended on.
   */
  public function testBubblesCacheMetadata(): void {
    $literal = $this->createLiteral('main_phone', '1', ['audience' => 'authenticated']);
    $this->setCurrentUser($this->member);
    $metadata = new BubbleableMetadata();
    $this->replace('[literal:main_phone]', $metadata);
    $this->assertContains('literal:' . $literal->id(), $metadata->getCacheTags());
    $this->assertContains('literal_list', $metadata->getCacheTags());
    $this->assertContains('user.permissions', $metadata->getCacheContexts());
    $this->assertContains('user.roles:authenticated', $metadata->getCacheContexts());
    $this->assertNotContains('user', $metadata->getCacheContexts());
  }

  /**
   * A token literal embedding another literal resolves it for the asker.
   */
  public function testNestedTokenUsesTheAskedAccount(): void {
    $this->createLiteral('members_phone', '555-MEMBERS', ['audience' => 'authenticated']);
    $this->createLiteral('banner', 'Ring [literal:members_phone]', ['type' => 'token', 'audience' => 'anonymous']);
    // The session user is a member, but the banner is read for anonymous: the
    // inner literal must not leak through the session user's access.
    $this->setCurrentUser($this->member);
    $reader = $this->container->get('literals.reader');
    $this->assertSame('Ring ', $reader->read('banner', new AnonymousUserSession()));
    $this->assertSame('Ring 555-MEMBERS', $reader->read('banner', $this->member));
  }

  /**
   * Token info lists published literals only, and follows saves and deletes.
   */
  public function testTokenInfoFollowsLiterals(): void {
    $token = $this->container->get('token');
    $this->assertArrayNotHasKey('later', $token->getInfo()['tokens']['literal'] ?? []);
    $literal = $this->createLiteral('later', '1');
    $this->createLiteral('hidden_draft', '1', ['status' => 0]);
    $info = $token->getInfo()['tokens']['literal'];
    $this->assertArrayHasKey('later', $info);
    $this->assertArrayNotHasKey('hidden_draft', $info);
    $literal->delete();
    $this->assertArrayNotHasKey('later', $token->getInfo()['tokens']['literal'] ?? []);
  }

  /**
   * A token literal may reference an existing key but not a made-up one.
   */
  public function testTokenLiteralValidatesReferencedKeys(): void {
    $this->createLiteral('real', 'x');
    $base = ['type' => 'token', 'audience' => 'anonymous'];
    $ok = Literal::create($base + ['name' => 'a', 'key' => 'ref_ok', 'value' => '[literal:real]']);
    $this->assertCount(0, $ok->validate());
    $bad = Literal::create($base + ['name' => 'b', 'key' => 'ref_bad', 'value' => '[literal:imaginary]']);
    $this->assertGreaterThan(0, count($bad->validate()));
  }

}
