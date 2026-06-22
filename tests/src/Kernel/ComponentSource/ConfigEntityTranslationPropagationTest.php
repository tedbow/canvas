<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore Hallo mundo Hola opcional optionnel

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests component instance version update propagation to config translations.
 *
 * Validates that after the default (English) component tree is updated via
 * ComponentSourceManager::updateComponentInstances(), each language's
 * LanguageConfigOverride is reconciled: deleted-prop keys are pruned,
 * and orphan-free overrides are deleted in their entirety.
 *
 * Config entity translations store only the translatable subset of inputs in
 * LanguageConfigOverride records (sparse, not a full copy). Propagation
 * therefore operates at the override level, not at the entity level.
 */
#[CoversClass(ComponentSourceManager::class)]
#[CoversClass(ComponentTreeItemList::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
final class ConfigEntityTranslationPropagationTest extends TranslationPropagationTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // TRICKY: no extra modules are needed: Drupal core provides the no-UI
    // translation infrastructure in the `language` module.
    // @see \Drupal\language\Config\LanguageConfigFactoryOverride
  ];

  private const string COMPONENT_UUID = '22222222-2222-4222-8222-222222222222';

  private PageRegion $pageRegion;

  /**
   * {@inheritdoc}
   */
  protected static function componentMachineName(): string {
    return 'config_prop_propagation_test';
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service('theme_installer')->install(['stark']);

    $this->pageRegion = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::COMPONENT_UUID,
          'component_id' => 'js.' . static::componentMachineName(),
          'component_version' => $this->originalVersion,
          'inputs' => [
            'required_text' => 'Hello world',
            'optional_text' => 'Optional EN',
          ],
        ],
      ],
    ]);
    self::assertSame(SAVED_NEW, $this->pageRegion->save());
  }

  /**
   * Writes a LanguageConfigOverride for the PageRegion's Spanish translation.
   *
   * Stores only the translatable subset: `required_text` (translatable string).
   * The `optional_text` prop is also a translatable string, but the test setup
   * here simulates a translator who only translated `required_text`.
   */
  private function writeSpanishOverride(array $inputs): void {
    $language_manager = \Drupal::languageManager();
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $override = $language_manager->getLanguageConfigOverride('es', $this->pageRegion->getConfigDependencyName());
    \assert($override instanceof LanguageConfigOverride);
    $override->set('component_tree', [
      self::COMPONENT_UUID => [
        'inputs' => $inputs,
      ],
    ]);
    $override->save();
  }

  /**
   * @legacy-covers \Drupal\canvas\ComponentSource\ComponentSourceManager::updateComponentInstances()
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::reconcileTranslationsWithUpdatedItems()
   */
  #[DataProvider('providerPropagation')]
  public function testPropagation(
    string $setup_method,
    bool $expected_modified,
    ?string $new_key,
    ?string $removed_key,
    array $expected_remaining_override_inputs,
  ): void {
    // Write a Spanish override before the update.
    $this->writeSpanishOverride([
      'required_text' => 'Hola mundo',
      'optional_text' => 'opcional ES',
    ]);

    $this->{$setup_method}();
    $this->generateComponentConfig();

    $tree = $this->pageRegion->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertSame($expected_modified, $was_modified);

    // Reconciliation stages changes in-memory on the entity; read them back.
    $staged = $this->pageRegion->getTranslation('es');

    if (empty($expected_remaining_override_inputs)) {
      // All translatable inputs were deleted: staged override should be empty.
      self::assertTrue($staged->isEmpty(), 'Staged override must be empty when no translatable inputs remain.');
    }
    else {
      self::assertFalse($staged->isEmpty(), 'Staged override must still have data.');
      $stored = $staged->getData('component_tree.' . self::COMPONENT_UUID . '.inputs');
      self::assertIsArray($stored);
      if ($removed_key !== NULL) {
        self::assertArrayNotHasKey($removed_key, $stored, "Deleted prop must be pruned from staged override.");
      }
      if ($new_key !== NULL) {
        // New props are seeded on the base config (default translation), not
        // in the LanguageConfigOverride, so they must NOT appear in the
        // staged override.
        self::assertArrayNotHasKey($new_key, $stored, "New props must not appear in staged config override.");
      }
      self::assertSame($expected_remaining_override_inputs, $stored);
    }
  }

  public static function providerPropagation(): \Generator {
    yield 'New optional prop added — override unchanged (new prop not in override)' => [
      'setup_method' => 'addOptionalProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'removed_key' => NULL,
      'expected_remaining_override_inputs' => [
        'required_text' => 'Hola mundo',
        'optional_text' => 'opcional ES',
      ],
    ];
    yield 'New required prop added — override unchanged (new prop not in override)' => [
      'setup_method' => 'addRequiredProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'removed_key' => NULL,
      'expected_remaining_override_inputs' => [
        'required_text' => 'Hola mundo',
        'optional_text' => 'opcional ES',
      ],
    ];
    yield 'Optional prop deleted — orphaned key pruned from override' => [
      'setup_method' => 'removeOptionalProp',
      'expected_modified' => TRUE,
      'new_key' => NULL,
      'removed_key' => 'optional_text',
      'expected_remaining_override_inputs' => [
        'required_text' => 'Hola mundo',
      ],
    ];
    yield 'Unsafe prop type change — update blocked, override unchanged' => [
      'setup_method' => 'changePropType',
      'expected_modified' => FALSE,
      'new_key' => NULL,
      'removed_key' => NULL,
      'expected_remaining_override_inputs' => [
        'required_text' => 'Hola mundo',
        'optional_text' => 'opcional ES',
      ],
    ];
    yield 'Prop removed and another added — removed key pruned, new key absent from override' => [
      'setup_method' => 'removeAndAddProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'removed_key' => 'optional_text',
      'expected_remaining_override_inputs' => [
        'required_text' => 'Hola mundo',
      ],
    ];
    yield 'All translatable props deleted — override record deleted entirely' => [
      'setup_method' => 'removeBothProps',
      'expected_modified' => TRUE,
      'new_key' => NULL,
      'removed_key' => NULL,
      'expected_remaining_override_inputs' => [],
    ];
  }

  /**
   * Tests that a translation with no prior override is skipped gracefully.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::reconcileTranslationsWithUpdatedItems()
   */
  public function testNoOverrideSkipped(): void {
    // Do NOT write an override — the language exists but has no translation.
    $this->addOptionalProp();
    $this->generateComponentConfig();

    $tree = $this->pageRegion->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertTrue($was_modified);

    // No override existed before — reconciliation must not populate a staged one.
    self::assertTrue($this->pageRegion->getTranslation('es')->isEmpty(), 'No staged override should be created for a language with no prior translation.');
  }

  /**
   * Tests that multiple language overrides are all reconciled on a single update.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::reconcileTranslationsWithUpdatedItems()
   */
  public function testMultipleLanguageOverridesReconciled(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $language_manager = \Drupal::languageManager();
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $this->writeSpanishOverride(['required_text' => 'Hola mundo', 'optional_text' => 'opcional ES']);

    $fr_override = $language_manager->getLanguageConfigOverride('fr', $this->pageRegion->getConfigDependencyName());
    \assert($fr_override instanceof LanguageConfigOverride);
    $fr_override->set('component_tree', [
      self::COMPONENT_UUID => [
        'inputs' => ['required_text' => 'Bonjour monde', 'optional_text' => 'optionnel FR'],
      ],
    ]);
    $fr_override->save();

    // Remove optional_text.
    $this->removeOptionalProp();
    $this->generateComponentConfig();

    $tree = $this->pageRegion->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertTrue($was_modified);

    // Both staged overrides should have optional_text pruned in-memory.
    $es_stored = $this->pageRegion->getTranslation('es')
      ->getData('component_tree.' . self::COMPONENT_UUID . '.inputs');
    self::assertSame(['required_text' => 'Hola mundo'], $es_stored);

    $fr_stored = $this->pageRegion->getTranslation('fr')
      ->getData('component_tree.' . self::COMPONENT_UUID . '.inputs');
    self::assertSame(['required_text' => 'Bonjour monde'], $fr_stored);
  }

  protected function removeBothProps(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    unset($props['required_text'], $props['optional_text']);
    $props['count'] = ['type' => 'integer', 'title' => 'Count', 'examples' => [3]];
    $required = [];
    $this->jsComponent->setProps($props)->set('required', $required)->save();
  }

}
