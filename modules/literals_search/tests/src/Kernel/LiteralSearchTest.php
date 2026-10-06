<?php

declare(strict_types=1);

namespace Drupal\Tests\literals_search\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\literals\Kernel\LiteralsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests plain literal search and the autocomplete endpoint.
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
    'literals_search',
  ];

  /**
   * Runs a search and returns the matching keys, sorted.
   */
  protected function keys(string $text, $account = NULL, int $limit = 10): array {
    $found = $this->container->get('literals_search.search')->search($text, $account ?? new AnonymousUserSession(), $limit);
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
    $found = $this->container->get('literals_search.search')->search('thing', new AnonymousUserSession(), 2);
    $this->assertSame(['lit_a', 'lit_b'], array_values(array_map(fn ($l) => $l->get('key')->value, $found)));
  }

  /**
   * The autocomplete needs its permission and never returns a value.
   */
  public function testAutocomplete(): void {
    $this->createLiteral('main_phone', 'SECRET-VALUE 555 0100', [
      'name' => 'Main <b>phone</b>',
      'gist' => 'The library switchboard',
    ]);
    $this->createLiteral('internal', 'staff', ['audience' => 'restricted', 'name' => 'Main staff line']);
    $url = '/literals/search/autocomplete';
    $call = function ($account, string $q) use ($url): array {
      $this->setCurrentUser($account);
      $request = Request::create($url, 'GET', ['q' => $q]);
      $response = $this->container->get('http_kernel')->handle($request);
      return [$response->getStatusCode(), (string) $response->getContent()];
    };

    [$status] = $call(new AnonymousUserSession(), 'main');
    $this->assertSame(403, $status, 'No permission, no search');

    $searcher = $this->createUser(['search literals']);
    [$status, $body] = $call($searcher, 'main');
    $this->assertSame(200, $status);
    $suggestions = json_decode($body, TRUE);
    $this->assertCount(1, $suggestions, 'The restricted literal is not offered');
    $this->assertSame('main_phone', $suggestions[0]['value']);
    $this->assertStringNotContainsString('SECRET-VALUE', $body);
    $this->assertStringNotContainsString('<b>', $suggestions[0]['label'], 'Labels are escaped');
    $this->assertStringContainsString('The library switchboard', $suggestions[0]['label']);

    $both = $this->createUser(['search literals', 'view restricted literals']);
    [, $body] = $call($both, 'main');
    $this->assertCount(2, json_decode($body, TRUE));
  }

}
