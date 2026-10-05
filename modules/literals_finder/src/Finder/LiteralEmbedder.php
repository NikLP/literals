<?php

declare(strict_types=1);

namespace Drupal\literals_finder\Finder;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\literals\Entity\Literal;

/**
 * Embeds gists and questions for the finder's gate.
 *
 * The vector is derived data stored on the literal row with the embedding
 * model's ID beside it, so a vector from another model is detected and
 * ignored (ADR-0040 Addendum 6).
 */
class LiteralEmbedder {

  /**
   * Constructs the embedder.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether the gate is switched on and an embedding model is configured.
   */
  public function isAvailable(): bool {
    return (bool) $this->configFactory->get('literals_finder.settings')->get('gate_enabled')
      && $this->modelId() !== NULL;
  }

  /**
   * Returns the embedding model as "provider__model", or NULL.
   */
  public function modelId(): ?string {
    $default = $this->aiProvider->getDefaultProviderForOperationType('embeddings');
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      return NULL;
    }
    return $default['provider_id'] . '__' . $default['model_id'];
  }

  /**
   * Embeds a text with the configured model.
   *
   * @param string $text
   *   The text.
   *
   * @return float[]
   *   The vector.
   *
   * @throws \RuntimeException
   *   When no embedding model is configured.
   */
  public function embed(string $text): array {
    $model = $this->modelId();
    if ($model === NULL) {
      throw new \RuntimeException('No default embeddings provider is configured.');
    }
    [$provider_id, $model_id] = explode('__', $model, 2);
    return $this->aiProvider->createInstance($provider_id)->embeddings($text, $model_id, ['literals_gate'])->getNormalized();
  }

  /**
   * Whether the literal has a vector made by the current model.
   *
   * @param \Drupal\literals\Entity\Literal $literal
   *   The literal.
   */
  public function isCurrent(Literal $literal): bool {
    return $this->vectorOf($literal) !== NULL
      && (string) $literal->get('gist_vector_model')->value === $this->modelId();
  }

  /**
   * Returns the stored gist vector, or NULL.
   *
   * @param \Drupal\literals\Entity\Literal $literal
   *   The literal.
   *
   * @return float[]|null
   *   The vector. NULL when none.
   */
  public function vectorOf(Literal $literal): ?array {
    $json = (string) $literal->get('gist_vector')->value;
    if ($json === '') {
      return NULL;
    }
    $vector = json_decode($json, TRUE);
    return is_array($vector) && $vector !== [] && !is_array($vector[0] ?? NULL) ? $vector : NULL;
  }

  /**
   * Embeds the gist and stores the vector on the literal (no save).
   *
   * @param \Drupal\literals\Entity\Literal $literal
   *   The literal.
   */
  public function embedLiteral(Literal $literal): void {
    $gist = trim((string) $literal->get('gist')->value);
    if ($gist === '') {
      $literal->set('gist_vector', NULL);
      $literal->set('gist_vector_model', NULL);
      return;
    }
    $literal->set('gist_vector', json_encode($this->embed($gist)));
    $literal->set('gist_vector_model', $this->modelId());
  }

  /**
   * Computes the cosine similarity of two vectors.
   *
   * @param float[] $a
   *   The first vector.
   * @param float[] $b
   *   The second vector.
   *
   * @return float
   *   1 identical, 0 unrelated.
   */
  public static function cosine(array $a, array $b): float {
    $dot = 0.0;
    $norm_a = 0.0;
    $norm_b = 0.0;
    foreach ($a as $i => $value) {
      $other = $b[$i] ?? 0.0;
      $dot += $value * $other;
      $norm_a += $value * $value;
      $norm_b += $other * $other;
    }
    if ($norm_a == 0.0 || $norm_b == 0.0) {
      return 0.0;
    }
    return $dot / (sqrt($norm_a) * sqrt($norm_b));
  }

}
