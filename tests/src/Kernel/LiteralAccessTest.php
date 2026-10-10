<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\literals\LiteralVisibility;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests view access by the restricted flag, edit access, and list filtering.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralAccessTest extends LiteralsKernelTestBase {

  /**
   * The restricted flag values visible to each kind of account.
   */
  public function testVisibleFlags(): void {
    $this->assertSame([0], LiteralVisibility::visibleFlags(new AnonymousUserSession()));
    $this->assertSame([0], LiteralVisibility::visibleFlags($this->member));
    $this->assertNull(LiteralVisibility::visibleFlags($this->restrictedViewer));
    $this->assertNull(LiteralVisibility::visibleFlags($this->createUser(['edit literals'])));
    $this->assertNull(LiteralVisibility::visibleFlags($this->admin));
    Role::load('anonymous')->revokePermission('view literals')->save();
    $this->assertSame([], LiteralVisibility::visibleFlags(new AnonymousUserSession()));
  }

  /**
   * View access follows the restricted flag, for every account kind.
   */
  public function testViewAccessMatrix(): void {
    $open = $this->createLiteral('lit_open', '1');
    $restricted = $this->createLiteral('lit_restricted', '1', ['restricted' => TRUE]);
    $accounts = [
      'anon' => [new AnonymousUserSession(), FALSE],
      'member' => [$this->member, FALSE],
      'restricted viewer' => [$this->restrictedViewer, TRUE],
      'editor' => [$this->createUser(['edit literals']), TRUE],
      'admin' => [$this->admin, TRUE],
    ];
    foreach ($accounts as $label => [$account, $seesRestricted]) {
      $this->assertTrue($open->access('view', $account), "$label viewing open");
      $this->assertSame($seesRestricted, $restricted->access('view', $account), "$label viewing restricted");
    }
  }

  /**
   * Without "view literals" nothing is visible.
   */
  public function testNoViewPermissionSeesNothing(): void {
    $open = $this->createLiteral('lit_open', '1');
    Role::load('anonymous')->revokePermission('view literals')->save();
    $this->assertFalse($open->access('view', new AnonymousUserSession()));
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
   * Create, update and delete need "edit literals"; view does not give them.
   */
  public function testEditPermission(): void {
    $literal = $this->createLiteral('thing', '1');
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('literal');
    $editor = $this->createUser(['edit literals']);
    foreach (['member' => $this->member, 'anon' => new AnonymousUserSession()] as $label => $account) {
      $this->assertFalse($literal->access('update', $account), "$label update");
      $this->assertFalse($literal->access('delete', $account), "$label delete");
      $this->assertFalse($handler->createAccess('text', $account), "$label create");
    }
    foreach (['editor' => $editor, 'admin' => $this->admin] as $label => $account) {
      $this->assertTrue($literal->access('update', $account), "$label update");
      $this->assertTrue($literal->access('delete', $account), "$label delete");
      $this->assertTrue($handler->createAccess('text', $account), "$label create");
    }
  }

  /**
   * Access-checked entity queries only list what the viewer may open.
   */
  public function testQueryAlterFiltersLists(): void {
    $this->createLiteral('pub', '1');
    $this->createLiteral('res', '1', ['restricted' => TRUE]);
    $storage = $this->container->get('entity_type.manager')->getStorage('literal');
    $keys = function ($account) use ($storage): array {
      $this->setCurrentUser($account);
      $ids = $storage->getQuery()->accessCheck(TRUE)->execute();
      $found = array_map(fn ($l) => $l->id(), $storage->loadMultiple($ids));
      sort($found);
      return $found;
    };
    $this->assertSame(['pub'], $keys(new AnonymousUserSession()));
    $this->assertSame(['pub'], $keys($this->member));
    $this->assertSame(['pub', 'res'], $keys($this->restrictedViewer));
    $this->assertSame(['pub', 'res'], $keys($this->admin));
    Role::load('anonymous')->revokePermission('view literals')->save();
    $this->assertSame([], $keys(new AnonymousUserSession()));
  }

}
