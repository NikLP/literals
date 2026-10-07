<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\literals\Entity\Literal;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for literal resolvers.
 */
abstract class LiteralResolverBase extends PluginBase implements LiteralResolverInterface, ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function resolveItem(Literal $literal, AccountInterface $account, CacheableMetadata $metadata): ?ResolvedLiteral {
    $value = $this->resolve($literal, $account, $metadata);
    if ($value === NULL) {
      return NULL;
    }
    return new ResolvedLiteral($value, $this->label($literal), $this->kind($literal));
  }

  /**
   * Returns the label for a resolved literal. Defaults to the literal's name.
   *
   * @param \Drupal\literals\Entity\Literal $literal
   *   The literal.
   *
   * @return string
   *   The label.
   */
  protected function label(Literal $literal): string {
    return (string) $literal->label();
  }

  /**
   * Returns the kind of value this resolver produces for a literal.
   *
   * @param \Drupal\literals\Entity\Literal $literal
   *   The literal.
   *
   * @return string
   *   One of the ResolvedLiteral::KIND_* constants.
   */
  protected function kind(Literal $literal): string {
    return ResolvedLiteral::KIND_TEXT;
  }

}
