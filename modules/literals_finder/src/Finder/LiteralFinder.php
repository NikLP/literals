<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Finder;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralAudience;
use Psr\Log\LoggerInterface;

/**
 * The finder: access filter, outcome cache, gate, then the chooser.
 *
 * Tiers (ADR-0040 Addendums 4 and 6): the outcome cache; the gate (embed the
 * question, compare with stored gist vectors in PHP) when switched on; the
 * chooser over the remaining menu. Without a decision model the gate's
 * margin decides alone; without either, nothing is guessed.
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
   * @param \Drupal\literals_finder\Finder\LiteralEmbedder $embedder
   *   The gist and question embedder.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The outcome cache.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The literals logger channel.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected LiteralChooserInterface $chooser,
    protected LiteralEmbedder $embedder,
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    protected QueueFactory $queueFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function find(string $question, ?AccountInterface $account = NULL): LiteralFindResult {
    $account ??= $this->currentUser;
    $question = trim($question);
    $candidates = $this->candidates($account);
    if ($question === '' || $candidates === []) {
      return $this->done(new LiteralFindResult(LiteralFindResult::NONE, [], 'pool', 'no_candidates'), count($candidates));
    }

    // Never serve across permission sets: the key carries the audiences.
    $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $question));
    $audiences = implode(',', LiteralAudience::visibleTo($account));
    $admin = (int) $account->hasPermission('administer literals');
    $cid = 'literals:find:' . hash('sha256', "$normalized|$audiences|$admin");
    if ($hit = $this->cache->get($cid)) {
      $by_id = $this->entityTypeManager->getStorage('literal')->loadMultiple($hit->data['ids']);
      // A cached id the asker can no longer see just drops out.
      $literals = array_values(array_intersect_key($by_id, $candidates));
      if (count($literals) === count($hit->data['ids'])) {
        return $this->done(new LiteralFindResult($hit->data['outcome'], $literals, 'cache', $hit->data['reason']), count($candidates));
      }
    }

    try {
      $result = $this->lookup($question, $candidates);
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
        'ids' => array_map(fn (Literal $l) => (int) $l->id(), $result->literals),
        'reason' => $result->reason,
      ], $expire, ['literal_list']);
    }
    return $this->done($result, count($candidates));
  }

  /**
   * Runs the gate and the chooser over an access-filtered pool.
   *
   * @param string $question
   *   The question.
   * @param \Drupal\literals\Entity\Literal[] $candidates
   *   Literals the asker may see.
   */
  protected function lookup(string $question, array $candidates): LiteralFindResult {
    $menu = $candidates;
    $tier = 'pool';
    if ($this->embedder->isAvailable()) {
      try {
        $gated = $this->gate($question, $candidates);
        if ($gated instanceof LiteralFindResult) {
          return $gated;
        }
        $menu = $gated;
        $tier = 'gate';
      }
      catch (\Throwable $e) {
        // An embedding outage degrades to the full menu, not to an error.
        $this->logger->warning('Gate unavailable (@class); using the full menu.', ['@class' => get_class($e)]);
      }
    }
    if ($this->chooser->isAvailable()) {
      return $this->chooser->choose($question, $menu);
    }
    if ($tier === 'gate') {
      // No decision model: the shortlist itself is the honest answer.
      return new LiteralFindResult(count($menu) === 1 ? LiteralFindResult::MATCH : LiteralFindResult::AMBIGUOUS, $menu, 'margin');
    }
    return new LiteralFindResult(LiteralFindResult::NONE, [], 'pool', 'no_backend');
  }

  /**
   * Compares the question with stored gist vectors.
   *
   * @param string $question
   *   The question.
   * @param \Drupal\literals\Entity\Literal[] $candidates
   *   Literals the asker may see.
   *
   * @return \Drupal\literals_finder\Finder\LiteralFindResult|\Drupal\literals\Entity\Literal[]
   *   A final result when the gate decides alone, else the shortlist for the
   *   chooser. Literals without a current vector always stay in the shortlist
   *   and are queued for embedding, so a stale vector never hides one.
   */
  protected function gate(string $question, array $candidates): LiteralFindResult|array {
    $settings = $this->configFactory->get('literals_finder.settings');
    $query_vector = $this->embedder->embed($question);

    $scored = [];
    $unembedded = [];
    foreach ($candidates as $literal) {
      if ($this->embedder->isCurrent($literal)) {
        $scored[(int) $literal->id()] = LiteralEmbedder::cosine($query_vector, $this->embedder->vectorOf($literal));
      }
      else {
        $unembedded[] = $literal;
        $this->queueFactory->get('literals_embed')->createItem(['id' => (int) $literal->id()]);
      }
    }
    arsort($scored);
    $ids = array_keys($scored);
    $top = $scored ? reset($scored) : 0.0;
    $second = count($scored) > 1 ? array_values($scored)[1] : 0.0;

    if ($unembedded === []) {
      if ($top < (float) $settings->get('gate_min_similarity')) {
        return new LiteralFindResult(LiteralFindResult::NONE, [], 'gate', 'nothing_close');
      }
      if ($top - $second >= (float) $settings->get('gate_margin')) {
        return new LiteralFindResult(LiteralFindResult::MATCH, [$this->byId($candidates, $ids[0])], 'margin');
      }
    }

    $shortlist = array_map(fn (int $id) => $this->byId($candidates, $id), array_slice($ids, 0, (int) $settings->get('gate_top')));
    return array_merge($shortlist, $unembedded);
  }

  /**
   * Returns the pool's literal with an ID.
   *
   * @param \Drupal\literals\Entity\Literal[] $candidates
   *   The pool.
   * @param int $id
   *   The literal ID.
   */
  protected function byId(array $candidates, int $id): Literal {
    foreach ($candidates as $literal) {
      if ((int) $literal->id() === $id) {
        return $literal;
      }
    }
    throw new \LogicException('Literal not in the pool.');
  }

  /**
   * Loads the published literals the account may view.
   *
   * The audience rule is applied to the query and again per entity, before
   * the gate or the chooser sees any gist.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The asker.
   *
   * @return \Drupal\literals\Entity\Literal[]
   *   Literals, keyed by ID.
   */
  protected function candidates(AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('literal');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('status', 1)->condition('gist', '', '<>');
    if (!$account->hasPermission('administer literals')) {
      $query->condition('audience', LiteralAudience::visibleTo($account), 'IN');
    }
    $literals = [];
    foreach ($storage->loadMultiple($query->execute()) as $literal) {
      if ($literal->access('view', $account)) {
        $literals[(int) $literal->id()] = $literal;
      }
    }
    return $literals;
  }

  /**
   * Writes the audit line and returns the result.
   *
   * IDs, tier and outcome only, never the question text or a value.
   *
   * @param \Drupal\literals_finder\Finder\LiteralFindResult $result
   *   The result.
   * @param int $pool
   *   The visible pool size.
   */
  protected function done(LiteralFindResult $result, int $pool): LiteralFindResult {
    if ($this->configFactory->get('literals_finder.settings')->get('log_audit')) {
      $this->logger->info('Find: @outcome via @tier (@reason), literals [@ids], pool @pool.', [
        '@outcome' => $result->outcome,
        '@tier' => $result->tier,
        '@reason' => $result->reason ?: '-',
        '@ids' => implode(',', array_map(fn (Literal $l) => (int) $l->id(), $result->literals)),
        '@pool' => $pool,
      ]);
    }
    return $result;
  }

}
