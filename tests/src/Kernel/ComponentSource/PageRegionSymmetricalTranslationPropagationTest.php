<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore Hola opcional

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests component instance update propagation to PageRegion translations.
 */
#[CoversClass(ComponentSourceManager::class)]
#[CoversClass(StagedLanguageConfigOverrideStorage::class)]
#[CoversMethod(ComponentTreeItemList::class, 'reconcileTranslationsWithUpdatedItems')]
#[CoversMethod(ComponentTreeItemList::class, 'reconcileConfigEntityTranslations')]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
final class PageRegionSymmetricalTranslationPropagationTest extends ConfigEntitySymmetricalTranslationPropagationTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service('theme_installer')->install(['stark']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');

    $this->translatedConfigEntity = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => self::populateActiveComponentVersionPlaceholders($this->translatableComponentTree),
    ]);
    self::assertEntityIsValid($this->translatedConfigEntity);
    self::assertSame(SAVED_NEW, $this->translatedConfigEntity->save());
  }

}
