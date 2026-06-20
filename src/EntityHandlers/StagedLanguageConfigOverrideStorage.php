<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class StagedLanguageConfigOverrideStorage extends ConfigEntityStorage {

  private AutoSaveManager $autoSaveManager;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->autoSaveManager = $container->get(AutoSaveManager::class);
    return $instance;
  }

  public function resetCache(?array $ids = NULL): void {
  }

  /**
   * {@inheritdoc}
   *
   * @param string[] $ids
   *
   * @return array<string, \Drupal\canvas\Entity\StagedLanguageConfigOverride|null>
   */
  // @phpstan-ignore-next-line method.childParameterType
  public function loadMultiple(?array $ids = NULL): array {
    if ($ids === NULL) {
      return [];
    }
    $return = [];
    foreach ($ids as $id) {
      $return[$id] = $this->load($id);
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\canvas\Entity\StagedLanguageConfigOverride|null
   */
  public function load($id) {
    \assert(\is_string($id));
    [$langcode, $config_name] = \explode('.', $id, 2);
    $stub = StagedLanguageConfigOverride::createEmpty($langcode, $config_name);
    $auto_save_entity = $this->autoSaveManager->getAutoSaveEntity($stub);
    if ($auto_save_entity->entity === NULL) {
      return NULL;
    }
    \assert($auto_save_entity->entity instanceof StagedLanguageConfigOverride);
    return $auto_save_entity->entity->enforceIsNew(FALSE);
  }

  public function loadUnchanged($id) {
    return $this->load($id);
  }

  public function loadByProperties(array $values = []): array {
    throw new \LogicException('Cannot query staged language config overrides to load by properties.');
  }

  public function delete(array $entities): void {
    foreach ($entities as $entity) {
      $this->autoSaveManager->delete($entity);
    }
  }

  public function save(EntityInterface $entity): int {
    \assert($entity instanceof StagedLanguageConfigOverride);
    $entity->enforceIsNew(FALSE);
    $entity->setOriginalId($entity->id());
    $return = SAVED_NEW;

    if ($entity->status() === TRUE) {
      \assert($this->languageManager instanceof ConfigurableLanguageManagerInterface);
      $override = $this->languageManager->getLanguageConfigOverride($entity->language()->getId(), $entity->getName());
      \assert($override instanceof LanguageConfigOverride);
      if ($entity->isEmpty()) {
        $override->delete();
      }
      else {
        $data = $entity->getData();
        \assert(\is_array($data));
        foreach ($data as $key => $value) {
          $override->set($key, $value);
        }
        $override->save();
      }
      return SAVED_UPDATED;
    }

    $existing = $this->autoSaveManager->getAutoSaveEntity($entity);
    if ($existing->entity instanceof StagedLanguageConfigOverride) {
      $return = SAVED_UPDATED;
    }

    $this->autoSaveManager->saveEntity($entity);
    return $return;
  }

  public function restore(EntityInterface $entity): void {
  }

  public function hasData(): bool {
    return FALSE;
  }

  public function getQuery($conjunction = 'AND') {
    throw new \LogicException('Cannot query staged language config overrides.');
  }

  public function getAggregateQuery($conjunction = 'AND') {
    throw new \LogicException('Cannot query staged language config overrides.');
  }

}
