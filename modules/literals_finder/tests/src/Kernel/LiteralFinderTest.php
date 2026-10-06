<?php

declare(strict_types=1);

namespace Drupal\Tests\literals_finder\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\literals\Kernel\LiteralsKernelTestBase;
use Drupal\literals_finder\Finder\LiteralChooserInterface;
use Drupal\literals_finder\Finder\LiteralFindResult;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the finder around a stand-in chooser: cache, access, context, errors.
 *
 * The real chooser (a Decision API model) is covered by drush literals:eval.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralFinderTest extends LiteralsKernelTestBase {

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
    'ai',
    'literals',
    'literals_finder',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Not installConfig(): the module's guardrail configs hit an upstream
    // schema gap (check_all_messages), and only the settings matter here.
    $this->config('literals_finder.settings')->setData([
      'match_threshold' => 0.5,
      'choice_margin' => 0.2,
      'miss_ttl' => 300,
      'log_audit' => TRUE,
      'chooser_context' => '',
    ])->save();
    FakeChooser::reset();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('literals_finder.chooser', FakeChooser::class);
  }

  /**
   * The finder under test.
   *
   * @return \Drupal\literals_finder\Finder\LiteralFinderInterface
   *   The finder.
   */
  protected function finder() {
    return $this->container->get('literals_finder.finder');
  }

  /**
   * No decision model is "none" with reason no_backend, and never cached.
   */
  public function testNoBackendIsNotCached(): void {
    $this->createLiteral('main_phone', '111 1111', ['type' => 'phone']);
    FakeChooser::$available = FALSE;
    $result = $this->finder()->find('phone number', $this->member);
    $this->assertSame('none', $result->outcome);
    $this->assertSame('no_backend', $result->reason);
    $this->assertSame(0, FakeChooser::$calls);

    FakeChooser::$available = TRUE;
    FakeChooser::$answer = ['match', ['main_phone']];
    $result = $this->finder()->find('phone number', $this->member);
    $this->assertSame('match', $result->outcome, 'The earlier no_backend answer was not cached');
    $this->assertSame(1, FakeChooser::$calls);
  }

  /**
   * An empty question or an empty menu never reaches the chooser.
   */
  public function testNoCandidates(): void {
    $result = $this->finder()->find('phone number', $this->member);
    $this->assertSame('no_candidates', $result->reason);
    $this->createLiteral('main_phone', '111 1111', ['type' => 'phone']);
    $result = $this->finder()->find('   ', $this->member);
    $this->assertSame('no_candidates', $result->reason);
    // A literal with no gist is not on the menu.
    $this->createLiteral('quiet', 'x', ['gist' => '']);
    $this->assertSame(0, FakeChooser::$calls);
  }

  /**
   * A repeat of a question (case and spacing aside) is served from the cache.
   */
  public function testOutcomeCache(): void {
    $this->createLiteral('main_phone', '111 1111', ['type' => 'phone']);
    FakeChooser::$answer = ['match', ['main_phone']];

    $first = $this->finder()->find('Phone  number', $this->member);
    $this->assertSame('chooser', $first->tier);
    $second = $this->finder()->find('phone number', $this->member);
    $this->assertSame('cache', $second->tier);
    $this->assertSame('match', $second->outcome);
    $this->assertSame('main_phone', $second->literals[0]->get('key')->value);
    $this->assertSame(1, FakeChooser::$calls);
  }

  /**
   * Saving a literal drops cached outcomes, so a stale "none" cannot stick.
   */
  public function testCacheClearsOnLiteralSave(): void {
    $this->createLiteral('main_phone', '111 1111', ['type' => 'phone']);
    FakeChooser::$answer = ['none', []];
    $this->assertSame('none', $this->finder()->find('fax number', $this->member)->outcome);
    $this->assertSame('cache', $this->finder()->find('fax number', $this->member)->tier);

    $this->createLiteral('fax', '222 2222', ['type' => 'phone']);
    FakeChooser::$answer = ['match', ['fax']];
    $result = $this->finder()->find('fax number', $this->member);
    $this->assertSame('chooser', $result->tier);
    $this->assertSame('match', $result->outcome);
  }

  /**
   * Changed settings, a changed site context or another call context re-ask.
   */
  public function testCacheFingerprint(): void {
    $this->createLiteral('main_phone', '111 1111', ['type' => 'phone']);
    FakeChooser::$answer = ['match', ['main_phone']];
    $this->finder()->find('your phone', $this->member);
    $this->assertSame(1, FakeChooser::$calls);

    $this->finder()->find('your phone', $this->member);
    $this->assertSame(1, FakeChooser::$calls, 'Same ask: cached');

    $this->finder()->find('your phone', $this->member, 'Staff are asking.');
    $this->assertSame(2, FakeChooser::$calls, 'A call context is a different ask');
    $this->assertSame('Staff are asking.', FakeChooser::$lastContext);
    $this->finder()->find('your phone', $this->member, 'Staff are asking.');
    $this->assertSame(2, FakeChooser::$calls, 'The same call context is cached');

    $this->config('literals_finder.settings')->set('chooser_context', 'Asked of the library.')->save();
    $this->finder()->find('your phone', $this->member);
    $this->assertSame(3, FakeChooser::$calls, 'A changed site context is not served stale');

    $this->config('literals_finder.settings')->set('match_threshold', 0.9)->save();
    $this->finder()->find('your phone', $this->member);
    $this->assertSame(4, FakeChooser::$calls, 'A changed threshold is not served stale');

    // The audit switch is not part of the outcome.
    $this->config('literals_finder.settings')->set('log_audit', FALSE)->save();
    $this->finder()->find('your phone', $this->member);
    $this->assertSame(4, FakeChooser::$calls);
  }

  /**
   * The chooser sees only what the asker may view, and caches do not cross.
   */
  public function testAudienceFilterAndCacheSeparation(): void {
    $this->createLiteral('main_phone', '111 1111', ['type' => 'phone']);
    $this->createLiteral('staff_line', '222 2222', ['type' => 'phone', 'audience' => 'restricted']);
    $this->createLiteral('draft', 'x', ['status' => 0]);
    FakeChooser::$answer = ['ambiguous', ['main_phone', 'staff_line']];

    $result = $this->finder()->find('phone', $this->member);
    $this->assertSame(['main_phone'], FakeChooser::$lastKeys);
    $this->assertSame(['main_phone'], array_map(fn ($l) => $l->get('key')->value, $result->literals));

    $result = $this->finder()->find('phone', $this->restrictedViewer);
    $this->assertSame(['main_phone', 'staff_line'], FakeChooser::$lastKeys);
    $this->assertSame('chooser', $result->tier, 'The member answer was not served to a restricted viewer');
    $this->assertCount(2, $result->literals);

    $result = $this->finder()->find('phone', $this->member);
    $this->assertSame('cache', $result->tier);
    $this->assertCount(1, $result->literals, 'The restricted literal never leaks into the member answer');
  }

  /**
   * A model failure is reported as none/error and never cached.
   */
  public function testChooserFailureIsNotCached(): void {
    $this->createLiteral('main_phone', '111 1111', ['type' => 'phone']);
    FakeChooser::$throw = TRUE;
    $result = $this->finder()->find('phone', $this->member);
    $this->assertSame('none', $result->outcome);
    $this->assertSame('error', $result->reason);

    FakeChooser::$throw = FALSE;
    FakeChooser::$answer = ['match', ['main_phone']];
    $result = $this->finder()->find('phone', $this->member);
    $this->assertSame('match', $result->outcome);
    $this->assertSame(2, FakeChooser::$calls);
  }

}

/**
 * Stand-in chooser with canned answers and call recording.
 */
class FakeChooser implements LiteralChooserInterface {

  /**
   * Whether a decision model is available.
   */
  public static bool $available = TRUE;

  /**
   * Whether choose() throws.
   */
  public static bool $throw = FALSE;

  /**
   * How many times choose() ran.
   */
  public static int $calls = 0;

  /**
   * The context of the last call.
   */
  public static ?string $lastContext = NULL;

  /**
   * The candidate keys of the last call.
   */
  public static array $lastKeys = [];

  /**
   * The canned answer: [outcome, keys the answer names].
   */
  public static array $answer = ['none', []];

  /**
   * Resets the recorded state.
   */
  public static function reset(): void {
    self::$available = TRUE;
    self::$throw = FALSE;
    self::$calls = 0;
    self::$lastContext = NULL;
    self::$lastKeys = [];
    self::$answer = ['none', []];
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return self::$available;
  }

  /**
   * {@inheritdoc}
   */
  public function choose(string $question, array $candidates, ?string $context = NULL): LiteralFindResult {
    self::$calls++;
    self::$lastContext = $context;
    self::$lastKeys = array_values(array_map(fn ($l) => (string) $l->get('key')->value, $candidates));
    if (self::$throw) {
      throw new \RuntimeException('model down');
    }
    [$outcome, $keys] = self::$answer;
    // Like the real chooser, name only literals it was shown.
    $named = array_values(array_filter($candidates, fn ($l) => in_array((string) $l->get('key')->value, $keys, TRUE)));
    return new LiteralFindResult($outcome, $named, 'chooser');
  }

}
