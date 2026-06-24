<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore Hola mundo opcional ranura prueba

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests component instance update propagation to ContentTemplate translations.
 *
 * ContentTemplate is a ComponentTreeConfigEntityBase with an additional
 * translatable field outside the component tree: `exposed_slots` labels. This
 * class verifies that reconciliation correctly handles that non-tree data.
 */
#[CoversClass(ComponentSourceManager::class)]
#[CoversClass(StagedLanguageConfigOverrideStorage::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
#[Group('slow')]
final class ContentTemplateSymmetricalTranslationPropagationTest extends ConfigEntitySymmetricalTranslationPropagationTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installConfig(['node', 'user']);

    NodeType::create(['type' => 'helpful', 'name' => 'Helpful'])->save();

    $this->entity = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => self::populateActiveComponentVersionPlaceholders($this->translatableComponentTree),
      'exposed_slots' => [
        'test_slot' => [
          'label' => 'Test slot',
          'component_uuid' => static::TRANSLATED_COMPONENT_INSTANCE_UUID,
          'slot_name' => 'test_slot',
        ],
      ],
    ]);
    self::assertEntityIsValid($this->entity);
    self::assertSame(SAVED_NEW, $this->entity->save());
  }

  /**
   * Tests that an exposed_slot label keeps the override alive on publish.
   *
   * ContentTemplate stores exposed_slots data in the LanguageConfigOverride
   * alongside component_tree. When every translatable component_tree input is
   * deleted, the component_tree side of the override empties — but the
   * translated exposed_slot label must survive reconciliation, and publishing
   * must NOT delete the live record. Contrast with
   * ::testPublishDeletesEmptyOverride(), where nothing else remains and the
   * record is deleted.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage
   */
  public function testExposedSlotLabelOverridePreserved(): void {
    \assert($this->entity instanceof ComponentTreeConfigEntityBase);
    // Write a Spanish override that includes both component_tree and
    // exposed_slots translations.
    $language_manager = \Drupal::languageManager();
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $override = $language_manager->getLanguageConfigOverride('es', $this->entity->getConfigDependencyName());
    \assert($override instanceof LanguageConfigOverride);
    $override->set('component_tree', [
      static::TRANSLATED_COMPONENT_INSTANCE_UUID => [
        'inputs' => ['required_text' => 'Hola mundo', 'optional_text' => 'opcional ES'],
      ],
    ]);
    // Translations only override the translatable `label`; the structural keys
    // `component_uuid` and `slot_name` remain in the base config.
    $override->set('exposed_slots.test_slot.label', 'ranura de prueba');
    $override->save();
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraintValidator
    self::assertEntityIsValid($this->entity);

    // Delete BOTH translatable props — this empties the component_tree side of
    // the override on reconciliation.
    $this->removeBothProps();
    $this->generateComponentConfig();

    $tree = $this->entity->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $manager->updateComponentInstances($tree);

    // Staged reconciliation: the emptied component_tree is pruned, but the slot
    // label survives, so the staged override is not empty.
    $staged = $this->entity->getTranslation('es');
    self::assertNull($staged->getData('component_tree'), 'Emptied component_tree must be pruned from the staged override.');
    self::assertSame('ranura de prueba', $staged->getData('exposed_slots.test_slot.label'), 'Exposed slot label override must survive reconciliation.');
    self::assertFalse($staged->isEmpty(), 'Staged override must not be empty while the slot label remains.');

    // After publishing, the live override must NOT be deleted: only the
    // translated slot label remains.
    $this->updateAndPublishOverrides();
    $live = $language_manager->getLanguageConfigOverride('es', $this->entity->getConfigDependencyName());
    \assert($live instanceof LanguageConfigOverride);
    self::assertFalse($live->isNew(), 'Override must survive because a non-tree translation (slot label) remains.');
    self::assertSame('ranura de prueba', $live->get('exposed_slots.test_slot.label'), 'Translated slot label must survive publish.');
    self::assertArrayNotHasKey('component_tree', $live->getRawData(), 'Emptied component_tree key must be removed entirely from the published override.');
  }

}
