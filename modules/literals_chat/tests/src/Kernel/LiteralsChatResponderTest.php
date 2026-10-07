<?php

declare(strict_types=1);

namespace Drupal\Tests\literals_chat\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\literals\Kernel\LiteralsKernelTestBase;
use Drupal\literals_finder\Finder\LiteralFinderInterface;
use Drupal\literals_finder\Finder\LiteralFindResult;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests the chat responder's templates around a stand-in finder.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralsChatResponderTest extends LiteralsKernelTestBase {

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
    'literals_chat',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('literals_finder.finder', FakeChatFinder::class)->setArguments([new Reference('entity_type.manager')]);
  }

  /**
   * A link match is a templated link with its label.
   */
  public function testLinkMatch(): void {
    $this->createLiteral('login', '/user/login', ['type' => 'url', 'name' => 'Sign in']);
    // The login page is anonymous-only, so ask as anonymous.
    $answer = $this->container->get('literals_chat.responder')->answer('login', new AnonymousUserSession());
    $this->assertSame('match', $answer['outcome']);
    $this->assertSame('Here you go:', $answer['message']);
    $this->assertSame('url', $answer['items'][0]['kind']);
    $this->assertSame('Sign in', $answer['items'][0]['label']);
    $this->assertStringEndsWith('/user/login', $answer['items'][0]['value']);
  }

  /**
   * A text match names the label and carries the exact value.
   */
  public function testTextMatch(): void {
    $this->createLiteral('phone_a', '111 1111', ['type' => 'phone', 'name' => 'Main phone']);
    $answer = $this->container->get('literals_chat.responder')->answer('phone_a', $this->member);
    $this->assertSame('Main phone:', $answer['message']);
    $this->assertSame('111 1111', $answer['items'][0]['value']);
  }

  /**
   * An ambiguous question lists every candidate that resolves.
   */
  public function testAmbiguousListsCandidates(): void {
    $this->createLiteral('phone_a', '111 1111', ['type' => 'phone']);
    $this->createLiteral('phone_b', '222 2222', ['type' => 'phone']);
    $answer = $this->container->get('literals_chat.responder')->answer('ambiguous', $this->member);
    $this->assertSame('ambiguous', $answer['outcome']);
    $this->assertCount(2, $answer['items']);
  }

  /**
   * Markdown carries safe links only and escapes stored text.
   */
  public function testMarkdown(): void {
    $responder = $this->container->get('literals_chat.responder');
    $md = $responder->toMarkdown([
      'outcome' => 'ambiguous',
      'message' => 'A few:',
      'items' => [
        ['label' => 'Main *phone*', 'kind' => 'phone', 'value' => '+44 1223 000000'],
        ['label' => 'Home', 'kind' => 'url', 'value' => 'https://example.com/a b'],
        ['label' => 'Evil', 'kind' => 'url', 'value' => 'javascript:alert(1)'],
        ['label' => 'Note', 'kind' => 'text', 'value' => '<script>x</script>'],
      ],
    ]);
    $this->assertStringContainsString('- Main \\*phone\\*: [\\+44 1223 000000](tel:+441223000000)', $md);
    $this->assertStringContainsString('- [Home](https://example.com/a%20b)', $md);
    $this->assertStringNotContainsString('](javascript', $md);
    $this->assertStringContainsString('- Evil: javascript:alert\\(1\\)', $md);
    $this->assertStringContainsString('\\<script\\>x\\</script\\>', $md);
  }

  /**
   * No match, or a match that cannot resolve for the asker, is a none.
   */
  public function testNone(): void {
    $responder = $this->container->get('literals_chat.responder');
    $answer = $responder->answer('something else', $this->member);
    $this->assertSame('none', $answer['outcome']);
    $this->assertSame([], $answer['items']);

    // A literal pointing at a path the asker cannot access.
    $this->createLiteral('admin_page', '/admin/config', ['type' => 'url']);
    $answer = $responder->answer('admin_page', $this->member);
    $this->assertSame('none', $answer['outcome'], 'A match the asker cannot resolve is a none');
    $this->assertSame([], $answer['items']);
  }

}

/**
 * Test double finder: the question text is the literal key (or "ambiguous").
 */
class FakeChatFinder implements LiteralFinderInterface {

  /**
   * Constructs the fake.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(protected $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public function find(string $question, ?AccountInterface $account = NULL, ?string $context = NULL): LiteralFindResult {
    $keys = $question === 'ambiguous' ? ['phone_a', 'phone_b'] : [$question];
    $storage = $this->entityTypeManager->getStorage('literal');
    $found = [];
    foreach ($keys as $key) {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('key', $key)->execute();
      if ($ids) {
        $found[] = $storage->load(reset($ids));
      }
    }
    if (!$found) {
      return new LiteralFindResult(LiteralFindResult::NONE, [], 'pool', 'fake');
    }
    return new LiteralFindResult(count($found) > 1 ? LiteralFindResult::AMBIGUOUS : LiteralFindResult::MATCH, $found, 'pool', 'fake');
  }

}
