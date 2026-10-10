<?php

declare(strict_types=1);

namespace Drupal\Tests\literals_finder\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\literals\Entity\Literal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a Guardrails rejection of the gist through the literal form.
 *
 * Uses the guardrail set this module ships (literals_write_guardrails), so
 * the whole path runs: form, field constraint, runner, ai guardrail plugin.
 */
#[Group('literals')]
#[RunTestsInSeparateProcesses]
class LiteralGistGuardrailsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['literals', 'literals_finder'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * The ai module's schema for input_length_limit lacks check_all_messages,
   * an upstream gap (see this module's CLAUDE.md gotchas).
   */
  protected static $configSchemaCheckerExclusions = ['ai.ai_guardrail.literals_max_length'];

  /**
   * A gist the no-markup guardrail rejects is a form error on the gist.
   */
  public function testGistRejected(): void {
    $this->drupalLogin($this->drupalCreateUser(['edit literals']));

    $this->drupalGet('/admin/content/literals/add/text');
    $this->submitForm([
      'name[0][value]' => 'Main phone',
      'id' => 'main_phone',
      'value[0][value]' => '0113 496 0000',
      'gist[0][value]' => 'The <b>main</b> phone number',
    ], 'Save');
    $this->assertSession()->pageTextContains('This text contains HTML markup, which is not allowed in a literal.');
    $this->assertSession()->elementAttributeContains('css', 'input[name="gist[0][value]"]', 'aria-invalid', 'true');
    $this->assertSession()->elementAttributeNotExists('css', 'textarea[name="value[0][value]"]', 'aria-invalid');
    $this->assertNull(Literal::load('main_phone'));

    // The same literal saves once the gist is plain text.
    $this->submitForm(['gist[0][value]' => 'The main phone number'], 'Save');
    $literal = Literal::load('main_phone');
    $this->assertInstanceOf(Literal::class, $literal);
    $this->assertSame('The main phone number', $literal->getGist());
  }

  /**
   * An edit that adds markup to the gist is refused and changes nothing.
   */
  public function testGistRejectedOnEdit(): void {
    Literal::create([
      'type' => 'text',
      'id' => 'opening_hours',
      'name' => 'Opening hours',
      'value' => '9am to 5pm',
      'gist' => 'When the library is open',
      'status' => 1,
    ])->save();
    $this->drupalLogin($this->drupalCreateUser(['edit literals']));

    $this->drupalGet('/admin/content/literals/opening_hours/edit');
    $this->submitForm(['gist[0][value]' => 'When the library is <script>open</script>'], 'Save');
    $this->assertSession()->pageTextContains('This text contains HTML markup, which is not allowed in a literal.');
    $this->container->get('entity_type.manager')->getStorage('literal')->resetCache();
    $this->assertSame('When the library is open', Literal::load('opening_hours')->getGist());
  }

}
