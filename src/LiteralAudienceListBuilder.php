<?php

declare(strict_types=1);

namespace Drupal\literals;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists literal audiences with the permission that grants each.
 */
class LiteralAudienceListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Name');
    $header['id'] = $this->t('Machine name');
    $header['permission'] = $this->t('Granted by permission');
    $header['description'] = $this->t('Description');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\literals\Entity\Audience $entity */
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['permission'] = $entity->getPermissionName();
    $row['description'] = $entity->getDescription();
    return $row + parent::buildRow($entity);
  }

}
