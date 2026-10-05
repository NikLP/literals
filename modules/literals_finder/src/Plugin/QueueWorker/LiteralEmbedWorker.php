<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals_finder\Finder\LiteralEmbedder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-embeds a literal whose gist vector is missing or from another model.
 */
#[QueueWorker(
  id: 'literals_embed',
  title: new TranslatableMarkup('Literal gist embedding'),
  cron: ['time' => 30],
)]
class LiteralEmbedWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the worker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\literals_finder\Finder\LiteralEmbedder $embedder
   *   The embedder.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LiteralEmbedder $embedder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get('literals_finder.embedder'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $literal = $this->entityTypeManager->getStorage('literal')->load($data['id']);
    if ($literal && $this->embedder->isAvailable() && !$this->embedder->isCurrent($literal)) {
      // The presave hook embeds a literal that has no current vector.
      $literal->save();
    }
  }

}
