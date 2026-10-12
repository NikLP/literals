<?php

declare(strict_types=1);

namespace Drupal\literals\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\literals\Plugin\LiteralResolver\ContentResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Suggests content of any content type for a content literal.
 */
class ContentAutocompleteController extends ControllerBase {

  /**
   * How many suggestions to ask each entity type for.
   */
  protected const PER_TYPE = 8;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface $selection
   *   The entity reference selection plugin manager.
   */
  public function __construct(protected SelectionPluginManagerInterface $selection) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('plugin.manager.entity_reference_selection'));
  }

  /**
   * Returns matches as autocomplete suggestions, "Title (entity_type:id)".
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; the typed text is in "q", the literal type ID in "type".
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Suggestions, each with a value and a label.
   */
  public function suggest(Request $request): JsonResponse {
    $string = trim((string) $request->query->get('q'));
    $matches = [];
    $literal_type = $this->entityTypeManager()->getStorage('literal_type')->load((string) $request->query->get('type'));
    if ($string !== '' && $literal_type) {
      foreach (ContentResolver::contentTypes($this->entityTypeManager(), $literal_type->getTargetBundles()) as $type => $type_label) {
        $bundles = ContentResolver::allowedBundles($literal_type->getTargetBundles(), $type);
        $handler = $this->selection->getInstance([
          'target_type' => $type,
          'handler' => 'default',
          'target_bundles' => array_combine($bundles, $bundles),
        ]);
        foreach ($handler->getReferenceableEntities($string, 'CONTAINS', static::PER_TYPE) as $entities) {
          foreach ($entities as $id => $label) {
            $label = strip_tags((string) $label);
            $matches[] = [
              'value' => $label . ' (' . $type . ':' . $id . ')',
              'label' => $label . ' - ' . $type_label,
            ];
          }
        }
      }
    }
    return new JsonResponse($matches);
  }

}
