<?php

declare(strict_types=1);

namespace Drupal\literals\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add and edit form for a literal.
 *
 * Laid out like the node form: the content on the left, status, revision
 * information and authoring in the sidebar (rendered by Gin for routes
 * registered in LiteralsHooks).
 */
class LiteralForm extends ContentEntityForm {

  /**
   * The key-value factory.
   */
  protected KeyValueFactoryInterface $keyValueFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->keyValueFactory = $container->get('keyvalue');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\literals\Entity\Literal $literal */
    $literal = $this->entity;

    // The key is generated from the name, as a config entity's machine name is.
    if (isset($form['key']['widget'][0]['value'])) {
      $element = &$form['key']['widget'][0]['value'];
      $element['#type'] = 'machine_name';
      $element['#machine_name'] = [
        'source' => ['name', 'widget', 0, 'value'],
        'exists' => [$this, 'keyExists'],
        'replace_pattern' => '[^a-z0-9_]+',
        'replace' => '_',
      ];
      $element['#disabled'] = !$literal->isNew();
      unset($element);
    }

    $resolver = $literal->getType()->getResolver();
    if ($resolver === 'token' && isset($form['value'])) {
      // The token browser comes from the contrib Token module, if present.
      if ($this->moduleHandler->moduleExists('token')) {
        $form['value']['token_help'] = [
          '#theme' => 'token_tree_link',
          '#token_types' => 'all',
          '#global_types' => TRUE,
          '#weight' => 10,
        ];
      }
      else {
        $form['value']['widget'][0]['value']['#description'] = $this->t('Install the Token module for a token browser.');
      }
    }
    if ($resolver === 'url' && $this->moduleHandler->moduleExists('node') && isset($form['value']['widget'][0]['value'])) {
      $this->addContentAutocomplete($form['value']['widget'][0]['value']);
    }

    $form['advanced'] = [
      '#type' => 'vertical_tabs',
      '#weight' => 99,
    ];
    $form['meta'] = [
      '#type' => 'container',
      '#group' => 'advanced',
      '#weight' => -10,
      '#title' => $this->t('Status'),
      '#attributes' => ['class' => ['entity-meta__header']],
      '#tree' => TRUE,
    ];
    $form['meta']['published'] = [
      '#type' => 'item',
      '#markup' => $literal->isPublished() ? $this->t('Published') : $this->t('Not published'),
      '#access' => !$literal->isNew(),
    ];
    $form['meta']['author'] = [
      '#type' => 'item',
      '#title' => $this->t('Author'),
      '#markup' => $literal->getOwner()?->getAccountName() ?? $this->t('Anonymous'),
    ];

    $form['revision_information']['#group'] = 'meta';
    $form['revision_information']['#weight'] = 20;

    return $form;
  }

  /**
   * Machine name callback: whether the key is taken.
   *
   * @param string $key
   *   The key to look up.
   * @param array $element
   *   The machine name element.
   *
   * @return bool
   *   TRUE if another literal already uses the key.
   */
  public function keyExists(string $key, array $element): bool {
    $literal = $this->entity;
    $query = $this->entityTypeManager->getStorage('literal')->getQuery()
      ->accessCheck(FALSE)
      ->condition('key', $key);
    if (!$literal->isNew()) {
      $query->condition('id', $literal->id(), '<>');
    }
    return (bool) $query->range(0, 1)->execute();
  }

  /**
   * Turns the value element into a text field that suggests content.
   *
   * Typing a title offers matching content; picking one stores its path. A
   * typed URL or path is kept as typed.
   *
   * @param array $element
   *   The value form element.
   */
  protected function addContentAutocomplete(array &$element): void {
    // Same registration core's entity_autocomplete element does, so the
    // stock route serves the suggestions.
    $settings = [];
    $key = Crypt::hmacBase64(serialize($settings) . 'nodedefault:node', Settings::getHashSalt());
    $store = $this->keyValueFactory->get('entity_autocomplete');
    if (!$store->has($key)) {
      $store->set($key, $settings);
    }
    $element['#type'] = 'textfield';
    $element['#maxlength'] = 2048;
    $element['#size'] = 60;
    $element['#autocomplete_route_name'] = 'system.entity_autocomplete';
    $element['#autocomplete_route_parameters'] = [
      'target_type' => 'node',
      'selection_handler' => 'default:node',
      'selection_settings_key' => $key,
    ];
    $element['#description'] = $this->t('Start typing a page title, or enter an internal path such as /news.');
    $element['#element_validate'][] = [static::class, 'pickedContentToPath'];
  }

  /**
   * Element validate: turns "Title (12)" into the path /node/12.
   *
   * @param array $element
   *   The value form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function pickedContentToPath(array &$element, FormStateInterface $form_state): void {
    $value = trim((string) $element['#value']);
    if (preg_match('/\((\d+)\)$/', $value, $matches)) {
      $form_state->setValueForElement($element, '/node/' . $matches[1]);
    }
  }

}
