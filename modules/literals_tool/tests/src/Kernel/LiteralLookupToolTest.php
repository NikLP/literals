<?php

declare(strict_types=1);

namespace Drupal\Tests\literals_tool\Kernel;

use Symfony\Component\DependencyInjection\Reference;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\literals\Kernel\LiteralsKernelTestBase;
use Drupal\literals\Entity\Literal;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the literals:lookup tool: access, key mode, question mode.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralLookupToolTest extends LiteralsKernelTestBase {

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
    'tool',
    'literals_search',
    'literals_tool',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // A stand-in finder: "ambiguous" returns two phones, "phone" one, any
    // other question nothing. Only literals the asker can view are returned,
    // as the real finder guarantees.
    $container->register('literals_finder.finder', FakeFinder::class)->setArguments([new Reference('entity_type.manager')]);
  }

  /**
   * Runs the tool as an account.
   *
   * @param array $input
   *   Tool input.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The caller.
   *
   * @return array
   *   [success, message, values].
   */
  protected function runTool(array $input, AccountInterface $account): array {
    $this->setCurrentUser($account);
    $tool = $this->container->get('plugin.manager.tool')->createInstance('literals:lookup');
    foreach ($input as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $result = $tool->execute()->getResult();
    return [$result->isSuccess(), (string) $result->getMessage(), $result->getContextValues()];
  }

  /**
   * The tool needs its permission.
   */
  public function testAccess(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('literals:lookup');
    $this->assertFalse($tool->access($this->member));
    $this->assertTrue($tool->access($this->createUser(['use literal lookup tool'])));
  }

  /**
   * Key mode returns the value, or the same "none" for every kind of miss.
   */
  public function testKeyMode(): void {
    $this->createLiteral('main_phone', '+44 1223 000000', ['type' => 'phone']);
    $this->createLiteral('internal', 'staff-only', ['audience' => 'restricted']);
    $this->createLiteral('draft', 'draft', ['status' => 0]);
    $caller = $this->createUser(['use literal lookup tool']);

    [$ok, , $values] = $this->runTool(['key' => 'main_phone'], $caller);
    $this->assertTrue($ok);
    $this->assertSame(['outcome' => 'match', 'key' => 'main_phone', 'candidates' => '', 'value' => '+44 1223 000000'], $values);

    $misses = [];
    foreach (['nosuchkey', 'internal', 'draft'] as $key) {
      $misses[$key] = $this->runTool(['key' => $key], $caller);
      $this->assertSame('none', $misses[$key][2]['outcome'], $key);
      $this->assertSame('', $misses[$key][2]['value'], $key);
    }
    $this->assertSame($misses['nosuchkey'], $misses['internal'], 'Missing and not visible look the same');
    $this->assertSame($misses['nosuchkey'], $misses['draft']);
  }

  /**
   * A caller with the restricted permission does get the restricted value.
   */
  public function testKeyModeRestrictedCaller(): void {
    $this->createLiteral('internal', 'staff-only', ['audience' => 'restricted']);
    $caller = $this->createUser(['use literal lookup tool', 'view restricted literals']);
    [, , $values] = $this->runTool(['key' => 'internal'], $caller);
    $this->assertSame('staff-only', $values['value']);
  }

  /**
   * No input is a failure; a key takes precedence over a question.
   */
  public function testInputRules(): void {
    $this->createLiteral('main_phone', '111 1111', ['type' => 'phone']);
    $caller = $this->createUser(['use literal lookup tool']);
    [$ok] = $this->runTool([], $caller);
    $this->assertFalse($ok);
    [, , $values] = $this->runTool(['key' => 'main_phone', 'question' => 'ambiguous'], $caller);
    $this->assertSame('match', $values['outcome']);
    $this->assertSame('main_phone', $values['key']);
  }

  /**
   * Question mode: match, ambiguous, none.
   */
  public function testQuestionMode(): void {
    $this->createLiteral('phone_a', '111 1111', ['type' => 'phone']);
    $this->createLiteral('phone_b', '222 2222', ['type' => 'phone']);
    $caller = $this->createUser(['use literal lookup tool']);

    [, , $values] = $this->runTool(['question' => 'phone'], $caller);
    $this->assertSame(['outcome' => 'match', 'key' => 'phone_a', 'candidates' => '', 'value' => '111 1111'], $values);

    [, $message, $values] = $this->runTool(['question' => 'ambiguous'], $caller);
    $this->assertSame('ambiguous', $values['outcome']);
    $this->assertSame('phone_a,phone_b', $values['key']);
    $this->assertSame('', $values['value'], 'No value is returned for an ambiguous question');
    $this->assertStringNotContainsString('111 1111', $message);
    $this->assertStringNotContainsString('111 1111', $values['candidates']);
    $this->assertStringContainsString('phone_a: Phone_a', $values['candidates']);
    $this->assertStringContainsString('phone_b: Phone_b', $values['candidates']);

    [, , $values] = $this->runTool(['question' => 'gibberish'], $caller);
    $this->assertSame('none', $values['outcome']);
  }

  /**
   * A matched literal whose value cannot resolve for the caller is "none".
   */
  public function testQuestionMatchThatCannotResolve(): void {
    $this->createLiteral('phone_a', '111 1111', ['type' => 'phone']);
    $page = $this->createPage('Draft', FALSE);
    $this->createLiteral('gone', 'node:' . $page->id(), ['type' => 'entity']);
    $caller = $this->createUser(['use literal lookup tool', 'access content']);
    [, , $values] = $this->runTool(['question' => 'unresolvable'], $caller);
    $this->assertSame('none', $values['outcome']);
    $this->assertSame('', $values['value']);
  }

  /**
   * Search mode lists candidates (key, name, gist), never values.
   */
  public function testSearchMode(): void {
    $this->createLiteral('phone_a', '111 1111', ['type' => 'phone', 'name' => 'Main phone', 'gist' => 'The switchboard']);
    $this->createLiteral('internal_line', '222 2222', [
      'type' => 'phone',
      'name' => 'Staff phone',
      'audience' => 'restricted',
    ]);
    $caller = $this->createUser(['use literal lookup tool']);

    [$ok, , $values] = $this->runTool(['search' => 'phone'], $caller);
    $this->assertTrue($ok);
    $this->assertSame('candidates', $values['outcome']);
    $this->assertSame('phone_a', $values['key'], 'The restricted literal is not offered');
    $this->assertSame("phone_a: Main phone - The switchboard", $values['candidates']);
    $this->assertSame('', $values['value']);
    $this->assertStringNotContainsString('111 1111', json_encode($values));

    [, , $values] = $this->runTool(['search' => 'zeppelin'], $caller);
    $this->assertSame('none', $values['outcome']);

    $restricted = $this->createUser(['use literal lookup tool', 'view restricted literals']);
    [, , $values] = $this->runTool(['search' => 'phone'], $restricted);
    $this->assertSame('phone_a,internal_line', $values['key'], 'Name order: Main phone, then Staff phone');
  }

  /**
   * The admin-set context reaches the finder; a caller cannot supply one.
   */
  public function testQuestionContextIsAdminSet(): void {
    $this->createLiteral('phone_a', '111 1111', ['type' => 'phone']);
    $caller = $this->createUser(['use literal lookup tool']);

    $this->runTool(['question' => 'phone'], $caller);
    $this->assertNull(FakeFinder::$lastContext, 'No setting: the finder uses its own site context');

    $this->config('literals_tool.settings')->set('question_context', "  Staff of the library are asking.  ")->save();
    $this->runTool(['question' => 'phone'], $caller);
    $this->assertSame('Staff of the library are asking.', FakeFinder::$lastContext);

    $definition = $this->container->get('plugin.manager.tool')->getDefinition('literals:lookup');
    $this->assertArrayNotHasKey('context', $definition->getInputDefinitions());
  }

  /**
   * Key beats question beats search.
   */
  public function testModePrecedence(): void {
    $this->createLiteral('phone_a', '111 1111', ['type' => 'phone']);
    $caller = $this->createUser(['use literal lookup tool']);
    [, , $values] = $this->runTool(['key' => 'phone_a', 'question' => 'ambiguous', 'search' => 'phone'], $caller);
    $this->assertSame('match', $values['outcome']);
    [, , $values] = $this->runTool(['question' => 'ambiguous', 'search' => 'phone'], $caller);
    $this->assertSame('ambiguous', $values['outcome']);
  }

}

/**
 * Test double finder keyed on the question text.
 */
class FakeFinder {

  /**
   * Constructs the fake.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(protected $entityTypeManager) {}

  /**
   * The context of the most recent call.
   */
  public static ?string $lastContext = NULL;

  /**
   * Finds literals by canned question.
   */
  public function find(string $question, ?AccountInterface $account = NULL, ?string $context = NULL): object {
    self::$lastContext = $context;
    $by_key = function (array $keys) use ($account): array {
      $found = [];
      foreach ($keys as $key) {
        $ids = $this->entityTypeManager->getStorage('literal')->getQuery()->accessCheck(FALSE)->condition('key', $key)->execute();
        $literal = $ids ? $this->entityTypeManager->getStorage('literal')->load(reset($ids)) : NULL;
        if ($literal instanceof Literal && $literal->access('view', $account)) {
          $found[] = $literal;
        }
      }
      return $found;
    };
    return match ($question) {
      'phone' => (object) ['outcome' => 'match', 'literals' => $by_key(['phone_a'])],
      'ambiguous' => (object) ['outcome' => 'ambiguous', 'literals' => $by_key(['phone_a', 'phone_b'])],
      'unresolvable' => (object) ['outcome' => 'match', 'literals' => $by_key(['gone'])],
      default => (object) ['outcome' => 'none', 'literals' => []],
    };
  }

}
