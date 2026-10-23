<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Finder;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralVisibility;
use Psr\Log\LoggerInterface;

/**
 * The finder: access filter, outcome cache, then the chooser.
 *
 * The chooser (a Decision API model) sees the whole access-filtered menu of
 * keys and gists. Without a decision model nothing is guessed: the answer is
 * none. An embedding gate that shortlisted the menu was built and removed
 * (2026-10-05): with a decision model it added no accuracy and, past a few
 * hundred literals, no speed.
 */
class LiteralFinder implements LiteralFinderInterface {

  /**
   * Constructs the finder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\literals_finder\Finder\LiteralChooserInterface $chooser
   *   The chooser.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The outcome cache.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The literals logger channel.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected LiteralChooserInterface $chooser,
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function find(string $question, ?AccountInterface $account = NULL, ?string $context = NULL, ?array $thresholds = NULL): LiteralFindResult {
    $account ??= $this->currentUser;
    $question = mb_substr(trim($question), 0, max(20, (int) $this->configFactory->get('literals_finder.settings')->get('max_question_length') ?: 300));
    $candidates = $this->candidates($account);
    if ($question === '' || $candidates === []) {
      return $this->done(new LiteralFindResult(LiteralFindResult::NONE, [], 'pool', 'no_candidates'), count($candidates));
    }

    // Never serve across permission sets: the key carries what is visible.
    $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $question));
    $visible = json_encode(LiteralVisibility::visibleFlags($account));
    $cid = 'literals:find:' . hash('sha256', "$normalized|$visible|" . $this->settingsFingerprint($context, $thresholds));
    if ($hit = $this->cache->get($cid)) {
      $by_id = $this->entityTypeManager->getStorage('literal')->loadMultiple($hit->data['ids']);
      // A cached id the asker can no longer see just drops out.
      $literals = array_values(array_intersect_key($by_id, $candidates));
      if (count($literals) === count($hit->data['ids'])) {
        return $this->done(new LiteralFindResult($hit->data['outcome'], $literals, 'cache', $hit->data['reason'], $hit->data['scores'] ?? []), count($candidates));
      }
    }

    try {
      $result = $this->lookup($question, $candidates, $context, $thresholds);
    }
    catch (\Throwable $e) {
      // A model failure is not a "none": it is reported, never cached.
      $this->logger->warning('Finder failed: @class, @candidates candidates.', [
        '@class' => get_class($e),
        '@candidates' => count($candidates),
      ]);
      return new LiteralFindResult(LiteralFindResult::NONE, [], 'pool', 'error');
    }

    if ($result->reason !== 'no_backend') {
      $settings = $this->configFactory->get('literals_finder.settings');
      $expire = $result->outcome === LiteralFindResult::NONE
        ? time() + (int) $settings->get('miss_ttl')
        : CacheBackendInterface::CACHE_PERMANENT;
      $this->cache->set($cid, [
        'outcome' => $result->outcome,
        'ids' => array_map(fn (Literal $l) => $l->id(), $result->literals),
        'reason' => $result->reason,
        'scores' => $result->scores,
      ], $expire, ['literal_list']);
    }
    return $this->done($result, count($candidates));
  }

  /**
   * Fingerprints everything besides the question that decides an outcome.
   *
   * A cached outcome is only valid for the same context, instructions,
   * thresholds and decision model, so changing any of them (or asking with
   * another context) never serves a stale answer. A model updated behind
   * the same ID is not seen: clear the cache after one.
   *
   * @param string|null $context
   *   The caller's context, if any.
   * @param array|null $thresholds
   *   The caller's threshold overrides, if any.
   *
   * @return string
   *   A hash.
   */
  protected function settingsFingerprint(?string $context, ?array $thresholds = NULL): string {
    $settings = $this->configFactory->get('literals_finder.settings')->getRawData();
    unset($settings['_core'], $settings['log_audit'], $settings['log_verbose']);
    $settings['context'] = $context ?? '';
    $settings['thresholds'] = $thresholds ?? [];
    $settings['model'] = $this->chooser->modelId() ?? '';
    ksort($settings);
    return hash('sha256', serialize($settings));
  }

  /**
   * Runs the chooser over an access-filtered pool.
   *
   * @param string $question
   *   The question.
   * @param \Drupal\literals\Entity\Literal[] $candidates
   *   Literals the asker may see.
   * @param string|null $context
   *   The caller's context for the chooser, if any.
   * @param array|null $thresholds
   *   The caller's threshold overrides for the chooser, if any.
   */
  protected function lookup(string $question, array $candidates, ?string $context = NULL, ?array $thresholds = NULL): LiteralFindResult {
    if (!$this->chooser->isAvailable()) {
      return new LiteralFindResult(LiteralFindResult::NONE, [], 'pool', 'no_backend');
    }
    return $this->chooser->choose($question, $candidates, $context, $thresholds);
  }

  /**
   * Loads the published literals the account may view.
   *
   * The visibility rule is applied to the query and again per entity, before
   * the chooser sees any gist.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The asker.
   *
   * @return \Drupal\literals\Entity\Literal[]
   *   Literals, keyed by ID.
   */
  protected function candidates(AccountInterface $account): array {
    $flags = LiteralVisibility::visibleFlags($account);
    if ($flags === []) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('literal');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('status', 1)->condition('gist', '', '<>');

    if ($flags !== NULL) {
      $query->condition('restricted', $flags, 'IN');
    }

    $literals = [];
    foreach ($storage->loadMultiple($query->execute()) as $literal) {
      if ($literal->access('view', $account)) {
        $literals[$literal->id()] = $literal;
      }
    }

    return $literals;
  }

  /**
   * Writes the audit line and returns the result.
   *
   * IDs, tier and outcome only, never the question text or a value. With
   * log_verbose on, the chooser's scores and model ID are added (a cached
   * outcome repeats the scores of the call that made it).
   *
   * @param \Drupal\literals_finder\Finder\LiteralFindResult $result
   *   The result.
   * @param int $pool
   *   The visible pool size.
   */
  protected function done(LiteralFindResult $result, int $pool): LiteralFindResult {
    $settings = $this->configFactory->get('literals_finder.settings');
    if ($settings->get('log_audit')) {
      $message = 'Find: @outcome via @tier (@reason), literals [@ids], pool @pool.';
      $args = [
        '@outcome' => $result->outcome,
        '@tier' => $result->tier,
        '@reason' => $result->reason ?: '-',
        '@ids' => implode(',', array_map(fn (Literal $l) => $l->id(), $result->literals)),
        '@pool' => $pool,
      ];

      if ($settings->get('log_verbose') && $result->scores) {
        $message .= ' Scores: @scores.';
        $args['@scores'] = $this->formatScores($result->scores);
      }
      $this->logger->info($message, $args);
    }
    return $result;
  }

  /**
   * Formats chooser scores: "top a 0.92, next b 0.04, none 0.007, model m".
   *
   * @param array $scores
   *   The scores, as LiteralFindResult describes them.
   *
   * @return string
   *   The formatted scores.
   */
  protected function formatScores(array $scores): string {
    $parts = [];
    foreach (['top', 'next'] as $rank) {
      if (!empty($scores[$rank])) {
        $parts[] = sprintf('%s %s %.2g', $rank, $scores[$rank][0], $scores[$rank][1]);
      }
    }

    $parts[] = sprintf('none %.2g', $scores['none'] ?? 0.0);
    $parts[] = 'model ' . ($scores['model'] ?? '-');
    return implode(', ', $parts);
  }

}
