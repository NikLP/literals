<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\literals\LiteralAudience;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests audience-based view access, update/delete, and list filtering.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralAccessTest extends LiteralsKernelTestBase {

  /**
   * Audience values visible to each kind of account.
   */
  public function testVisibleTo(): void {
    $this->assertSame(['anonymous'], LiteralAudience::visibleTo(new AnonymousUserSession()));
    $this->assertSame(['anonymous', 'authenticated'], LiteralAudience::visibleTo($this->member));
    $this->assertSame(['anonymous', 'authenticated', 'restricted'], LiteralAudience::visibleTo($this->restrictedViewer));
  }

  /**
   * View access follows the audience, for every account kind.
   */
  public function testViewAccessMatrix(): void {
    $literals = [];
    foreach (['anonymous', 'authenticated', 'restricted'] as $audience) {
      $literals[$audience] = $this->createLiteral("lit_$audience", '1', ['audience' => $audience]);
    }
    $accounts = [
      'anon' => [new AnonymousUserSession(), ['anonymous']],
      'member' => [$this->member, ['anonymous', 'authenticated']],
      'restricted viewer' => [$this->restrictedViewer, ['anonymous', 'authenticated', 'restricted']],
      'admin' => [$this->admin, ['anonymous', 'authenticated', 'restricted']],
    ];
    foreach ($accounts as $label => [$account, $expected]) {
      foreach ($literals as $audience => $literal) {
        $this->assertSame(in_array($audience, $expected, TRUE), $literal->access('view', $account), "$label viewing $audience");
      }
    }
  }

  /**
   * An unpublished literal is visible only to someone who can edit.
   */
  public function testUnpublishedIsHidden(): void {
    $draft = $this->createLiteral('draft', '1', ['status' => 0]);
    $this->assertFalse($draft->access('view', new AnonymousUserSession()));
    $this->assertFalse($draft->access('view', $this->member));
    $this->assertFalse($draft->access('view', $this->restrictedViewer));
    $editor = $this->createUser(['edit literals']);
    $this->assertTrue($draft->access('view', $editor));
    $this->assertTrue($draft->access('view', $this->admin));
  }

  /**
   * Update and delete need their own flat permissions; view does not give them.
   */
  public function testUpdateDeleteNeedPermissions(): void {
    $literal = $this->createLiteral('thing', '1');
    $this->assertFalse($literal->access('update', $this->member));
    $this->assertFalse($literal->access('delete', $this->member));
    $this->assertFalse($literal->access('update', new AnonymousUserSession()));
    $this->assertTrue($literal->access('update', $this->createUser(['edit literals'])));
    $this->assertFalse($literal->access('delete', $this->createUser(['edit literals'])));
    $this->assertTrue($literal->access('delete', $this->createUser(['delete literals'])));
    $this->assertTrue($literal->access('update', $this->admin));
    $this->assertTrue($literal->access('delete', $this->admin));
  }

  /**
   * Create access needs the create permission.
   */
  public function testCreateAccess(): void {
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('literal');
    $this->assertFalse($handler->createAccess('text', $this->member));
    $this->assertTrue($handler->createAccess('text', $this->createUser(['create literals'])));
    $this->assertTrue($handler->createAccess('text', $this->admin));
  }

  /**
   * Access-checked entity queries only list what the viewer may open.
   */
  public function testQueryAlterFiltersLists(): void {
    $this->createLiteral('pub', '1', ['audience' => 'anonymous']);
    $this->createLiteral('auth', '1', ['audience' => 'authenticated']);
    $this->createLiteral('res', '1', ['audience' => 'restricted']);
    $storage = $this->container->get('entity_type.manager')->getStorage('literal');
    $keys = function ($account) use ($storage): array {
      $this->setCurrentUser($account);
      $ids = $storage->getQuery()->accessCheck(TRUE)->execute();
      $found = array_map(fn ($l) => $l->get('key')->value, $storage->loadMultiple($ids));
      sort($found);
      return $found;
    };
    $this->assertSame(['pub'], $keys(new AnonymousUserSession()));
    $this->assertSame(['auth', 'pub'], $keys($this->member));
    $this->assertSame(['auth', 'pub', 'res'], $keys($this->restrictedViewer));
    $this->assertSame(['auth', 'pub', 'res'], $keys($this->admin));
  }

}
