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
    return $this->defaultModel() !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function modelId(): ?string {
    $default = $this->defaultModel();
    return $default ? $default['provider_id'] . '/' . $default['model_id'] : NULL;
  }

  /**
   * The site's default decision provider and model, if both are set.
   *
   * @return array|null
   *   The provider_id and model_id, or NULL when either is missing.
   */
  protected function defaultModel(): ?array {
    $default = $this->aiProvider->getDefaultProviderForOperationType('decision');
    return !empty($default['provider_id']) && !empty($default['model_id']) ? $default : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function choose(string $question, array $candidates, ?string $context = NULL, ?array $thresholds = NULL): LiteralFindResult {
    $default = $this->defaultModel();
    if ($default === NULL) {
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
    $threshold = (float) ($thresholds['match_threshold'] ?? $settings->get('match_threshold'));
    $margin = (float) ($thresholds['choice_margin'] ?? $settings->get('choice_margin'));

    $probabilities = $answer->getProbabilities();
    return $this->interpret($probabilities, $by_key, $threshold, $margin)
      ->withScores($this->scores($probabilities, $default['model_id']));
  }

  /**
   * Summarizes a probability spread for the audit line, keys and numbers only.
   *
   * @param float[] $probabilities
   *   Probabilities keyed by literal key, plus the none option.
   * @param string $model
   *   The decision model ID.
   *
   * @return array
   *   The two likeliest literals ("top", "next": [key, probability] or NULL),
   *   the none probability ("none") and the model ID ("model").
   */
  public function scores(array $probabilities, string $model): array {
    $none = (float) ($probabilities[self::NONE_OPTION] ?? 0.0);
    unset($probabilities[self::NONE_OPTION]);
    arsort($probabilities);
    $ranked = array_slice($probabilities, 0, 2, TRUE);
    $pairs = array_map(fn ($key, $p) => [(string) $key, (float) $p], array_keys($ranked), $ranked);
    return [
      'top' => $pairs[0] ?? NULL,
      'next' => $pairs[1] ?? NULL,
      'none' => $none,
      'model' => $model,
    ];
  }

  /**
   * Turns the model's probabilities into a find result.
   *
   * @param float[] $probabilities
   *   Probabilities keyed by literal key, plus the none option.
   * @param \Drupal\literals\Entity\Literal[] $by_key
   *   The candidate literals keyed by key.
   * @param float $threshold
   *   The least probability a top pick needs to be returned at all.
   * @param float $margin
   *   The lead the top pick needs over the runner-up.
   *
   * @return \Drupal\literals_finder\Finder\LiteralFindResult
   *   The result.
   */
  public function interpret(array $probabilities, array $by_key, float $threshold, float $margin): LiteralFindResult {
    $decision = static::rule($probabilities, array_keys($by_key), $threshold, $margin);
    $literals = array_map(fn ($key) => $by_key[$key], $decision['keys']);
    return new LiteralFindResult($decision['outcome'], $literals, 'chooser', $decision['reason']);
  }

  /**
   * Applies the threshold and margin rule to a probability spread, keys only.
   *
   * Needs no model and no literals, so an offline sweep (drush
   * literals:sweep) can replay a recorded spread under other settings.
   *
   * @param float[] $probabilities
   *   Probabilities keyed by literal key, plus the none option.
   * @param string[] $candidates
   *   The candidate literal keys.
   * @param float $threshold
   *   The least probability a top pick needs to be returned at all.
   * @param float $margin
   *   The lead the top pick needs over the runner-up.
   *
   * @return array
   *   "outcome" (a LiteralFindResult outcome), "keys" (the matched or tied
   *   literal keys) and "reason" (a machine reason, or '').
   */
  public static function rule(array $probabilities, array $candidates, float $threshold, float $margin): array {
    $candidates = array_flip(array_map('strval', $candidates));
    arsort($probabilities);
    $ranked = array_keys($probabilities);
    $top = (string) $ranked[0];
    if ($top === self::NONE_OPTION || !isset($candidates[$top])) {
      return ['outcome' => LiteralFindResult::NONE, 'keys' => [], 'reason' => 'chose_none'];
    }
    // A top pick under the threshold is no answer, however the rest lie: a flat
    // spread would otherwise tie almost the whole pool.
    if ($probabilities[$top] < $threshold) {
      return ['outcome' => LiteralFindResult::NONE, 'keys' => [], 'reason' => 'low_confidence'];
    }
    // The lead is over the best other literal: "none" is only a rival for the
    // top place, never part of a tie.
    $rival = 0.0;
    foreach ($probabilities as $key => $p) {
      if ((string) $key !== $top && $key !== self::NONE_OPTION) {
        $rival = max($rival, $p);
      }
    }

    if ($probabilities[$top] - $rival < $margin) {
      // Only literals the model itself believes in are contenders.
      $tied = [];
      foreach ($probabilities as $key => $p) {
        if ($key !== self::NONE_OPTION && isset($candidates[(string) $key]) && $p >= $threshold && $probabilities[$top] - $p < $margin) {
          $tied[] = (string) $key;
        }
      }
      return ['outcome' => LiteralFindResult::AMBIGUOUS, 'keys' => $tied, 'reason' => ''];
    }
    return ['outcome' => LiteralFindResult::MATCH, 'keys' => [$top], 'reason' => ''];
  }

  /**
   * Rebuilds a probability spread from recorded scores.
   *
   * Holds only the top two literals and the none option, which is enough for
   * rule() to give the same outcome as on the full spread; only a tie of
   * three or more literals would list fewer keys.
   *
   * @param array $scores
   *   Scores as scores() returns them.
   *
   * @return float[]
   *   Probabilities keyed by literal key, plus the none option.
   */
  public static function probabilitiesFromScores(array $scores): array {
    $probabilities = [self::NONE_OPTION => (float) ($scores['none'] ?? 0.0)];
    foreach (['top', 'next'] as $rank) {
      if (!empty($scores[$rank])) {
        $probabilities[(string) $scores[$rank][0]] = (float) $scores[$rank][1];
      }
    }
    return $probabilities;
  }

}
