<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Finder;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Guardrail\AiGuardrailRepository;
use Drupal\ai\Guardrail\NonDeterministicGuardrailInterface;
use Drupal\ai\Guardrail\Result\PassResult;
use Drupal\ai\Guardrail\Result\RewriteInputResult;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\literals\LiteralGuardrailsInterface;

/**
 * Runs the shipped literals guardrail set over text being saved.
 *
 * The same loop as aim's AimMemoryManager::runGuardrails(), over the
 * literals_write_guardrails set. A literal's value is checked with
 * non-deterministic guardrails skipped, so a model-backed guardrail added to
 * the set later still never sees a value.
 */
class LiteralGuardrails implements LiteralGuardrailsInterface {

  /**
   * The guardrail set shipped with this module.
   */
  public const SET_ID = 'literals_write_guardrails';

  /**
   * Constructs the runner.
   *
   * @param \Drupal\ai\Guardrail\AiGuardrailRepository $repository
   *   The guardrail repository.
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   */
  public function __construct(
    protected AiGuardrailRepository $repository,
    protected AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function check(string $text, bool $deterministic_only): string {
    $set = $this->repository->getGuardrailSetById(self::SET_ID);
    if (!$set) {
      return $text;
    }
    $message = new ChatMessage('user', $text);
    $input = new ChatInput([$message]);
    $score = 0.0;
    $messages = [];
    foreach ($set->getPreGenerateGuardrails() as $guardrail) {
      if ($guardrail instanceof NonDeterministicGuardrailInterface) {
        if ($deterministic_only) {
          continue;
        }
        $guardrail->setAiPluginManager($this->aiProvider);
      }
      $result = $guardrail->processInput($input);
      if ($result instanceof PassResult) {
        continue;
      }
      if ($result instanceof StopResult) {
        $score += $result->getScore();
        $messages[] = $result->getMessage();
        if ($score >= $set->getStopThreshold()) {
          throw new \InvalidArgumentException('Guardrail check failed: ' . implode(' ', $messages));
        }
      }
      if ($result instanceof RewriteInputResult) {
        $message->setText($result->getMessage());
      }
    }
    return $message->getText();
  }

}
