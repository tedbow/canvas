<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore Hola mundo opcional optionnel

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared tests for config entity translation propagation kernel tests.
 *
 * Validates that after the default (English) component tree is updated via
 * ComponentSourceManager::updateComponentInstances(), each language's
 * LanguageConfigOverride is reconciled: deleted-prop keys are pruned,
 * and orphan-free overrides are deleted in their entirety.
 *
 * Config entity translations store only the translatable subset of inputs in
 * LanguageConfigOverride records (sparse, not a full copy). Propagation
 * therefore operates at the override level, not at the entity level.
 *
 * @phpstan-import-type ComponentTreeItemListArray from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type OptimizedSingleComponentInputArray from \Drupal\canvas\Plugin\DataType\ComponentInputs
 */
abstract class ConfigEntitySymmetricalTranslationPropagationTestBase extends TranslationPropagationTestBase {

  use DataProviderWithComponentTreeTrait;

  /**
   * The component instance UUID used in the config entity's component tree.
   */
  protected const string TRANSLATED_COMPONENT_INSTANCE_UUID = '22222222-2222-4222-8222-222222222222';

  /**
   * The config entity under test (PageRegion or ContentTemplate).
   */
  protected ComponentTreeConfigEntityBase $translatedConfigEntity;

  /**
   * @var ComponentTreeItemListArray
   * @todo Move to TranslationPropagationTestBase
   */
  protected array $translatableComponentTree = [
    [
      'uuid' => self::TRANSLATED_COMPONENT_INSTANCE_UUID,
      'component_id' => 'js.translatable_js_component',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => [
        'required_text' => 'Hello world',
        'optional_text' => 'Optional EN',
      ],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // TRICKY: no extra modules are needed: Drupal core provides the no-UI
    // translation infrastructure in the `language` module.
    // @see \Drupal\language\Config\LanguageConfigFactoryOverride
  ];

  #[DataProvider('providerPropagation')]
  public function testPropagation(
    string $setup_method,
    bool $expected_modified,
    ?string $new_key,
    ?string $removed_key,
    array $expected_remaining_override_inputs,
  ): void {
    // Write a Spanish override before the update.
    $this->createComponentTreeTranslation('es', [
      'required_text' => 'Hola mundo',
      'optional_text' => 'opcional ES',
    ]);
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
    self::assertEntityIsValid($this->translatedConfigEntity);

    $this->{$setup_method}();
    $this->generateComponentConfig();

    $tree = $this->translatedConfigEntity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertSame($expected_modified, $was_modified);

    // Reconciliation stages changes in-memory on the entity; read them back.
    $staged = $this->translatedConfigEntity->getTranslation('es');

    if (empty($expected_remaining_override_inputs)) {
      // All translatable inputs were deleted: staged override should be empty.
      self::assertTrue($staged->isEmpty(), 'Staged override must be empty when no translatable inputs remain.');
    }
    else {
      self::assertFalse($staged->isEmpty(), 'Staged override must still have data.');
      $stored = $staged->getData('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs');
      self::assertIsArray($stored);
      if ($removed_key !== NULL) {
        self::assertArrayNotHasKey($removed_key, $stored, 'Deleted prop must be pruned from staged override.');
      }
      if ($new_key !== NULL) {
        // New props are never injected into a LanguageConfigOverride — the
        // sparse override stores only translatable overrides, and new props
        // have no translated value yet.
        self::assertArrayNotHasKey($new_key, $stored, 'New props must not appear in staged config override.');
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
    // This has a sibling test that tests the full lifecycle.
    // @see ::testPublishWritesToLiveOverride()
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
    // This has a sibling test that tests the full lifecycle.
    // @see ::testPublishDeletesEmptyOverride()
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
   */
  public function testNoOverrideSkipped(): void {
    // Do NOT write an override — the language exists but has no translation.
    $this->addOptionalProp();
    $this->generateComponentConfig();

    $tree = $this->translatedConfigEntity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertTrue($was_modified);

    // No override existed before — reconciliation must not populate a staged one.
    self::assertTrue($this->translatedConfigEntity->getTranslation('es')->isEmpty(), 'No staged override should be created for a language with no prior translation.');
  }

  /**
   * Tests that all LanguageConfigOverrides for the entity are updated together.
   */
  public function testMultipleLanguageOverridesReconciled(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $this->createComponentTreeTranslation('es', [
      'required_text' => 'Hola mundo',
      'optional_text' => 'opcional ES',
    ]);
    $this->createComponentTreeTranslation('fr', [
      'required_text' => 'Bonjour monde',
      'optional_text' => 'optionnel FR',
    ]);
    self::assertHasStoredTranslation('es');
    self::assertHasStoredTranslation('fr');
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
    self::assertEntityIsValid($this->translatedConfigEntity);

    $this->removeOptionalProp();
    $this->generateComponentConfig();

    $tree = $this->translatedConfigEntity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertTrue($was_modified);

    // Both staged overrides should have optional_text pruned in-memory.
    $es_stored = $this->translatedConfigEntity->getTranslation('es')
      ->getData('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs');
    self::assertSame(['required_text' => 'Hola mundo'], $es_stored);

    $fr_stored = $this->translatedConfigEntity->getTranslation('fr')
      ->getData('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs');
    self::assertSame(['required_text' => 'Bonjour monde'], $fr_stored);
  }

  /**
   * Runs updateComponentInstances() and publishes via the real auto-save path.
   *
   * Exercises the full lifecycle:
   * 1. A `LanguageConfigOverride` exists for `es`
   * 2. Generate a `StagedLanguageConfigOverride for this `es` translation
   * 3. It must now not yet be saved, only exist in memory
   * 4. Let it be updated via:
   *    ComponentSourceManager::updateComponentInstances()
   * 5. Call StagedLanguageConfigOverride::save() to move it from PHP memory to
   *    the entity type's storage (i.e. AutoSaveManager)
   * 6. Publish it via the real auto-save controller, which will first ensure it
   *    is valid:
   *    and will then trigger
   *    StagedLanguageConfigOverride::autoSavePublish()
   *    → StagedConfigEntityStorageTrait::save()
   *    → StagedLanguageConfigOverrideStorage::publish()
   *    → LanguageConfigOverride::set()
   *    → LanguageConfigOverride::save()
   *
   * @return \Drupal\canvas\Entity\StagedLanguageConfigOverride
   *   The in-memory staged config translation, so callers can inspect the
   *   in-memory, pre-publish state if needed.
   */
  protected function updateAndPublishOverrides(string $assert_langcode = 'es'): StagedLanguageConfigOverride {
    // Router must be built before UserCreationTrait::setUpCurrentUser()
    // triggers FilterPermissions::permissions()  URL generation.
    $this->container->get('router.builder')->rebuild();

    $admin_permission = $this->translatedConfigEntity->getEntityType()->getAdminPermission();
    \assert(\is_string($admin_permission));
    $this->setUpCurrentUser([], [
      $admin_permission,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    $tree = $this->translatedConfigEntity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $manager->updateComponentInstances($tree);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    // Stage the translation override BEFORE setComponentTree() — the latter
    // clears stagedOverrides, so a subsequent getTranslation() would re-read
    // un-pruned data from live config, discarding the reconciliation.
    $staged = $this->translatedConfigEntity->getTranslation($assert_langcode);
    self::assertTrue($staged->isNew());
    self::assertFalse($staged->status());
    $staged->save();

    // Assert that both the retrieved-and-now-saved StagedLanguageConfigOverride
    // and its origin (the config entity's ::getTranslation() method) convey
    // that the StagedLanguageConfigOverride has been saved.
    self::assertFalse($staged->isNew());
    self::assertFalse($staged->status());
    self::assertFalse($this->translatedConfigEntity->getTranslation($assert_langcode)->isNew());

    // Stage the updated base entity.
    $this->translatedConfigEntity->setComponentTree($tree->getValue());
    self::assertFalse($this->translatedConfigEntity->getTranslation($assert_langcode)->isNew());
    // Validate the StagedLanguageConfigOverride; it is minimally validated.
    // @see canvas.schema.yml, `canvas.staged_language_config_override.*:data`.
    self::assertEntityIsValid($staged);
    self::assertEntityIsValid($this->translatedConfigEntity);
    $auto_save_manager->saveEntity($this->translatedConfigEntity);

    // Both the translated config entity and the Spanish
    // StagedLanguageConfigOverride must now be in auto-save storage.
    // @todo 🚧 The StagedLanguageConfigOverrides should NOT appear in this list, to match the behavior for content entities introduced in https://git.drupalcode.org/project/canvas/-/work_items/3591704
    $all_auto_saves = $auto_save_manager->getAllAutoSaveList(FALSE, FALSE);
    self::assertSame([
      AutoSaveManager::getAutoSaveKey($this->translatedConfigEntity),
      AutoSaveManager::getAutoSaveKey($staged),
    ], \array_keys($all_auto_saves));

    // Publish everything through the real auto-save publish controller.
    $payload = [];
    foreach ($all_auto_saves as $key => $info) {
      $payload[$key] = ['data_hash' => $info['data_hash']];
    }
    $request = Request::create('/canvas/api/v0/auto-saves/publish', 'POST', content: (string) \json_encode($payload));
    $controller = \Drupal::classResolver(ApiAutoSaveController::class);
    \assert($controller instanceof ApiAutoSaveController);
    $response = $controller->post($request);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    // No auto-saves left.
    self::assertSame([], $auto_save_manager->getAllAutoSaveList(FALSE, FALSE));

    return $staged;
  }

  /**
   * Tests that publishing a staged override writes it to the live LanguageConfigOverride.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage
   */
  public function testPublishWritesToLiveOverride(): void {
    $this->createComponentTreeTranslation('es', [
      'required_text' => 'Hola mundo',
      'optional_text' => 'opcional ES',
    ]);
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
    self::assertEntityIsValid($this->translatedConfigEntity);

    $this->removeOptionalProp();
    $this->generateComponentConfig();

    $this->updateAndPublishOverrides();

    // After publish, the live LanguageConfigOverride must have optional_text
    // removed and required_text preserved.
    $language_manager = \Drupal::languageManager();
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $live = $language_manager->getLanguageConfigOverride('es', $this->translatedConfigEntity->getConfigDependencyName());
    \assert($live instanceof LanguageConfigOverride);
    self::assertFalse($live->isNew(), 'Live override must still exist after partial reconciliation.');
    $inputs = $live->get('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs');
    self::assertIsArray($inputs);
    self::assertArrayNotHasKey('optional_text', $inputs, 'Deleted prop must be removed from live override on publish.');
    self::assertSame('Hola mundo', $inputs['required_text']);
  }

  /**
   * Tests that publishing a staged override deletes the live record when empty.
   *
   * When reconciliation removes all translated inputs (all props deleted from
   * the base component), the resulting staged override is empty. Publishing it
   * must delete the live LanguageConfigOverride rather than writing empty data.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage
   */
  public function testPublishDeletesEmptyOverride(): void {
    // Write an override that only has the two props that will both be deleted.
    $this->createComponentTreeTranslation('es', [
      'required_text' => 'Hola mundo',
      'optional_text' => 'opcional ES',
    ]);
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
    self::assertEntityIsValid($this->translatedConfigEntity);

    $this->removeBothProps();
    $this->generateComponentConfig();

    $staged = $this->updateAndPublishOverrides();
    self::assertTrue($staged->isEmpty(), 'Staged override must be empty after both props deleted.');

    // Live LanguageConfigOverride must be deleted when the staged override is empty.
    $language_manager = \Drupal::languageManager();
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $live = $language_manager->getLanguageConfigOverride('es', $this->translatedConfigEntity->getConfigDependencyName());
    \assert($live instanceof LanguageConfigOverride);
    self::assertTrue($live->isNew(), 'Live override must be deleted when staged override is empty.');
    self::assertSame([], $live->getRawData());
  }

  /**
   * Tests that non-translatable (enum) props are not leaked into the staged override.
   *
   * Config entity translations store only the translatable subset of inputs.
   * Enum-typed props are not translatable, so a new enum prop added to the base
   * component must not appear in the staged override after reconciliation.
   */
  public function testNonTranslatablePropNotStaged(): void {
    $this->createComponentTreeTranslation('es', [
      'required_text' => 'Hola mundo',
      'optional_text' => 'opcional ES',
    ]);
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
    self::assertEntityIsValid($this->translatedConfigEntity);

    // Add a new enum (non-translatable) optional prop.
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    $props['alignment'] = [
      'type' => 'string',
      'title' => 'Alignment',
      'enum' => ['left', 'right'],
      'examples' => ['left'],
    ];
    $this->jsComponent->setProps($props)->save();
    $this->generateComponentConfig();

    $tree = $this->translatedConfigEntity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $manager->updateComponentInstances($tree);

    $staged = $this->translatedConfigEntity->getTranslation('es');
    $inputs = $staged->getData('component_tree.' . static::TRANSLATED_COMPONENT_INSTANCE_UUID . '.inputs');
    self::assertIsArray($inputs);
    self::assertArrayNotHasKey('alignment', $inputs, 'Non-translatable enum prop must not appear in staged override.');
    // Existing translatable values are preserved.
    self::assertSame('Hola mundo', $inputs['required_text']);
    self::assertSame('opcional ES', $inputs['optional_text']);
  }

  private function getStoredTranslation(string $langcode): LanguageConfigOverride {
    $language_manager = \Drupal::languageManager();
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    return $language_manager->getLanguageConfigOverride($langcode, $this->translatedConfigEntity->getConfigDependencyName());
  }

  protected function assertHasStoredTranslation(string $langcode): void {
    $translation = $this->getStoredTranslation($langcode);
    self::assertFalse($translation->isNew());
    self::assertNotSame([], $translation->getRawData());
  }

  protected function assertHasNoStoredTranslation(string $langcode): void {
    $translation = $this->getStoredTranslation($langcode);
    self::assertTrue($translation->isNew());
    self::assertSame([], $translation->getRawData());
  }

  /**
   * Writes a LanguageConfigOverride with a translated component tree.
   *
   * @param string $langcode
   * @param OptimizedSingleComponentInputArray $inputs
   *   The symmetrical translation to store: translated component instance
   *   inputs.
   *
   * @return void
   */
  protected function createComponentTreeTranslation(string $langcode, array $inputs): void {
    self::assertHasNoStoredTranslation($langcode);
    $this->getStoredTranslation($langcode)->set('component_tree', [
      static::TRANSLATED_COMPONENT_INSTANCE_UUID => [
        'inputs' => $inputs,
      ],
    ])->save();
    self::assertHasStoredTranslation($langcode);
  }

}
