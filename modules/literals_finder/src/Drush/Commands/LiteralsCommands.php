<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Drush\Commands;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\literals\Entity\Literal;
use Drupal\literals_finder\Finder\LiteralChooser;
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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected LiteralFinderInterface $finder,
    protected AccountSwitcherInterface $accountSwitcher,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleExtensionList $moduleList,
    protected ConfigFactoryInterface $configFactory,
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
      $container->get('config.factory'),
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
   * Clears the outcome cache first. Reports per-query results with the
   * chooser's scores, then recall, misses, wrong-confident matches (the
   * costly kind: a wrong value served) and time. Never prints values. With
   * --dump, writes each row as JSON for an offline literals:sweep.
   */
  #[CLI\Command(name: 'literals:eval')]
  #[CLI\Argument(name: 'file', description: 'YAML gold set (default: the module\'s eval/gold.seed.yml).')]
  #[CLI\Option(name: 'dump', description: 'Write each row (question, expected, scores, verdict) as JSON to this file.')]
  #[CLI\Usage(name: 'drush literals:eval eval/gold.blind.yml --dump=/tmp/run1/blind.json', description: 'Score a set and keep its rows for literals:sweep.')]
  public function evaluate(?string $file = NULL, array $options = ['dump' => NULL]): void {
    $file ??= $this->moduleList->getPath('literals_finder') . '/eval/gold.seed.yml';
    $gold = Yaml::decode(file_get_contents($file))['queries'] ?? [];
    Cache::invalidateTags(['literal_list']);

    $score = ['hit' => 0, 'miss' => 0, 'wrong' => 0, 'ambiguous' => 0, 'none_ok' => 0, 'none_fp' => 0];
    $elapsed = 0.0;
    $rows = [];
    $dump = [];
    foreach ($gold as $item) {
      $account = $this->evalAccount($item['as'] ?? 0);
      if (!$account) {
        $this->logger()->warning('Skipped "{q}": no user {as}.', ['q' => $item['q'], 'as' => $item['as']]);
        continue;
      }
      $start = microtime(TRUE);
      $result = $this->finder->find($item['q'], $account);
      $took = microtime(TRUE) - $start;
      $elapsed += $took;
      $keys = array_map(fn ($l) => $l->id(), $result->literals);
      $verdict = $this->verdict($item['expect'], $result->outcome, $keys);
      $score[$verdict]++;
      $expect = is_array($item['expect']) ? implode(' or ', $item['expect']) : (string) $item['expect'];
      $got = $result->outcome . ($keys ? ' ' . implode('|', $keys) : '');
      $tier = $result->tier . ($result->reason ? "/$result->reason" : '');
      $rows[] = [
        $verdict,
        $item['q'],
        $expect,
        $got,
        $tier,
        $this->formatScores($result->scores),
        sprintf('%.2fs', $took),
      ];
      $dump[] = [
        'q' => $item['q'],
        'as' => $item['as'] ?? 0,
        'expect' => $item['expect'],
        'outcome' => $result->outcome,
        'keys' => $keys,
        'tier' => $result->tier,
        'reason' => $result->reason,
        'verdict' => $verdict,
        'scores' => $result->scores,
        'unseen' => $this->unseen($item['expect'], $account),
        'time' => round($took, 3),
      ];
    }

    $this->io()->table(['', 'Question', 'Expected', 'Got', 'Tier', 'Scores (top, next, none)', 'Time'], $rows);
    $answerable = $score['hit'] + $score['miss'] + $score['wrong'] + $score['ambiguous'];
    $unanswerable = $score['none_ok'] + $score['none_fp'];
    $pct = fn (int $n, int $d) => $d ? sprintf('%d/%d (%d%%)', $n, $d, round(100 * $n / $d)) : '-';
    $this->io()->writeln([
      'Answerable:   hit ' . $pct($score['hit'], $answerable) . ', miss ' . $score['miss'] . ', ambiguous ' . $score['ambiguous'] . ', WRONG-CONFIDENT ' . $score['wrong'],
      'Unanswerable: correct none ' . $pct($score['none_ok'], $unanswerable) . ', false positive ' . $score['none_fp'],
      sprintf('Mean time %.2fs per query', $dump ? $elapsed / count($dump) : 0),
    ]);

    if ($options['dump']) {
      $settings = $this->configFactory->get('literals_finder.settings');
      $data = [
        'set' => basename($file, '.yml'),
        'date' => date('c'),
        'settings' => [
          'match_threshold' => (float) $settings->get('match_threshold'),
          'choice_margin' => (float) $settings->get('choice_margin'),
        ],
        'rows' => $dump,
      ];
      if (file_put_contents($options['dump'], json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === FALSE) {
        throw new \RuntimeException("Could not write {$options['dump']}");
      }
      $this->io()->writeln("Rows written to {$options['dump']}");
    }
  }

  /**
   * Replays literals:eval dumps under other thresholds, with no model calls.
   *
   * Each row's recorded scores go back through the chooser's own rule
   * (LiteralChooser::rule()) at every match_threshold and choice_margin step.
   * Reports right answers served, wrong answers served, unanswerable
   * questions answered and right answers lost (to none or a tie), a
   * calibration table (top probability against how often that pick was
   * right), confident picks that look like a stale gold label rather than a
   * model error, and questions whose expected key the asker cannot see. With
   * --against, compares two runs of the same sets.
   */
  #[CLI\Command(name: 'literals:sweep')]
  #[CLI\Argument(name: 'dumps', description: 'A literals:eval --dump file, or a directory of them read as one run.')]
  #[CLI\Option(name: 'thresholds', description: 'match_threshold steps, from:to:step.')]
  #[CLI\Option(name: 'margins', description: 'choice_margin steps, from:to:step.')]
  #[CLI\Option(name: 'grid', description: 'Report every threshold and margin pair, not one axis at a time.')]
  #[CLI\Option(name: 'against', description: 'A second run (file or directory) of the same sets, to check stability.')]
  #[CLI\Option(name: 'confident', description: 'Least top probability that counts as confident for the stale-label flags.')]
  #[CLI\Option(name: 'pool', description: 'Key prefix of literals added after the gold sets were written (empty: any).')]
  #[CLI\Option(name: 'drop-stale', description: 'Leave the stale-label suspects out of the threshold and margin tables.')]
  #[CLI\Option(name: 'older', description: 'Key prefix of an older pool whose expectations the new pool may have overtaken.')]
  #[CLI\Usage(name: 'drush literals:sweep /tmp/run1 --against=/tmp/run2', description: 'Sweep run 1, then compare it with run 2.')]
  public function sweep(
    string $dumps,
    array $options = [
      'thresholds' => '0.3:0.9:0.05',
      'margins' => '0:0.4:0.05',
      'grid' => FALSE,
      'against' => NULL,
      'confident' => 0.8,
      'pool' => 'pl_',
      'older' => 'st_',
      'drop-stale' => FALSE,
    ],
  ): void {
    $run = $this->loadDumps($dumps);
    $settings = $this->configFactory->get('literals_finder.settings');
    $threshold = (float) $settings->get('match_threshold');
    $margin = (float) $settings->get('choice_margin');

    // A failed model call says nothing about any threshold.
    $errors = array_filter($run, fn ($row) => $row['reason'] === 'error');
    $run = array_values(array_diff_key($run, $errors));
    $scored = count(array_filter($run, fn ($row) => !empty($row['scores']['top'])));
    $models = array_unique(array_filter(array_map(fn ($row) => $row['scores']['model'] ?? NULL, $run)));
    $sets = array_unique(array_column($run, 'set'));
    $this->io()->writeln(sprintf('%d rows from %s; %d scored by %s; %d model errors left out.', count($run), implode(', ', $sets), $scored, implode(', ', $models) ?: '-', count($errors)));

    // The replay must reproduce each row's live verdict at its own settings.
    $mismatch = 0;
    foreach ($run as $row) {
      if ($this->replay($row, $row['settings']['match_threshold'], $row['settings']['choice_margin']) !== $row['verdict']) {
        $mismatch++;
      }
    }
    $this->io()->writeln(sprintf('Replay check: %d of %d rows reproduce their live verdict.', count($run) - $mismatch, count($run)));

    // Likely stale labels: a confident pick of a newer pool's literal where
    // the gold set expects none, a key the asker could not see in this run,
    // or an older pool's key. Separately, a question whose only answer is a
    // key the asker cannot see is lost or wrong at every setting.
    $stale = [];
    $unanswerable = [];
    foreach ($run as $i => $row) {
      $top = $row['scores']['top'] ?? NULL;
      $expected = $this->expectedKeys($row['expect']);
      if ($expected && $expected === $row['unseen'] && !in_array('none', array_map('strval', (array) $row['expect']), TRUE)) {
        $unanswerable[$i] = $row;
      }
      if (!$top || $top[1] < (float) $options['confident'] || in_array($top[0], $expected, TRUE) || !str_starts_with($top[0], (string) $options['pool'])) {
        continue;
      }
      $why = match (TRUE) {
        $expected === [] => 'expects none',
        $expected === $row['unseen'] => 'expected key not visible',
        $options['older'] !== '' && $expected === array_filter($expected, fn ($k) => str_starts_with($k, (string) $options['older'])) => 'expects ' . $options['older'] . '*',
        default => NULL,
      };
      if ($why) {
        $pick = sprintf('%s %.2f', $top[0], $top[1]);
        $stale[$i] = [$row['set'], $row['q'], $this->expectLabel($row['expect']), $pick, $why];
      }
    }
    $header = ['Threshold', 'Margin', 'Right', 'Wrong', 'False pos.', 'Lost', 'None ok'];
    // Set aside the stale-label suspects to read the sweep as if relabeled.
    $suspects = $stale + $unanswerable;
    $swept = $options['drop-stale'] ? array_diff_key($run, $suspects) : $run;
    $line = function (float $t, float $m) use ($swept, $threshold, $margin): array {
      $count = array_fill_keys(['hit', 'wrong', 'none_fp', 'lost', 'none_ok'], 0);
      foreach ($swept as $row) {
        $verdict = $this->replay($row, $t, $m);
        $count[in_array($verdict, ['miss', 'ambiguous'], TRUE) ? 'lost' : $verdict]++;
      }
      $mark = abs($t - $threshold) < 1e-9 && abs($m - $margin) < 1e-9 ? ' *' : '';
      return [sprintf('%.2f%s', $t, $mark), sprintf('%.2f', $m), ...array_values($count)];
    };
    $thresholds = $this->steps($options['thresholds']);
    $margins = $this->steps($options['margins']);
    if ($options['drop-stale']) {
      $this->io()->writeln(sprintf('Sweeping %d rows: %d stale-label suspects set aside.', count($swept), count($suspects)));
    }
    if ($options['grid']) {
      $table = [];
      foreach ($thresholds as $t) {
        foreach ($margins as $m) {
          $table[] = $line($t, $m);
        }
      }
      $this->io()->section('Every threshold and margin (* current settings)');
      $this->io()->table($header, $table);
    }
    else {
      $this->io()->section(sprintf('match_threshold, margin fixed at %.2f (* current settings)', $margin));
      $this->io()->table($header, array_map(fn ($t) => $line($t, $margin), $thresholds));
      $this->io()->section(sprintf('choice_margin, threshold fixed at %.2f', $threshold));
      $this->io()->table($header, array_map(fn ($m) => $line($threshold, $m), $margins));
    }

    $this->io()->section(sprintf('Likely stale gold labels: %d confident (>= %.2f) %s* picks', count($stale), $options['confident'], $options['pool']));
    if ($stale) {
      $this->io()->table(['Set', 'Question', 'Expected', 'Pick', 'Why'], array_values($stale));
    }
    if ($unanswerable) {
      $keys = array_count_values(array_merge(...array_map(fn ($row) => $row['unseen'], $unanswerable)));
      arsort($keys);
      $list = implode(', ', array_map(fn ($key, $n) => "$key x$n", array_keys($keys), $keys));
      $this->io()->writeln(sprintf('%d questions expect only keys the asker cannot see, so are lost or wrong at every setting: %s.', count($unanswerable), $list));
    }

    // Calibration: does a higher top probability mean more often right?
    $bins = [0.0, 0.5, 0.6, 0.7, 0.8, 0.9, 0.95, 0.99];
    $table = [];
    foreach ($bins as $b => $low) {
      $high = $bins[$b + 1] ?? 1.01;
      $in = array_filter($run, fn ($row) => !empty($row['scores']['top']) && $row['scores']['top'][1] >= $low && $row['scores']['top'][1] < $high);
      if (!$in) {
        continue;
      }
      $right = fn (array $rows) => count(array_filter($rows, fn ($row) => in_array($row['scores']['top'][0], $this->expectedKeys($row['expect']), TRUE)));
      $clean = array_diff_key($in, $stale, $unanswerable);
      $mean = array_sum(array_map(fn ($row) => $row['scores']['top'][1], $in)) / count($in);
      $table[] = [
        sprintf('%.2f-%.2f', $low, min($high, 1.0)),
        count($in),
        sprintf('%.2f', $mean),
        sprintf('%d (%d%%)', $right($in), round(100 * $right($in) / count($in))),
        $clean ? sprintf('%d/%d (%d%%)', $right($clean), count($clean), round(100 * $right($clean) / count($clean))) : '-',
      ];
    }
    $this->io()->section('Calibration: top literal pick by its probability');
    $this->io()->table(['Top p', 'Rows', 'Mean p', 'Pick right', 'Without stale flags'], $table);

    if ($options['against']) {
      $this->compareRuns($run, $this->loadDumps($options['against']), $threshold, $margin);
    }
  }

  /**
   * Prints how far a second run of the same questions agrees with the first.
   *
   * @param array $first
   *   The first run's rows.
   * @param array $second
   *   The second run's rows.
   * @param float $threshold
   *   The match threshold to judge verdicts at.
   * @param float $margin
   *   The choice margin to judge verdicts at.
   */
  protected function compareRuns(array $first, array $second, float $threshold, float $margin): void {
    $id = fn (array $row) => $row['set'] . '|' . $row['as'] . '|' . $row['q'];
    $other = [];
    foreach ($second as $row) {
      $other[$id($row)] = $row;
    }
    $pairs = 0;
    $changed = [];
    $flipped = 0;
    $drift = [];
    foreach ($first as $a) {
      if (!$b = $other[$id($a)] ?? NULL) {
        continue;
      }
      $pairs++;
      $va = $this->replay($a, $threshold, $margin);
      $vb = $this->replay($b, $threshold, $margin);
      $ta = $a['scores']['top'] ?? NULL;
      $tb = $b['scores']['top'] ?? NULL;
      if ($ta && $tb && $ta[0] === $tb[0]) {
        $drift[] = abs($ta[1] - $tb[1]);
      }
      elseif ($ta || $tb) {
        $flipped++;
      }
      if ($va !== $vb || ($ta[0] ?? NULL) !== ($tb[0] ?? NULL)) {
        $show = fn ($v, $t) => $v . ($t ? sprintf(' %s %.2f', $t[0], $t[1]) : '');
        $changed[] = [$a['set'], $a['q'], $show($va, $ta), $show($vb, $tb)];
      }
    }
    sort($drift);
    $this->io()->section('Stability against the second run');
    $this->io()->writeln([
      sprintf('%d questions in both runs; %d changed verdict or top pick; top pick changed on %d.', $pairs, count($changed), $flipped),
      $drift ? sprintf('Same top pick: probability moved by median %.3f, max %.3f.', $drift[intdiv(count($drift), 2)], end($drift)) : '',
    ]);
    if ($changed) {
      $this->io()->table(['Set', 'Question', 'First run', 'Second run'], $changed);
    }
  }

  /**
   * Replays one dumped row at a threshold and margin and gives its verdict.
   *
   * @param array $row
   *   A dumped row.
   * @param float $threshold
   *   The match threshold.
   * @param float $margin
   *   The choice margin.
   *
   * @return string
   *   The verdict, as literals:eval gives it.
   */
  protected function replay(array $row, float $threshold, float $margin): string {
    // Nothing scored (no candidates, no backend): the outcome is fixed.
    if (empty($row['scores']['top'])) {
      return $this->verdict($row['expect'], $row['outcome'], $row['keys']);
    }
    $probabilities = LiteralChooser::probabilitiesFromScores($row['scores']);
    $candidates = array_filter([$row['scores']['top'][0], $row['scores']['next'][0] ?? NULL]);
    $decision = LiteralChooser::rule($probabilities, $candidates, $threshold, $margin);
    return $this->verdict($row['expect'], $decision['outcome'], $decision['keys']);
  }

  /**
   * Scores one outcome against a gold expectation.
   *
   * @param mixed $expect
   *   A literal key, "none", "ambiguous", or a list of acceptable answers
   *   (keys, "none", and "ambiguous" for a tie).
   * @param string $outcome
   *   The finder outcome.
   * @param string[] $keys
   *   The matched or tied literal keys.
   *
   * @return string
   *   For an answerable question "hit", "miss", "ambiguous" or "wrong"; for
   *   an unanswerable one "none_ok" or "none_fp".
   */
  protected function verdict(mixed $expect, string $outcome, array $keys): string {
    // In a list nothing is a miss, a pick outside it is wrong.
    if (is_array($expect)) {
      $accepted = array_map('strval', $expect);
      return match (TRUE) {
        $outcome === LiteralFindResult::MATCH && count($keys) === 1 && in_array($keys[0], $accepted, TRUE) => 'hit',
        $outcome === LiteralFindResult::AMBIGUOUS && in_array('ambiguous', $accepted, TRUE) => 'hit',
        $outcome === LiteralFindResult::NONE && in_array('none', $accepted, TRUE) => 'hit',
        $outcome === LiteralFindResult::MATCH => 'wrong',
        $outcome === LiteralFindResult::AMBIGUOUS => 'ambiguous',
        default => 'miss',
      };
    }
    $expect = (string) $expect;
    if ($expect === 'none' || $expect === 'ambiguous') {
      // Unanswerable, or deliberately vague: showing a tie or nothing is
      // right, picking one literal is the false positive.
      $ok = $outcome === LiteralFindResult::NONE
        || ($expect === 'ambiguous' && $outcome === LiteralFindResult::AMBIGUOUS);
      return $ok ? 'none_ok' : 'none_fp';
    }
    return match (TRUE) {
      $outcome === LiteralFindResult::MATCH && $keys === [$expect] => 'hit',
      $outcome === LiteralFindResult::MATCH => 'wrong',
      $outcome === LiteralFindResult::AMBIGUOUS => 'ambiguous',
      default => 'miss',
    };
  }

  /**
   * Gives the literal keys a gold expectation accepts, without none or a tie.
   *
   * @param mixed $expect
   *   The expectation.
   *
   * @return string[]
   *   The keys.
   */
  protected function expectedKeys(mixed $expect): array {
    return array_values(array_diff(array_map('strval', (array) $expect), ['none', 'ambiguous']));
  }

  /**
   * Formats an expectation for a table cell.
   *
   * @param mixed $expect
   *   The expectation.
   */
  protected function expectLabel(mixed $expect): string {
    return implode(' or ', array_map('strval', (array) $expect));
  }

  /**
   * Lists the expected keys the asker cannot find in this pool.
   *
   * A literal that is missing, unpublished, without a gist or not viewable
   * is never offered to the chooser, so a gold label naming it cannot hit.
   *
   * @param mixed $expect
   *   The expectation.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The asker.
   *
   * @return string[]
   *   The unseen keys.
   */
  protected function unseen(mixed $expect, AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('literal');
    $unseen = [];
    foreach ($this->expectedKeys($expect) as $key) {
      /** @var \Drupal\literals\Entity\Literal|null $literal */
      $literal = $storage->load($key);
      if (!$literal || !$literal->isPublished() || !$literal->getGist() || !$literal->access('view', $account)) {
        $unseen[] = $key;
      }
    }
    return $unseen;
  }

  /**
   * Loads the account a gold query asks as: a user ID, 0, or a user name.
   *
   * @param int|string $as
   *   The query's "as" value.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   The account, or NULL when no such user exists.
   */
  protected function evalAccount(int|string $as): ?AccountInterface {
    if (is_numeric($as)) {
      return (int) $as === 0 ? new AnonymousUserSession() : User::load((int) $as);
    }
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $as]);
    return $users ? reset($users) : NULL;
  }

  /**
   * Formats chooser scores for the eval table: "a 0.92, b 0.04, none 0.01".
   *
   * @param array $scores
   *   The scores, as LiteralFindResult describes them.
   */
  protected function formatScores(array $scores): string {
    if (!$scores) {
      return '-';
    }
    $parts = [];
    foreach (['top', 'next'] as $rank) {
      if (!empty($scores[$rank])) {
        $parts[] = sprintf('%s %.2f', $scores[$rank][0], $scores[$rank][1]);
      }
    }
    $parts[] = sprintf('none %.2f', $scores['none'] ?? 0.0);
    return implode(', ', $parts);
  }

  /**
   * Reads literals:eval dumps: one file, or every .json file in a directory.
   *
   * @param string $path
   *   The file or directory.
   *
   * @return array
   *   The rows, each with its set name and the settings it ran at.
   */
  protected function loadDumps(string $path): array {
    $files = is_dir($path) ? glob(rtrim($path, '/') . '/*.json') : [$path];
    $rows = [];
    foreach ($files as $file) {
      $data = json_decode((string) @file_get_contents($file), TRUE);
      if (!is_array($data) || !isset($data['rows'])) {
        throw new \InvalidArgumentException("$file is not a literals:eval dump.");
      }
      foreach ($data['rows'] as $row) {
        $rows[] = $row + ['set' => $data['set'], 'settings' => $data['settings']];
      }
    }
    if (!$rows) {
      throw new \InvalidArgumentException("No dumped rows in $path.");
    }
    return $rows;
  }

  /**
   * Expands "from:to:step" into the values it names.
   *
   * @param string $spec
   *   The steps, for example "0.3:0.9:0.05".
   *
   * @return float[]
   *   The values.
   */
  protected function steps(string $spec): array {
    [$from, $to, $step] = array_map('floatval', explode(':', $spec)) + [0.0, 0.0, 0.0];
    if ($step <= 0) {
      return [$from];
    }
    return array_map(fn ($i) => round($from + $i * $step, 4), range(0, (int) round(($to - $from) / $step)));
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
