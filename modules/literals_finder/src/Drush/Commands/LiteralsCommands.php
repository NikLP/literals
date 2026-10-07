<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Drush\Commands;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
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
      $this->io()->writeln(sprintf('  %s: %s', $literal->get('key')->value, $literal->get('gist')->value));
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
      $keys = array_map(fn ($l) => (string) $l->get('key')->value, $result->literals);
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

}
