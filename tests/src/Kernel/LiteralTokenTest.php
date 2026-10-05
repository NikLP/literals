<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\literals\Entity\Literal;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AnonymousUserSession;

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
    $this->assertContains('user', $metadata->getCacheContexts());
  }

  /**
   * A token literal embedding another literal resolves it for the asked account.
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
   * A token literal may reference an existing literal key but not a made-up one.
   */
  public function testTokenLiteralValidatesReferencedKeys(): void {
    $this->createLiteral('real', 'x');
    $ok = Literal::create(['type' => 'token', 'name' => 'a', 'key' => 'ref_ok', 'value' => '[literal:real]', 'audience' => 'anonymous']);
    $this->assertCount(0, $ok->validate());
    $bad = Literal::create(['type' => 'token', 'name' => 'b', 'key' => 'ref_bad', 'value' => '[literal:imaginary]', 'audience' => 'anonymous']);
    $this->assertGreaterThan(0, count($bad->validate()));
  }

}
