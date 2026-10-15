<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\literals\LiteralLookup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A finder that cannot run is reported as unavailable, not as "none".
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralLookupUnavailableTest extends LiteralsKernelTestBase {

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
   * Builds a lookup whose finder returns the given outcome and reason.
   */
  protected function lookupWith(string $outcome, string $reason): LiteralLookup {
    $finder = new class($outcome, $reason) {

      /**
       * Constructs the fake finder.
       *
       * @param string $outcome
       *   The outcome to return.
       * @param string $reason
       *   The reason to return.
       */
      public function __construct(protected string $outcome, protected string $reason) {}

      /**
       * Returns the canned result.
       *
       * @param string $question
       *   The question.
       * @param mixed $account
       *   The account.
       *
       * @return object
       *   The result.
       */
      public function find(string $question, $account): object {
        return (object) ['outcome' => $this->outcome, 'literals' => [], 'reason' => $this->reason];
      }

    };
    return new LiteralLookup(
      $this->container->get('literals.reader'),
      $this->container->get('literals.search'),
      new AnonymousUserSession(),
      $finder,
    );
  }

  /**
   * Finder errors and a missing backend are unavailable; a real none is none.
   */
  public function testUnavailableIsNotNone(): void {
    $account = new AnonymousUserSession();
    foreach (['error', 'no_backend'] as $reason) {
      $result = $this->lookupWith('none', $reason)->lookup(['question' => 'main phone?'], $account);
      $this->assertTrue($result['success']);
      $this->assertSame('unavailable', $result['values']['outcome'], $reason);
    }
    foreach (['chose_none', 'low_confidence', ''] as $reason) {
      $result = $this->lookupWith('none', $reason)->lookup(['question' => 'main phone?'], $account);
      $this->assertSame('none', $result['values']['outcome'], $reason);
    }
  }

}
