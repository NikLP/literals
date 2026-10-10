<?php

declare(strict_types=1);

namespace Drupal\Tests\literals\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\literals\Entity\Literal;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that Content Moderation can drive literals.
 *
 * The entity docs promise a changed value can sit as a draft until someone
 * publishes it; this holds them to it.
 *
 * @group literals
 */
#[RunTestsInSeparateProcesses]
class LiteralModerationTest extends LiteralsKernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['workflows', 'content_moderation'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig(['content_moderation']);
    $workflow = $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('literal', 'text');
    $workflow->save();
    // Uid 1 may use every transition.
    $this->setCurrentUser(User::load(1));
  }

  /**
   * A draft of a published literal is not served until it is published.
   */
  public function testDraftWaitsForPublication(): void {
    $literal = $this->createLiteral('moderated', 'old value', ['moderation_state' => 'published']);
    $anon = new AnonymousUserSession();
    $reader = $this->container->get('literals.reader');
    $this->assertSame('old value', $reader->read('moderated', $anon));

    $literal->set('value', 'new value')->set('moderation_state', 'draft')->save();
    $this->container->get('entity_type.manager')->getStorage('literal')->resetCache();
    $this->assertSame('old value', $reader->read('moderated', $anon), 'The draft is not served');

    $latest = $this->container->get('entity_type.manager')->getStorage('literal')->loadRevision(
      $this->container->get('entity_type.manager')->getStorage('literal')->getLatestRevisionId('moderated'),
    );
    $this->assertInstanceOf(Literal::class, $latest);
    $latest->set('moderation_state', 'published')->save();
    $this->container->get('entity_type.manager')->getStorage('literal')->resetCache();
    $this->container->get('entity_type.manager')->getAccessControlHandler('literal')->resetCache();
    $this->assertSame('new value', $reader->read('moderated', $anon));
  }

}
