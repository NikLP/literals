<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\KernelTests\KernelTestBase;
use Drupal\literals\Entity\Literal;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what installing literals and its recipes leaves behind.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'text', 'filter', 'views', 'literals'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('literal');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'literals']);
  }

  /**
   * The shipped config exists, grants nothing and validates against schema.
   */
  public function testShippedConfig(): void {
    $types = array_keys($this->container->get('entity_type.manager')->getStorage('literal_type')->loadMultiple());
    sort($types);
    $this->assertSame(['entity', 'field', 'phone', 'text', 'token', 'url'], $types);
    $this->assertNotNull($this->config('views.view.literals')->get('id'));

    $typed = $this->container->get('config.typed');
    foreach ($this->container->get('config.storage')->listAll('literals.') as $name) {
      $violations = $typed->createFromNameAndData($name, $this->config($name)->getRawData())->validate();
      $this->assertCount(0, $violations, "$name: " . $violations);
    }
    $this->assertSame([], Literal::loadMultiple(), 'No literals on install');
  }

  /**
   * The base recipe grants the view permissions; the demo adds eight, once.
   */
  public function testRecipes(): void {
    // Default content is imported as the site's first account.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $recipes = dirname(__DIR__, 3) . '/recipes/';
    RecipeRunner::processRecipe(Recipe::createFromDirectory($recipes . 'literals_base'));
    $this->assertSame(['view literals'], Role::load('anonymous')->getPermissions());
    $authenticated = Role::load('authenticated')->getPermissions();
    sort($authenticated);
    $this->assertSame(['view literals', 'view restricted literals'], $authenticated);

    $demo = Recipe::createFromDirectory($recipes . 'literals_demo_library');
    RecipeRunner::processRecipe($demo);
    $this->assertCount(8, Literal::loadMultiple());
    $this->assertTrue(Literal::load('staff_line')->isRestricted());
    $this->assertFalse(Literal::load('main_phone')->isRestricted());
    RecipeRunner::processRecipe($demo);
    $this->assertCount(8, Literal::loadMultiple(), 'Re-applying adds no duplicates');
  }

  /**
   * The module uninstalls cleanly once its literals are deleted.
   */
  public function testUninstall(): void {
    $this->container->get('module_installer')->uninstall(['literals']);
    $this->assertFalse($this->container->get('module_handler')->moduleExists('literals'));
    $this->assertNull($this->container->get('config.factory')->get('views.view.literals')->get('id'));
  }

}
