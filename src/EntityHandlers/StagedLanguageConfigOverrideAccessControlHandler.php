<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\Access\CanvasUiAccessCheck;
use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class StagedLanguageConfigOverrideAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  private array $typesByPrefix = [];

  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CanvasUiAccessCheck $canvasUiAccessCheck,
  ) {
    parent::__construct($entity_type);
    foreach ($this->entityTypeManager->getDefinitions() as $definition) {
      if ($definition->entityClassImplements(ConfigEntityInterface::class)) {
        /** @var \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $definition */
        $prefix = $definition->getConfigPrefix();
        $this->typesByPrefix[$prefix] = $definition->id();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    return new self(
      $entity_type,
      $container->get(EntityTypeManagerInterface::class),
      $container->get(CanvasUiAccessCheck::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    \assert($entity instanceof StagedLanguageConfigOverride);
    return match ($operation) {
      'view' => $this->canvasUiAccessCheck->access($account),
      default => $this->checkTargetUpdateAccess($entity->getName(), $account),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return $this->checkTargetUpdateAccess($context['config_name'], $account);
  }

  private function checkTargetUpdateAccess(string $config_name, AccountInterface $account): AccessResultInterface {
    $config_name_parts = \explode('.', $config_name, 3);
    $config_prefix = "$config_name_parts[0].$config_name_parts[1]";
    if (!\array_key_exists($config_prefix, $this->typesByPrefix)) {
      return AccessResult::forbidden("Unsupported configuration object '$config_name'.");
    }
    $entity_type_id = $this->typesByPrefix[$config_prefix];
    $entity_id = $config_name_parts[2];
    $loaded_target = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
    if ($loaded_target === NULL) {
      return AccessResult::forbidden("Target configuration entity '$entity_id' of type '$entity_type_id' does not exist.");
    }
    return $this->entityTypeManager->getAccessControlHandler($entity_type_id)
      ->access($loaded_target, 'update', $account, TRUE);
  }

}
