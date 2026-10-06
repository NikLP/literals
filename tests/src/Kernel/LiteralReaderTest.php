<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Psr\Log\AbstractLogger;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AnonymousUserSession;

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
    $this->createLiteral('internal', '1', ['audience' => 'restricted']);
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
   * Changing the audience takes effect on the next read.
   */
  public function testAudienceChangeTakesEffect(): void {
    $literal = $this->createLiteral('moves', 'v');
    $anon = new AnonymousUserSession();
    $this->assertSame('v', $this->reader()->read('moves', $anon));
    $literal->set('audience', 'authenticated')->save();
    // Core's access handler memoizes results per entity for the request; a
    // real edit and read happen in different requests.
    $this->container->get('entity_type.manager')->getAccessControlHandler('literal')->resetCache();
    $this->assertNull($this->reader()->read('moves', $anon));
    $this->assertSame('v', $this->reader()->read('moves', $this->member));
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
    $this->assertContains('user.roles:authenticated', $metadata->getCacheContexts());
    $this->assertNotContains('user', $metadata->getCacheContexts(), 'Varies on permissions and sign-in, not per individual');

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

}
