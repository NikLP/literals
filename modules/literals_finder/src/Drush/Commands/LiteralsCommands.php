<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Drush\Commands;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\literals\Entity\Literal;
use Drupal\literals_finder\Finder\LiteralFindResult;
use Drupal\literals_finder\Finder\LiteralFinderInterface;
use Drupal\user\Entity\User;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the literals finder.
 */
final class LiteralsCommands extends DrushCommands {

  /**
   * Constructs the commands.
   *
   * @param \Drupal\literals_finder\Finder\LiteralFinderInterface $finder
   *   The finder.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module extension list.
   */
  public function __construct(
    protected LiteralFinderInterface $finder,
    protected AccountSwitcherInterface $accountSwitcher,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleExtensionList $moduleList,
  ) {
    parent::__construct();
  }

  /**
   * Creates the commands from the container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(LiteralFinderInterface::class),
      $container->get('account_switcher'),
      $container->get('entity_type.manager'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * Finds a literal by plain-language question.
   *
   * Prints the outcome, tier and keys, never values.
   */
  #[CLI\Command(name: 'literals:find')]
  #[CLI\Argument(name: 'question', description: 'The plain-language question.')]
  #[CLI\Option(name: 'uid', description: 'Ask as this user ID (default 0, anonymous).')]
  #[CLI\Usage(name: 'drush literals:find "how do I phone you" --uid=1', description: 'Look up as user 1.')]
  public function find(string $question, array $options = ['uid' => 0]): void {
    $account = (int) $options['uid'] === 0 ? new AnonymousUserSession() : User::load((int) $options['uid']);
    $this->accountSwitcher->switchTo($account);
    $result = $this->finder->find($question, $account);
    $this->accountSwitcher->switchBack();
    $this->io()->writeln(sprintf('%s via %s%s', $result->outcome, $result->tier, $result->reason ? " ($result->reason)" : ''));
    foreach ($result->literals as $literal) {
      $this->io()->writeln(sprintf('  %s: %s', $literal->id(), $literal->get('gist')->value));
    }
  }

  /**
   * Runs a gold set of questions through the finder and scores it.
   *
   * Clears the outcome cache first. Reports per-query results, then recall,
   * misses, wrong-confident matches (the costly kind: a wrong value served)
   * and time. Never prints values.
   */
  #[CLI\Command(name: 'literals:eval')]
  #[CLI\Argument(name: 'file', description: 'YAML gold set (default: the module\'s eval/gold.seed.yml).')]
  public function evaluate(?string $file = NULL): void {
    $file ??= $this->moduleList->getPath('literals_finder') . '/eval/gold.seed.yml';
    $gold = Yaml::decode(file_get_contents($file))['queries'] ?? [];
    Cache::invalidateTags(['literal_list']);

    $score = ['hit' => 0, 'miss' => 0, 'wrong' => 0, 'ambiguous' => 0, 'none_ok' => 0, 'none_fp' => 0];
    $answerable = 0;
    $unanswerable = 0;
    $elapsed = 0.0;
    $rows = [];
    foreach ($gold as $item) {
      $uid = (int) ($item['as'] ?? 0);
      $account = $uid === 0 ? new AnonymousUserSession() : User::load($uid);
      $start = microtime(TRUE);
      $result = $this->finder->find($item['q'], $account);
      $took = microtime(TRUE) - $start;
      $elapsed += $took;
      $keys = array_map(fn ($l) => $l->id(), $result->literals);
      // "expect" may be a list of acceptable answers: literal keys, "none", and
      // "ambiguous" for a tie. Nothing is a miss, a pick outside it is wrong.
      $accepted = is_array($item['expect']) ? array_map('strval', $item['expect']) : NULL;
      $expect = $accepted ? implode(' or ', $accepted) : (string) $item['expect'];

      if ($accepted) {
        $answerable++;
        $verdict = match (TRUE) {
          $result->outcome === LiteralFindResult::MATCH && count($keys) === 1 && in_array($keys[0], $accepted, TRUE) => 'hit',
          $result->outcome === LiteralFindResult::AMBIGUOUS && in_array('ambiguous', $accepted, TRUE) => 'hit',
          $result->outcome === LiteralFindResult::NONE && in_array('none', $accepted, TRUE) => 'hit',
          $result->outcome === LiteralFindResult::MATCH => 'wrong',
          $result->outcome === LiteralFindResult::AMBIGUOUS => 'ambiguous',
          default => 'miss',
        };
      }
      elseif ($expect === 'none' || $expect === 'ambiguous') {
        // Unanswerable, or deliberately vague: showing a tie or nothing is
        // right, picking one literal is the false positive.
        $unanswerable++;
        $ok = $result->outcome === LiteralFindResult::NONE
          || ($expect === 'ambiguous' && $result->outcome === LiteralFindResult::AMBIGUOUS);
        $verdict = $ok ? 'none_ok' : 'none_fp';
      }
      else {
        $answerable++;
        $verdict = match (TRUE) {
          $result->outcome === LiteralFindResult::MATCH && $keys === [$expect] => 'hit',
          $result->outcome === LiteralFindResult::MATCH => 'wrong',
          $result->outcome === LiteralFindResult::AMBIGUOUS => 'ambiguous',
          default => 'miss',
        };
      }
      $score[$verdict]++;
      $got = $result->outcome . ($keys ? ' ' . implode('|', $keys) : '');
      $tier = $result->tier . ($result->reason ? "/$result->reason" : '');
      $rows[] = [$verdict, $item['q'], $expect, $got, $tier, sprintf('%.2fs', $took)];
    }

    $this->io()->table(['', 'Question', 'Expected', 'Got', 'Tier', 'Time'], $rows);
    $pct = fn (int $n, int $d) => $d ? sprintf('%d/%d (%d%%)', $n, $d, round(100 * $n / $d)) : '-';
    $this->io()->writeln([
      'Answerable:   hit ' . $pct($score['hit'], $answerable) . ', miss ' . $score['miss'] . ', ambiguous ' . $score['ambiguous'] . ', WRONG-CONFIDENT ' . $score['wrong'],
      'Unanswerable: correct none ' . $pct($score['none_ok'], $unanswerable) . ', false positive ' . $score['none_fp'],
      sprintf('Mean time %.2fs per query', $gold ? $elapsed / count($gold) : 0),
    ]);
  }

  /**
   * Loads or removes a test pool of literals from eval/pools/NAME.yml.
   *
   * Runs as user 1. Existing literals with a pool key are replaced on load.
   * Removing deletes exactly the keys the pool file lists.
   */
  #[CLI\Command(name: 'literals:pool')]
  #[CLI\Argument(name: 'action', description: 'load or clean.')]
  #[CLI\Argument(name: 'name', description: 'Pool name: a file in eval/pools, without .yml (pool100, stress).')]
  public function pool(string $action, string $name): void {
    if (!in_array($action, ['load', 'clean'], TRUE) || !preg_match('/^[a-z0-9_]+$/', $name)) {
      throw new \InvalidArgumentException('Usage: literals:pool load|clean NAME');
    }
    $file = $this->moduleList->getPath('literals_finder') . "/eval/pools/$name.yml";
    if (!is_file($file)) {
      throw new \InvalidArgumentException("No pool file at $file");
    }
    $rows = Yaml::decode(file_get_contents($file))['literals'] ?? [];
    $storage = $this->entityTypeManager->getStorage('literal');
    $this->accountSwitcher->switchTo(User::load(1));
    try {
      $existing = $storage->loadMultiple(array_column($rows, 'id'));
      $storage->delete($existing);
      if ($action === 'clean') {
        $this->io()->success(count($existing) . ' literals removed.');
        return;
      }
      $made = 0;
      foreach ($rows as $row) {
        $literal = $storage->create([
          'type' => $row['type'],
          'bundle' => $row['type'],
          'id' => $row['id'],
          'name' => $row['name'],
          'gist' => $row['gist'],
          'value' => $row['value'],
          'restricted' => !empty($row['restricted']),
          'status' => 1,
        ]);
        $violations = $literal->validate();
        if (count($violations)) {
          $this->logger()->warning('{id}: {message}', ['id' => $row['id'], 'message' => $violations[0]->getMessage()]);
          continue;
        }
        $literal->save();
        $made++;
      }
      $this->io()->success("$made of " . count($rows) . ' literals loaded.');
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Generates a synthetic zz_ pool and a matching gold set.
   *
   * Reads eval/pools/scale.yml. The gold set is a fixed 60 sampled questions
   * plus the unanswerable ones, written to --gold. Runs as user 1.
   */
  #[CLI\Command(name: 'literals:pool-scale')]
  #[CLI\Option(name: 'count', description: 'Number of literals to create (max 225).')]
  #[CLI\Option(name: 'gold', description: 'Where to write the gold YAML.')]
  #[CLI\Option(name: 'clean', description: 'Remove the zz_ literals instead.')]
  public function poolScale(array $options = ['count' => 60, 'gold' => '/tmp/scale-gold.yml', 'clean' => FALSE]): void {
    $storage = $this->entityTypeManager->getStorage('literal');
    $this->accountSwitcher->switchTo(User::load(1));
    try {
      $old = array_filter($storage->loadMultiple(), fn ($l) => str_starts_with((string) $l->id(), 'zz_'));
      $storage->delete($old);
      if ($options['clean']) {
        $this->io()->success(count($old) . ' literals removed.');
        return;
      }
      $spec = Yaml::decode(file_get_contents($this->moduleList->getPath('literals_finder') . '/eval/pools/scale.yml'));
      $limit = (int) $options['count'];
      $gold = [];
      $count = 0;
      foreach ($spec['departments'] as $department) {
        foreach ($spec['kinds'] as $kind => $k) {
          if ($count >= $limit) {
            break 2;
          }
          $slug = 'zz_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($department)) . '_' . $kind;
          $gist = sprintf($k['gist'], $department);
          $literal = Literal::create([
            'type' => $k['type'],
            'name' => ucfirst($gist),
            'id' => $slug,
            'value' => sprintf($k['value'], $k['offset'] + $count),
            'gist' => $gist,
            'status' => 1,
          ]);
          if (count($literal->validate())) {
            continue;
          }
          $literal->save();
          foreach ($k['questions'] as $q) {
            $gold[] = ['q' => sprintf($q, strtolower($department)), 'expect' => $slug];
          }
          $count++;
        }
      }
      // A fixed sample size, whatever the pool size.
      mt_srand(42);
      shuffle($gold);
      $gold = array_slice($gold, 0, 60);
      foreach ($spec['unanswerable'] as $q) {
        $gold[] = ['q' => $q, 'expect' => 'none'];
      }
      file_put_contents($options['gold'], Yaml::encode(['queries' => $gold]));
      $this->io()->success("$count literals created, " . count($gold) . " gold questions in {$options['gold']}.");
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

}
