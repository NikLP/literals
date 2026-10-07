<?php

declare(strict_types=1);

namespace Drupal\literals\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\literals\LiteralSearch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Autocomplete endpoint over literals.
 */
class LiteralSearchController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * @param \Drupal\literals\LiteralSearch $search
   *   The literal search.
   */
  public function __construct(protected LiteralSearch $search) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('literals.search'));
  }

  /**
   * Returns autocomplete suggestions: the key as value, name and gist as label.
   *
   * Never the literal's value.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; the typed text is the "q" query parameter.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   Suggestions.
   */
  public function autocomplete(Request $request): CacheableJsonResponse {
    $metadata = new CacheableMetadata();
    $metadata->addCacheContexts(['url.query_args:q', 'user.permissions', 'user.roles:authenticated']);
    $metadata->addCacheTags(['literal_list']);
    $suggestions = [];
    foreach ($this->search->search((string) $request->query->get('q', ''), NULL, 10) as $literal) {
      $metadata->addCacheableDependency($literal);
      $gist = $literal->getGist();
      $suggestions[] = [
        'value' => (string) $literal->get('key')->value,
        'label' => Html::escape((string) $literal->label()) . ($gist !== '' ? ' - ' . Html::escape(Unicode::truncate($gist, 80, TRUE, TRUE)) : ''),
      ];
    }
    $response = new CacheableJsonResponse($suggestions);
    $response->addCacheableDependency($metadata);
    return $response;
  }

}
