<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\literals_finder\Finder\LiteralEmbedder;
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
   * @param \Drupal\literals_finder\Finder\LiteralEmbedder $embedder
   *   The gist embedder.
   */
  public function __construct(
    protected LiteralFinderInterface $finder,
    protected AccountSwitcherInterface $accountSwitcher,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LiteralEmbedder $embedder,
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
      $container->get('literals_finder.embedder'),
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
   * Embeds every literal whose gist vector is missing or from another model.
   */
  #[CLI\Command(name: 'literals:embed')]
  public function embed(): void {
    $embedder = $this->embedder;
    if (!$embedder->isAvailable()) {
      throw new \RuntimeException('The gate is off (literals_finder.settings gate_enabled) or no embeddings provider is set.');
    }
    $count = 0;
    foreach ($this->entityTypeManager->getStorage('literal')->loadMultiple() as $literal) {
      if (!$embedder->isCurrent($literal)) {
        // Embed here so a provider error is raised, not swallowed by presave.
        $embedder->embedLiteral($literal);
        $literal->save();
        $count++;
      }
    }
    $this->io()->success("Embedded $count literal(s).");
  }

}
