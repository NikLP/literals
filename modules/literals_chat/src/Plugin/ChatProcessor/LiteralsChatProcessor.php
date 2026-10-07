<?php

declare(strict_types=1);

namespace Drupal\literals_chat\Plugin\ChatProcessor;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\ChatProcessor;
use Drupal\ai\Base\ChatProcessorBase;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\literals_chat\LiteralsChatResponder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Answers the chatbot block from literals only, with no chat model.
 *
 * The finder picks a literal (a typed decision, no text generation) and the
 * reply is a fixed template around the exact resolved value.
 */
#[ChatProcessor(
  id: 'literals_chat_processor',
  label: new TranslatableMarkup('Literals'),
  description: new TranslatableMarkup('Answers only from literals: the finder picks one and the reply is a fixed template. No chat model.'),
)]
class LiteralsChatProcessor extends ChatProcessorBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the processor.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\literals_chat\LiteralsChatResponder $responder
   *   The responder.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, the asker.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected LiteralsChatResponder $responder, protected AccountInterface $currentUser) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('literals_chat.responder'), $container->get('current_user'));
  }

  /**
   * {@inheritdoc}
   */
  public function doExecute(): ChatOutput {
    $input = $this->getInput();
    if (!$input) {
      throw new \InvalidArgumentException('Input must be set before execution.');
    }
    $question = '';
    foreach (array_reverse($input->getMessages()) as $message) {
      if ($message->getRole() === 'user') {
        $question = $message->getText();
        break;
      }
    }
    $answer = $this->responder->answer($question, $this->currentUser);
    return new ChatOutput(new ChatMessage('assistant', $this->responder->toMarkdown($answer)), [], []);
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'use literals chat');
  }

}
