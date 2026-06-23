<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore Hola opcional

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests component instance update propagation to PageRegion translations.
 */
#[CoversClass(ComponentSourceManager::class)]
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

  /**
   * {@inheritdoc}
   *
   * Stores only the translatable subset: both `required_text` and `optional_text`
   * are translatable strings, matching the full override scenario.
   */
  protected function writeSpanishOverride(array $inputs): void {
    $language_manager = \Drupal::languageManager();
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $override = $language_manager->getLanguageConfigOverride('es', $this->translatedConfigEntity->getConfigDependencyName());
    \assert($override instanceof LanguageConfigOverride);
    $override->set('component_tree', [
      static::TRANSLATED_COMPONENT_INSTANCE_UUID => [
        'inputs' => $inputs,
      ],
    ]);
    $override->save();
  }

}
