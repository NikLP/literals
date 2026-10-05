<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\literals\Attribute\LiteralKind;

/**
 * Plugin manager for literal kinds.
 */
class LiteralKindManager extends DefaultPluginManager {

  /**
   * Constructs the manager.
   *
   * @param \Traversable $namespaces
   *   Namespaces to search for plugins.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/LiteralKind', $namespaces, $module_handler, LiteralKindInterface::class, LiteralKind::class);
    $this->alterInfo('literal_kind_info');
    $this->setCacheBackend($cache_backend, 'literal_kind_plugins');
  }

  /**
   * Returns the kind options for a select element.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Plugin labels keyed by ID.
   */
  public function getOptions(): array {
    $options = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      $options[$id] = $definition['label'];
    }
    return $options;
  }

}
