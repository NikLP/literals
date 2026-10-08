<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests plain literal search.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralSearchTest extends LiteralsKernelTestBase {

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
   * Runs a search and returns the matching keys, sorted.
   */
  protected function keys(string $text, $account = NULL, int $limit = 10): array {
    $found = $this->container->get('literals.search')->search($text, $account ?? new AnonymousUserSession(), $limit);
    $keys = array_map(fn ($l) => $l->get('key')->value, array_values($found));
    sort($keys);
    return $keys;
  }

  /**
   * Words match the name, the key or the gist, case-insensitively.
   */
  public function testMatchesNameKeyAndGist(): void {
    $this->createLiteral('main_phone', '+44 1223 000000', [
      'type' => 'phone',
      'name' => 'Main phone',
      'gist' => 'The library switchboard',
    ]);
    $this->createLiteral('hours', 'Mon-Fri', ['name' => 'Opening hours', 'gist' => 'When the library is open']);
    $this->assertSame(['main_phone'], $this->keys('MAIN'), 'name');
    $this->assertSame(['main_phone'], $this->keys('switchboard'), 'gist');
    $this->assertSame(['hours'], $this->keys('hours'), 'key and name');
    $this->assertSame(['hours', 'main_phone'], $this->keys('library'));
    $this->assertSame([], $this->keys('zeppelin'));
  }

  /**
   * Every word must match (somewhere); word order does not matter.
   */
  public function testAllWordsMustMatch(): void {
    $this->createLiteral('main_phone', '+44 1223 000000', [
      'type' => 'phone',
      'name' => 'Main phone',
      'gist' => 'The library switchboard',
    ]);
    $this->createLiteral('hours', 'Mon-Fri', ['name' => 'Opening hours', 'gist' => 'When the library is open']);
    $this->assertSame(['main_phone'], $this->keys('library phone'));
    $this->assertSame(['main_phone'], $this->keys('phone library'));
    $this->assertSame([], $this->keys('phone hours'));
  }

  /**
   * Blank text, a zero limit, and wildcard characters never match everything.
   */
  public function testEdgeInputs(): void {
    $this->createLiteral('one', '1', ['name' => 'One']);
    $this->createLiteral('two', '2', ['name' => 'Two']);
    $this->assertSame([], $this->keys(''));
    $this->assertSame([], $this->keys('   '));
    $this->assertSame([], $this->keys('one', NULL, 0));
    $this->assertSame([], $this->keys('%'), 'A LIKE wildcard is a literal character');
    $this->assertSame([], $this->keys('_'), 'A LIKE wildcard is a literal character');
  }

  /**
   * Only what the searcher may view comes back, drafts never.
   */
  public function testAccessAndDrafts(): void {
    $this->createLiteral('pub_x', '1', ['audience' => 'anonymous', 'name' => 'Item pub']);
    $this->createLiteral('auth_x', '1', ['audience' => 'authenticated', 'name' => 'Item auth']);
    $this->createLiteral('res_x', '1', ['audience' => 'restricted', 'name' => 'Item res']);
    $this->createLiteral('draft_x', '1', ['status' => 0, 'name' => 'Item draft']);
    $this->assertSame(['pub_x'], $this->keys('item'));
    $this->assertSame(['auth_x', 'pub_x'], $this->keys('item', $this->member));
    $this->assertSame(['auth_x', 'pub_x', 'res_x'], $this->keys('item', $this->restrictedViewer));
    $this->assertSame(['auth_x', 'pub_x', 'res_x'], $this->keys('item', $this->admin), 'Drafts are not searched, even by an admin');
  }

  /**
   * The limit caps the results; they come back in name order.
   */
  public function testLimitAndOrder(): void {
    foreach (['c', 'a', 'b'] as $letter) {
      $this->createLiteral("lit_$letter", '1', ['name' => "Thing $letter"]);
    }
    $found = $this->container->get('literals.search')->search('thing', new AnonymousUserSession(), 2);
    $this->assertSame(['lit_a', 'lit_b'], array_values(array_map(fn ($l) => $l->get('key')->value, $found)));
  }

}
