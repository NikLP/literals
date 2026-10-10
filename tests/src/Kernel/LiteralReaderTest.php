<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AnonymousUserSession;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;

/**
 * Tests key lookups: published only, access checked, indistinguishable misses.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralReaderTest extends LiteralsKernelTestBase {

  /**
   * The reader under test.
   */
  protected function reader() {
    return $this->container->get('literals.reader');
  }

  /**
   * A readable literal returns its value.
   */
  public function testReadsPublishedLiteral(): void {
    $this->createLiteral('phone', '+44 1223 000000', ['type' => 'phone']);
    $this->assertSame('+44 1223 000000', $this->reader()->read('phone', new AnonymousUserSession()));
  }

  /**
   * Missing, unpublished and not-visible literals all read as NULL.
   */
  public function testMissesAreIndistinguishable(): void {
    $this->createLiteral('draft', '1', ['status' => 0]);
    $this->createLiteral('internal', '1', ['restricted' => TRUE]);
    $anon = new AnonymousUserSession();
    $this->assertNull($this->reader()->read('nosuchkey', $anon));
    $this->assertNull($this->reader()->read('draft', $anon));
    $this->assertNull($this->reader()->read('internal', $anon));
    $this->assertNull($this->reader()->read('internal', $this->member));
    $this->assertSame('1', $this->reader()->read('internal', $this->restrictedViewer));
  }

  /**
   * Even a user who can edit drafts gets only published values from read().
   */
  public function testDraftNeverServedEvenToAdmin(): void {
    $this->createLiteral('draft', 'secret draft', ['status' => 0]);
    $this->assertNull($this->reader()->read('draft', $this->admin));
  }

  /**
   * Unpublishing takes the literal away; republishing brings it back.
   */
  public function testUnpublishing(): void {
    $literal = $this->createLiteral('toggle', 'v1');
    $anon = new AnonymousUserSession();
    $this->assertSame('v1', $this->reader()->read('toggle', $anon));
    $literal->setUnpublished()->save();
    $this->assertNull($this->reader()->read('toggle', $anon));
    $literal->setPublished()->save();
    $this->assertSame('v1', $this->reader()->read('toggle', $anon));
  }

  /**
   * A pending draft revision does not replace the published value.
   */
  public function testDraftRevisionDoesNotLeak(): void {
    $literal = $this->createLiteral('versioned', 'published value');
    $draft = \Drupal::entityTypeManager()->getStorage('literal')->createRevision($literal, FALSE);
    $draft->set('value', 'draft value');
    $draft->setNewRevision(TRUE);
    $draft->isDefaultRevision(FALSE);
    $draft->save();
    $this->assertSame('published value', $this->reader()->read('versioned', new AnonymousUserSession()));
  }

  /**
   * Editing the value changes what is read (no stale cache in the reader).
   */
  public function testEditIsVisibleImmediately(): void {
    $literal = $this->createLiteral('edited', 'old');
    $anon = new AnonymousUserSession();
    $this->assertSame('old', $this->reader()->read('edited', $anon));
    $literal->set('value', 'new')->save();
    $this->assertSame('new', $this->reader()->read('edited', $anon));
  }

  /**
   * Restricting a literal takes effect on the next read.
   */
  public function testRestrictionChangeTakesEffect(): void {
    $literal = $this->createLiteral('moves', 'v');
    $anon = new AnonymousUserSession();
    $this->assertSame('v', $this->reader()->read('moves', $anon));
    $literal->set('restricted', TRUE)->save();
    // Core's access handler memoizes results per entity for the request; a
    // real edit and read happen in different requests.
    $this->container->get('entity_type.manager')->getAccessControlHandler('literal')->resetCache();
    $this->assertNull($this->reader()->read('moves', $anon));
    $this->assertNull($this->reader()->read('moves', $this->member));
    $this->assertSame('v', $this->reader()->read('moves', $this->restrictedViewer));
  }

  /**
   * Cache metadata carries the literal list tag, the entity and the user.
   */
  public function testCacheMetadata(): void {
    $literal = $this->createLiteral('cached', 'v');
    $metadata = new CacheableMetadata();
    $this->reader()->read('cached', $this->member, $metadata);
    $this->assertContains('literal_list', $metadata->getCacheTags());
    $this->assertContains('literal:' . $literal->id(), $metadata->getCacheTags());
    $this->assertContains('user.permissions', $metadata->getCacheContexts());
    $this->assertNotContains('user', $metadata->getCacheContexts(), 'Varies on permissions, not per individual');

    // A miss still bubbles the list tag, so creating the literal later
    // invalidates the cached "nothing here".
    $miss = new CacheableMetadata();
    $this->reader()->read('notyet', $this->member, $miss);
    $this->assertContains('literal_list', $miss->getCacheTags());
  }

  /**
   * Token literals that point at each other do not loop.
   */
  public function testCycleIsBroken(): void {
    $this->createLiteral('first', 'plain', ['type' => 'text']);
    // Reference known keys only (validation), then re-point to form a cycle.
    $a = $this->createLiteral('cycle_a', '[literal:first]', ['type' => 'token']);
    $this->createLiteral('cycle_b', '[literal:cycle_a]', ['type' => 'token']);
    $a->set('value', '[literal:cycle_b]')->save();
    $result = $this->reader()->read('cycle_a', new AnonymousUserSession());
    // The loop is cut: the inner read gives nothing, the outer returns text.
    $this->assertSame('', $result);
  }

  /**
   * A literal that references itself resolves to empty, not infinity.
   */
  public function testSelfReferenceIsBroken(): void {
    $a = $this->createLiteral('selfie', '[site:name]', ['type' => 'token']);
    $a->set('value', 'x [literal:selfie] y')->save();
    $this->assertSame('x  y', $this->reader()->read('selfie', new AnonymousUserSession()));
  }

  /**
   * The audit line never contains the value or gist.
   */
  public function testAuditLogsOmitValues(): void {
    $this->config('literals.settings')->set('log_audit', TRUE)->save();
    $logger = new class() extends AbstractLogger {

      /**
       * Collected messages with placeholders interpolated.
       *
       * @var string[]
       */
      public array $lines = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->lines[] = strtr((string) $message, array_map('strval', $context));
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);
    $this->createLiteral('audited', 'TOP-SECRET-VALUE', ['gist' => 'TOP-SECRET-GIST']);
    $this->reader()->read('audited', $this->member);
    $this->reader()->read('missing', $this->member);
    $joined = implode("\n", $logger->lines);
    $this->assertStringContainsString('audited', $joined);
    $this->assertStringNotContainsString('TOP-SECRET-VALUE', $joined);
    $this->assertStringNotContainsString('TOP-SECRET-GIST', $joined);
  }

  /**
   * Tokens in text become plain values or Markdown links, per viewer.
   */
  public function testReplaceTokens(): void {
    $this->createLiteral('phone', '+44 1223 000000', ['type' => 'phone']);
    $this->createLiteral('login', '/user/login', ['type' => 'url', 'name' => 'Sign in']);
    $this->createLiteral('internal', 'staff-only', ['restricted' => TRUE]);
    $anon = new AnonymousUserSession();
    $reader = $this->reader();

    $this->assertSame('Call +44 1223 000000.', $reader->replaceTokens('Call [literal:phone].', $anon));
    $this->assertSame('Call [+44 1223 000000](tel:+441223000000).', $reader->replaceTokens('Call [literal:phone:link].', $anon));
    $this->assertMatchesRegularExpression('#^\[Sign in\]\(http[^)]*/user/login\)$#', $reader->replaceTokens('[literal:login:link]', $anon));
    $this->assertSame('x [redacted] y [redacted]', $reader->replaceTokens('x [literal:internal] y [literal:nosuchkey]', $anon));
    $this->assertNull($reader->replaceTokens('x [literal:internal]', $anon, NULL, TRUE));
    $this->assertSame('x +44 1223 000000', $reader->replaceTokens('x [literal:phone]', $anon, NULL, TRUE));
    $this->assertSame('staff-only', $reader->replaceTokens('[literal:internal]', $this->restrictedViewer));
    $this->assertSame('no tokens [here]', $reader->replaceTokens('no tokens [here]', $anon));
  }

  /**
   * Found literals resolve for the account; unresolvable ones downgrade.
   */
  public function testResolveFound(): void {
    $a = $this->createLiteral('phone_a', '111 1111', ['type' => 'phone']);
    $b = $this->createLiteral('phone_b', '222 2222', ['type' => 'phone']);
    $page = $this->createPage('Draft', FALSE);
    $internal = $this->createLiteral('gone', 'node:' . $page->id(), ['type' => 'entity']);
    $anon = new AnonymousUserSession();
    $reader = $this->reader();

    $result = $reader->resolveFound('match', [$a, $b], $anon);
    $this->assertSame('match', $result['outcome']);
    $this->assertSame(['phone_a'], array_keys($result['items']));

    $result = $reader->resolveFound('ambiguous', [$a, $internal, $b], $anon);
    $this->assertSame('ambiguous', $result['outcome']);
    $this->assertSame(['phone_a', 'phone_b'], array_keys($result['items']));

    $result = $reader->resolveFound('match', [$internal], $anon);
    $this->assertSame('none', $result['outcome']);
    $this->assertSame([], $result['items']);
  }

}
