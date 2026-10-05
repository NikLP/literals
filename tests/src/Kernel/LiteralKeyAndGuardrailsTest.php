<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralGuardrailsInterface;

/**
 * Tests key rules and the guardrail wiring on gist and value.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralKeyAndGuardrailsTest extends LiteralsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // A stand-in for literals_finder's runner: rejects "BAD", upper-cases
    // "shout", and records whether it was told to skip model guardrails.
    $container->register('literals.guardrails', FakeGuardrails::class);
  }

  /**
   * Builds an unsaved literal.
   */
  protected function build(array $values): Literal {
    return Literal::create($values + [
      'type' => 'text',
      'name' => 'n',
      'key' => 'k',
      'value' => 'v',
      'gist' => 'g',
      'audience' => 'anonymous',
    ]);
  }

  /**
   * Keys are unique, but a literal does not clash with itself.
   */
  public function testKeyUnique(): void {
    $first = $this->createLiteral('dup', '1');
    $this->assertCount(1, $this->build(['key' => 'dup'])->validate());
    $first->set('value', '2');
    $this->assertCount(0, $first->validate());
  }

  /**
   * Keys that collide with entity token names are refused.
   */
  public function testReservedKeys(): void {
    foreach (['url', 'name', 'value', 'gist', 'audience', 'original', 'id', 'uuid', 'status'] as $key) {
      $this->assertCount(1, $this->build(['key' => $key])->validate(), $key);
    }
    $this->assertCount(0, $this->build(['key' => 'main_phone'])->validate());
  }

  /**
   * A guardrail rejection of the gist is a field violation.
   */
  public function testGistRejected(): void {
    FakeGuardrails::$calls = [];
    $violations = $this->build(['gist' => 'BAD gist'])->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('gist', $violations[0]->getPropertyPath());
  }

  /**
   * A guardrail rejection of the value is a field violation.
   */
  public function testValueRejected(): void {
    $violations = $this->build(['value' => 'BAD value'])->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('value', $violations[0]->getPropertyPath());
  }

  /**
   * The value is checked deterministic-only; the gist is not.
   */
  public function testValueNeverGoesToModelGuardrails(): void {
    FakeGuardrails::$calls = [];
    $this->build(['gist' => 'one', 'value' => 'two'])->validate();
    $this->assertContains(['one', FALSE], FakeGuardrails::$calls);
    $this->assertContains(['two', TRUE], FakeGuardrails::$calls);
  }

  /**
   * A rewrite by a guardrail is what gets saved.
   */
  public function testRewriteSticks(): void {
    $literal = $this->build(['value' => 'shout']);
    $this->assertCount(0, $literal->validate());
    $this->assertSame('SHOUT', $literal->get('value')->value);
  }

  /**
   * Empty gist is allowed and skips the guardrails.
   */
  public function testEmptyGistSkipped(): void {
    FakeGuardrails::$calls = [];
    $this->assertCount(0, $this->build(['gist' => ''])->validate());
    $texts = array_column(FakeGuardrails::$calls, 0);
    $this->assertNotContains('', $texts);
  }

}

/**
 * Test double for the guardrail runner.
 */
class FakeGuardrails implements LiteralGuardrailsInterface {

  /**
   * Recorded calls: [text, deterministic_only].
   *
   * @var array
   */
  public static array $calls = [];

  /**
   * {@inheritdoc}
   */
  public function check(string $text, bool $deterministic_only): string {
    self::$calls[] = [$text, $deterministic_only];
    if (str_starts_with($text, 'BAD')) {
      throw new \InvalidArgumentException('Guardrail check failed: bad.');
    }
    return $text === 'shout' ? 'SHOUT' : $text;
  }

}
