<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore mundo Opcional Hola Página prueba Optionnel Etiqueta Española Hijo

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests component instance version update propagation to content translations.
 *
 * Validates that after the default translation's component tree is updated via
 * ComponentSourceManager::updateComponentInstances(), all non-default
 * translation component trees are reconciled in-memory via
 * ComponentTreeItem::reconcileWithUpdatedDefaultTranslation().
 */
#[CoversClass(ComponentSourceManager::class)]
#[CoversClass(ComponentTreeItem::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
final class ContentEntityTranslationPropagationTest extends TranslationPropagationTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
  ];

  private const string COMPONENT_UUID = '11111111-1111-4111-8111-111111111111';
  private const string SECOND_UUID = '22222222-2222-4222-8222-222222222222';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
  }

  /**
   * Creates a Page with an English default and a Spanish translation.
   */
  private function createPageWithTranslation(
    array $en_inputs = ['required_text' => 'Hello world', 'optional_text' => 'Optional EN'],
    array $es_inputs = ['required_text' => 'Hola mundo', 'optional_text' => 'Opcional ES'],
  ): Page {
    $page = Page::create([
      'title' => 'Test Page',
      'langcode' => 'en',
      'components' => [
        [
          'uuid' => self::COMPONENT_UUID,
          'component_id' => 'js.translatable_js_component',
          'component_version' => $this->originalVersion,
          'parent_uuid' => NULL,
          'inputs' => $en_inputs,
        ],
      ],
    ]);
    self::assertSame(SAVED_NEW, $page->save());

    $translation = $page->addTranslation('es');
    $translation->set('title', 'Página de prueba');
    $translation->set('components', $page->get('components')->getValue());
    $es_tree = $translation->getComponentTree();
    $es_item = $es_tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($es_item);
    $es_item->setInput($es_inputs);
    $translation->save();

    $loaded = Page::load($page->id());
    self::assertNotNull($loaded);
    return $loaded;
  }

  /**
   * Gets a translation's inputs for a given component instance.
   */
  private static function getInputs(Page $page, string $langcode, string $uuid): ?array {
    $translation = $page->getTranslation($langcode);
    $item = $translation->getComponentTree()->getComponentTreeItemByUuid($uuid);
    return $item?->getInputs();
  }

  /**
   * @legacy-covers \Drupal\canvas\ComponentSource\ComponentSourceManager::updateComponentInstances()
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::reconcileTranslationsWithUpdatedItems()
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::reconcileWithUpdatedDefaultTranslation()
   */
  #[DataProvider('providerPropagation')]
  public function testPropagation(
    string $setup_method,
    bool $expected_modified,
    ?string $new_key,
    ?string $expected_optional,
  ): void {
    $page = $this->createPageWithTranslation();

    $this->{$setup_method}();
    $this->generateComponentConfig();

    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertSame($expected_modified, $was_modified);

    $es_inputs = self::getInputs($page, 'es', self::COMPONENT_UUID);
    self::assertNotNull($es_inputs);

    if ($new_key !== NULL) {
      self::assertArrayHasKey($new_key, $es_inputs, "New prop must appear in translation.");
    }
    if ($expected_modified) {
      // The required value is always preserved; the optional value is its
      // translated value, or NULL once the prop has been removed.
      self::assertSame('Hola mundo', $es_inputs['required_text'] ?? NULL, "Existing translatable prop must be preserved.");
      self::assertSame($expected_optional, $es_inputs['optional_text'] ?? NULL, "Translated optional prop must match the expected post-update value.");
    }
  }

  public static function providerPropagation(): \Generator {
    yield 'New optional prop added — translation gets default value' => [
      'setup_method' => 'addOptionalProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'expected_optional' => 'Opcional ES',
    ];
    yield 'New required prop added — translation gets example value' => [
      'setup_method' => 'addRequiredProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'expected_optional' => 'Opcional ES',
    ];
    yield 'Prop deleted — orphaned input removed from translation' => [
      'setup_method' => 'removeOptionalProp',
      'expected_modified' => TRUE,
      'new_key' => NULL,
      'expected_optional' => NULL,
    ];
    yield 'Unsafe prop type change — update blocked, translation unchanged' => [
      'setup_method' => 'changePropType',
      'expected_modified' => FALSE,
      'new_key' => NULL,
      'expected_optional' => 'Opcional ES',
    ];
    yield 'Prop removed and another added — both changes propagated' => [
      'setup_method' => 'removeAndAddProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'expected_optional' => NULL,
    ];
  }

  /**
   * Tests that multiple translations are all reconciled on a single update.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::reconcileTranslationsWithUpdatedItems()
   */
  public function testMultipleTranslationsUpdated(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $page = $this->createPageWithTranslation();
    $page = Page::load($page->id());
    \assert($page instanceof Page);

    $fr = $page->addTranslation('fr');
    $fr->set('title', 'Page de test');
    $fr->set('components', $page->get('components')->getValue());
    $fr_tree = $fr->getComponentTree();
    $fr_item = $fr_tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    \assert($fr_item !== NULL);
    $fr_item->setInput(['required_text' => 'Bonjour monde', 'optional_text' => 'Optionnel FR']);
    $fr->save();

    $page = Page::load($page->id());
    \assert($page instanceof Page);

    // Remove optional_text and add voice simultaneously.
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    unset($props['optional_text']);
    $props['voice'] = ['type' => 'string', 'title' => 'Voice', 'examples' => ['polite']];
    $this->jsComponent->setProps($props)->save();
    $this->generateComponentConfig();

    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertTrue($was_modified);

    // Spanish translation.
    $es_inputs = self::getInputs($page, 'es', self::COMPONENT_UUID);
    self::assertNotNull($es_inputs);
    self::assertArrayNotHasKey('optional_text', $es_inputs);
    self::assertArrayHasKey('voice', $es_inputs);
    self::assertSame('polite', $es_inputs['voice']);
    self::assertSame('Hola mundo', $es_inputs['required_text']);

    // French translation.
    $fr_inputs = $page->getTranslation('fr')->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID)?->getInputs();
    self::assertNotNull($fr_inputs);
    self::assertArrayNotHasKey('optional_text', $fr_inputs);
    self::assertArrayHasKey('voice', $fr_inputs);
    self::assertSame('polite', $fr_inputs['voice']);
    self::assertSame('Bonjour monde', $fr_inputs['required_text']);
  }

  /**
   * Tests that triggering from a non-default translation reconciles all of them.
   *
   * The editor's GET preview endpoint calls updateComponentInstances() for
   * whichever language is being previewed. Under symmetric translation — the
   * mode this fixture sets up via canvas_dev_translation, where component_version
   * is shared — triggering from a non-default translation must still bring the
   * default to the new version, keeping every translation on the same version.
   *
   * @legacy-covers \Drupal\canvas\ComponentSource\ComponentSourceManager::updateComponentInstances()
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::reconcileTranslationsWithUpdatedItems()
   */
  public function testNonDefaultLanguageTriggersPropagation(): void {
    $page = $this->createPageWithTranslation();

    // A new required prop forces a value into every translation, so the
    // default's convergence is observable in its inputs (the updater injects
    // required-prop defaults into the source/default tree; optional ones are
    // only added to non-default translations during reconciliation).
    $this->addRequiredProp();
    $this->generateComponentConfig();

    // Trigger the update from the non-default (Spanish) translation's tree.
    $page = Page::load($page->id());
    \assert($page instanceof Page);
    $es_page = $page->getTranslation('es');
    $es_tree = $es_page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    self::assertTrue($manager->updateComponentInstances($es_tree));

    // The default (English) translation is reconciled too — not left behind on
    // the old version without the new required prop.
    $en_item = $es_page->getUntranslated()->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($en_item);
    $en_inputs = $en_item->getInputs();
    self::assertNotNull($en_inputs);
    self::assertArrayHasKey('voice', $en_inputs);
    self::assertSame('polite', $en_inputs['voice']);
    self::assertSame('Hello world', $en_inputs['required_text']);
    self::assertSame('Optional EN', $en_inputs['optional_text']);

    // The Spanish translation keeps its translated values, and both translations
    // end on the same new component version.
    $es_item = $es_page->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($es_item);
    $es_inputs = $es_item->getInputs();
    self::assertNotNull($es_inputs);
    self::assertArrayHasKey('voice', $es_inputs);
    self::assertSame('Hola mundo', $es_inputs['required_text']);
    self::assertNotSame($this->originalVersion, $es_item->getComponentVersion());
    self::assertSame($en_item->getComponentVersion(), $es_item->getComponentVersion());

    // Re-running once every translation is current is a no-op: nothing left to
    // update, so no modification is reported.
    self::assertFalse($manager->updateComponentInstances($es_tree));
  }

  /**
   * Tests that reconcileWithUpdatedDefaultTranslation() throws on default translation.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::reconcileWithUpdatedDefaultTranslation()
   */
  public function testReconcileThrowsOnDefaultTranslation(): void {
    $page = $this->createPageWithTranslation();
    $en_tree = $page->getComponentTree();
    $en_item = $en_tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($en_item);

    $this->expectException(\InvalidArgumentException::class);
    $en_item->reconcileWithUpdatedDefaultTranslation([], [], $this->originalVersion);
  }

  /**
   * Tests that a component instance's per-translation label is preserved.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::reconcileWithUpdatedDefaultTranslation()
   */
  public function testLabelPreservedDuringReconciliation(): void {
    $page = $this->createPageWithTranslation();

    $this->removeOptionalProp();
    $this->generateComponentConfig();

    // Give the component instance a distinct per-translation label. Set after
    // the version bump so the tree resolves the updated component (touching the
    // tree before would cache the component at its previous version).
    $tree = $page->getComponentTree();
    $en_item = $tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($en_item);
    $en_item->setLabel('English Label');
    $es_item = $page->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($es_item);
    $es_item->setLabel('Etiqueta Española');

    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    self::assertTrue($manager->updateComponentInstances($tree));

    $es_item = $page->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($es_item);
    self::assertSame('Etiqueta Española', $es_item->getLabel());

    $en_item = $tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($en_item);
    self::assertSame('English Label', $en_item->getLabel());
  }

  /**
   * Tests that empty translation inputs only gain the new prop's default.
   *
   * Simulates content_translation's FieldTranslationSynchronizer creating a new
   * delta with empty translatable columns: reconciliation must still inject the
   * new prop's default without error.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::reconcileWithUpdatedDefaultTranslation()
   */
  public function testEmptyTranslationInputsHandled(): void {
    $page = $this->createPageWithTranslation();

    $this->addOptionalProp();
    $this->generateComponentConfig();

    // Empty the Spanish inputs in-memory before reconciling.
    $es_translation = $page->getTranslation('es');
    $es_item = $es_translation->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($es_item);
    $es_item->setInput([]);

    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    self::assertTrue($manager->updateComponentInstances($tree));

    $es_inputs = self::getInputs($page, 'es', self::COMPONENT_UUID);
    self::assertNotNull($es_inputs);
    self::assertArrayHasKey('voice', $es_inputs);
    self::assertSame('polite', $es_inputs['voice']);
  }

  /**
   * Tests that updating instances without any translations works and bumps version.
   *
   * @legacy-covers \Drupal\canvas\ComponentSource\ComponentSourceManager::updateComponentInstances()
   */
  public function testNoTranslationsNoError(): void {
    $page = Page::create([
      'title' => 'No translations page',
      'langcode' => 'en',
      'components' => [
        [
          'uuid' => self::COMPONENT_UUID,
          'component_id' => 'js.translatable_js_component',
          'component_version' => $this->originalVersion,
          'parent_uuid' => NULL,
          'inputs' => ['required_text' => 'Hello'],
        ],
      ],
    ]);
    self::assertSame(SAVED_NEW, $page->save());
    $page_id = $page->id();
    \assert($page_id !== NULL);

    $this->addOptionalProp();
    $this->generateComponentConfig();

    \Drupal::entityTypeManager()->getStorage('component')->resetCache();
    $page = Page::load($page_id);
    \assert($page instanceof Page);
    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    self::assertTrue($manager->updateComponentInstances($tree));

    $en_item = $tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($en_item);
    self::assertNotSame($this->originalVersion, $en_item->getComponentVersion());
  }

  /**
   * Tests that multiple component instances in one tree are all reconciled.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::reconcileTranslationsWithUpdatedItems()
   */
  public function testMultipleComponentInstancesReconciled(): void {
    $page = Page::create([
      'title' => 'Multi-instance page',
      'langcode' => 'en',
      'components' => [
        [
          'uuid' => self::COMPONENT_UUID,
          'component_id' => 'js.translatable_js_component',
          'component_version' => $this->originalVersion,
          'parent_uuid' => NULL,
          'inputs' => ['required_text' => 'First EN', 'optional_text' => 'First opt EN'],
        ],
        [
          'uuid' => self::SECOND_UUID,
          'component_id' => 'js.translatable_js_component',
          'component_version' => $this->originalVersion,
          'parent_uuid' => NULL,
          'inputs' => ['required_text' => 'Second EN', 'optional_text' => 'Second opt EN'],
        ],
      ],
    ]);
    self::assertSame(SAVED_NEW, $page->save());

    $translation = $page->addTranslation('es');
    $translation->set('title', 'Página multi');
    $translation->set('components', $page->get('components')->getValue());
    $es_tree = $translation->getComponentTree();
    $first = $es_tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    \assert($first !== NULL);
    $first->setInput(['required_text' => 'Primero ES', 'optional_text' => 'Primero opt ES']);
    $second = $es_tree->getComponentTreeItemByUuid(self::SECOND_UUID);
    \assert($second !== NULL);
    $second->setInput(['required_text' => 'Segundo ES', 'optional_text' => 'Segundo opt ES']);
    $translation->save();

    $page = Page::load($page->id());
    \assert($page instanceof Page);

    // Remove a prop to trigger an update on both instances.
    $this->removeOptionalProp();
    $this->generateComponentConfig();

    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    self::assertTrue($manager->updateComponentInstances($tree));

    $first_es = self::getInputs($page, 'es', self::COMPONENT_UUID);
    self::assertNotNull($first_es);
    self::assertArrayNotHasKey('optional_text', $first_es);
    self::assertSame('Primero ES', $first_es['required_text']);

    $second_es = self::getInputs($page, 'es', self::SECOND_UUID);
    self::assertNotNull($second_es);
    self::assertArrayNotHasKey('optional_text', $second_es);
    self::assertSame('Segundo ES', $second_es['required_text']);
  }

  /**
   * Tests that an unsafe change blocks the update for every translation.
   *
   * Neither the default (EN) nor the non-default (ES) tree may change, and the
   * shared component_version must stay put.
   *
   * @legacy-covers \Drupal\canvas\ComponentSource\ComponentSourceManager::updateComponentInstances()
   */
  public function testUnsafeChangeBlocksBothLanguages(): void {
    $page = $this->createPageWithTranslation();

    $this->changePropType();
    $this->generateComponentConfig();

    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    self::assertFalse($manager->updateComponentInstances($tree));

    // EN inputs and version must be untouched.
    $en_item = $tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($en_item);
    $en_inputs = $en_item->getInputs();
    self::assertNotNull($en_inputs);
    self::assertSame('Hello world', $en_inputs['required_text']);
    self::assertSame('Optional EN', $en_inputs['optional_text']);
    self::assertSame($this->originalVersion, $en_item->getComponentVersion());

    // ES inputs must be untouched.
    $es_inputs = self::getInputs($page, 'es', self::COMPONENT_UUID);
    self::assertNotNull($es_inputs);
    self::assertSame('Hola mundo', $es_inputs['required_text']);
    self::assertSame('Opcional ES', $es_inputs['optional_text']);
  }

}
