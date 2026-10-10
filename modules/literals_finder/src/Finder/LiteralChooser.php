<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Finder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\DecisionInterface;
use Drupal\ai\OperationType\Decision\Value\ChoiceQuestion;

/**
 * The Decision API ChoiceQuestion over key and gist, never values.
 */
class LiteralChooser implements LiteralChooserInterface {

  /**
   * The option that means "none of these".
   */
  protected const NONE_OPTION = '__none__';

  /**
   * The default question put to the model.
   */
  protected const DEFAULT_INSTRUCTIONS = 'Which description says what the question is asking for? Choose none when no description clearly fits; a wrong answer is worse than none.';

  /**
   * Constructs the chooser.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    $default = $this->aiProvider->getDefaultProviderForOperationType('decision');
    return !empty($default['provider_id']) && !empty($default['model_id']);
  }

  /**
   * {@inheritdoc}
   */
  public function choose(string $question, array $candidates, ?string $context = NULL): LiteralFindResult {
    $default = $this->aiProvider->getDefaultProviderForOperationType('decision');
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      throw new \RuntimeException('No default decision provider is configured.');
    }
    $provider = $this->aiProvider->createInstance($default['provider_id']);
    if (!$provider->getPlugin() instanceof DecisionInterface) {
      throw new \RuntimeException('The default decision provider does not implement the Decision operation type.');
    }

    $by_key = [];
    $criteria = [];
    foreach ($candidates as $literal) {
      $key = $literal->id();
      $by_key[$key] = $literal;
      $criteria[$key] = $literal->getGist() ?: (string) $literal->label();
    }
    $criteria[self::NONE_OPTION] = 'None of the above: the question is not asking for any of these.';

    $config = $this->configFactory->get('literals_finder.settings');
    $instructions = (string) $config->get('chooser_instructions');
    // Who is being asked, so "you" and "your" resolve to the site's owner. A
    // caller's own context replaces the site default.
    $context = trim($context ?? (string) $config->get('chooser_context'));
    $input = new DecisionInput(
      ['question' => $question],
      ['choice' => new ChoiceQuestion(trim($context . ' ' . ($instructions ?: self::DEFAULT_INSTRUCTIONS)), $criteria)],
    );
    $answer = $provider->decision($input, $default['model_id'], ['literals_choose'])->getNormalized()->getChoice('choice');

    $settings = $this->configFactory->get('literals_finder.settings');
    $threshold = (float) $settings->get('match_threshold');
    $margin = (float) $settings->get('choice_margin');

    $probabilities = $answer->getProbabilities();
    arsort($probabilities);
    $ranked = array_keys($probabilities);
    $top = $ranked[0];
    if ($top === self::NONE_OPTION || !isset($by_key[$top])) {
      return new LiteralFindResult(LiteralFindResult::NONE, [], 'chooser', 'chose_none');
    }
    // The lead is over the best other literal: "none" is only a rival for the
    // top place, never part of a tie.
    $rival = 0.0;
    foreach ($probabilities as $key => $p) {
      if ($key !== $top && $key !== self::NONE_OPTION) {
        $rival = max($rival, $p);
      }
    }
    if ($probabilities[$top] - $rival < $margin) {
      $tied = [];
      foreach ($probabilities as $key => $p) {
        if ($key !== self::NONE_OPTION && isset($by_key[$key]) && $probabilities[$top] - $p < $margin) {
          $tied[] = $by_key[$key];
        }
      }
      return new LiteralFindResult(LiteralFindResult::AMBIGUOUS, $tied, 'chooser');
    }
    if ($probabilities[$top] < $threshold) {
      return new LiteralFindResult(LiteralFindResult::NONE, [], 'chooser', 'low_confidence');
    }
    return new LiteralFindResult(LiteralFindResult::MATCH, [$by_key[$top]], 'chooser');
  }

}
