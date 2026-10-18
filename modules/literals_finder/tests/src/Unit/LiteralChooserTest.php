<?php

declare(strict_types=1);

namespace Drupal\Tests\literals_finder\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\literals\Entity\Literal;
use Drupal\literals_finder\Finder\LiteralChooser;
use Drupal\literals_finder\Finder\LiteralFindResult;

/**
 * Tests how the chooser turns a probability spread into a result.
 *
 * @group literals_finder
 */
class LiteralChooserTest extends UnitTestCase {

  /**
   * Interprets a spread at the default threshold (0.5) and margin (0.2).
   *
   * @param float[] $probabilities
   *   Probabilities keyed by literal key, plus "__none__".
   *
   * @return \Drupal\literals_finder\Finder\LiteralFindResult
   *   The result.
   */
  protected function interpret(array $probabilities): LiteralFindResult {
    // The constructor only wires services interpret() does not touch.
    $chooser = (new \ReflectionClass(LiteralChooser::class))->newInstanceWithoutConstructor();
    $by_key = [];
    foreach (array_keys($probabilities) as $key) {
      if ($key !== '__none__') {
        $by_key[$key] = $this->createMock(Literal::class);
      }
    }
    return $chooser->interpret($probabilities, $by_key, 0.5, 0.2);
  }

  /**
   * A flat spread under the threshold is none, not the whole pool.
   */
  public function testFlatSpreadIsLowConfidence(): void {
    $probabilities = ['__none__' => 0.09, 'pl_ill_phone' => 0.2, 'accounts_phone' => 0.14];
    for ($i = 0; $i < 60; $i++) {
      $probabilities["lit_$i"] = 0.57 / 60;
    }
    $result = $this->interpret($probabilities);
    $this->assertSame(LiteralFindResult::NONE, $result->outcome);
    $this->assertSame('low_confidence', $result->reason);
    $this->assertSame([], $result->literals);
  }

  /**
   * Two contenders that both clear the threshold stay ambiguous.
   */
  public function testTwoStrongContendersAreAmbiguous(): void {
    $result = $this->interpret(['main_phone' => 0.5, 'staff_line' => 0.45, 'other' => 0.03, '__none__' => 0.02]);
    $this->assertSame(LiteralFindResult::AMBIGUOUS, $result->outcome);
    $this->assertCount(1, $result->literals);
  }

  /**
   * Ambiguity lists only contenders above the threshold.
   */
  public function testAmbiguousListExcludesWeakLiterals(): void {
    $result = $this->interpret(['a' => 0.55, 'b' => 0.5, 'c' => 0.4, '__none__' => 0.05]);
    $this->assertSame(LiteralFindResult::AMBIGUOUS, $result->outcome);
    $this->assertCount(2, $result->literals);
  }

  /**
   * A clear lead over the threshold is a match.
   */
  public function testClearLeadIsMatch(): void {
    $result = $this->interpret(['main_phone' => 0.8, 'staff_line' => 0.1, '__none__' => 0.1]);
    $this->assertSame(LiteralFindResult::MATCH, $result->outcome);
  }

  /**
   * A top "none" is chose_none.
   */
  public function testNoneTopIsChoseNone(): void {
    $result = $this->interpret(['__none__' => 0.7, 'main_phone' => 0.3]);
    $this->assertSame('chose_none', $result->reason);
  }

}
