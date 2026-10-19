<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\literals\Entity\Literal;
use Drupal\literals\Entity\LiteralType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the admin pages, the literal form and the list.
 */
#[Group('literals')]
#[RunTestsInSeparateProcesses]
class LiteralAdminTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['literals'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
  }

  /**
   * Creates and saves a literal.
   *
   * @param string $id
   *   The key.
   * @param array $values
   *   Overrides.
   *
   * @return \Drupal\literals\Entity\Literal
   *   The literal.
   */
  protected function literal(string $id, array $values = []): Literal {
    $literal = Literal::create($values + [
      'type' => 'text',
      'id' => $id,
      'name' => ucfirst($id),
      'value' => 'v',
      'status' => 1,
    ]);
    $literal->save();
    return $literal;
  }

  /**
   * Admin pages are for administrators only.
   */
  public function testAdminPagesAccess(): void {
    $pages = ['/admin/content/literals', '/admin/config/literals/types', '/admin/config/literals'];
    foreach ($pages as $page) {
      $this->drupalGet($page);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->drupalLogin($this->drupalCreateUser(['view literals', 'view restricted literals']));
    foreach ($pages as $page) {
      $this->drupalGet($page);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->drupalLogin($this->drupalCreateUser([
      'administer literals',
      'access administration pages',
    ]));
    foreach ($pages as $page) {
      $this->drupalGet($page);
      $this->assertSame(200, $this->getSession()->getStatusCode(), $page);
    }
  }

  /**
   * The form takes a key once and refuses bad ones.
   */
  public function testLiteralForm(): void {
    $this->literal('taken');
    $this->drupalLogin($this->drupalCreateUser(['edit literals']));

    $this->drupalGet('/admin/content/literals/add/text');
    $this->submitForm(['name[0][value]' => 'Main phone', 'id' => 'main_phone', 'value[0][value]' => '42'], 'Save');
    $this->assertInstanceOf(Literal::class, Literal::load('main_phone'));

    $this->drupalGet('/admin/content/literals/add/text');
    $this->submitForm(['name[0][value]' => 'Again', 'id' => 'taken', 'value[0][value]' => '1'], 'Save');
    $this->assertSession()->pageTextContains('already in use');

    $this->drupalGet('/admin/content/literals/add/text');
    $this->submitForm(['name[0][value]' => 'Url', 'id' => 'url', 'value[0][value]' => '1'], 'Save');
    $this->assertSession()->pageTextContains('is reserved');
    $this->assertNull(Literal::load('url'));

    $this->drupalGet('/admin/content/literals/main_phone/edit');
    // The key is not offered at all once saved.
    $this->assertSession()->fieldNotExists('id');
    $this->submitForm(['value[0][value]' => '43'], 'Save');
    $this->assertSame('43', Literal::load('main_phone')->get('value')->value);
  }

  /**
   * A type in use cannot be disabled; an empty one can, and is then not offered.
   */
  public function testTypeDisableGuard(): void {
    $this->literal('uses_text');
    $this->drupalLogin($this->drupalCreateUser(['administer literals']));
    $this->drupalGet('/admin/config/literals/types/text/edit');
    $this->submitForm(['status' => FALSE], 'Save');
    $this->assertSession()->pageTextContains('Cannot disable');
    $this->assertTrue(LiteralType::load('text')->status());
    LiteralType::create(['id' => 'spare', 'label' => 'Spare', 'resolver' => 'text'])->save();
    $this->drupalGet('/admin/config/literals/types/spare/edit');
    $this->submitForm(['status' => FALSE], 'Save');
    $this->assertFalse(LiteralType::load('spare')->status());
  }

  /**
   * The list is for editors, who see every literal, restricted ones included.
   */
  public function testListIsForEditors(): void {
    $this->literal('open_one', ['name' => 'Open one']);
    $this->literal('closed_one', ['name' => 'Closed one', 'restricted' => TRUE]);

    $this->drupalLogin($this->drupalCreateUser(['view literals', 'view restricted literals']));
    $this->drupalGet('/admin/content/literals');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['edit literals']));
    $this->drupalGet('/admin/content/literals');
    $this->assertSession()->pageTextContains('Open one');
    $this->assertSession()->pageTextContains('Closed one');
  }

  /**
   * An edit makes a revision; history lists it and an old one can be restored.
   */
  public function testRevisions(): void {
    $this->literal('versioned');
    $this->drupalLogin($this->drupalCreateUser(['administer literals']));
    $this->drupalGet('/admin/content/literals/versioned/edit');
    $this->submitForm(['value[0][value]' => 'second'], 'Save');
    $revisions = $this->container->get('entity_type.manager')->getStorage('literal')->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('id', 'versioned')
      ->execute();
    $this->assertCount(2, $revisions);

    $this->drupalGet('/admin/content/literals/versioned/revisions');
    $this->assertSession()->statusCodeEquals(200);
    $first = min(array_keys($revisions));
    $this->drupalGet("/admin/content/literals/versioned/revision/$first/revert");
    $this->submitForm([], 'Revert');
    $this->container->get('entity_type.manager')->getStorage('literal')->resetCache();
    $this->assertSame('v', Literal::load('versioned')->get('value')->value);

    // Revision pages go with editing, not viewing.
    $this->drupalLogin($this->drupalCreateUser(['view literals', 'view restricted literals']));
    $this->drupalGet('/admin/content/literals/versioned/revisions');
    $this->assertSession()->statusCodeEquals(403);
  }

}
