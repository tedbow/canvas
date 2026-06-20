<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore mundo Opcional Hola

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

  protected static $modules = [
    ...parent::BASE_MODULES,
    'content_translation',
  ];

  private const string COMPONENT_UUID = '11111111-1111-4111-8111-111111111111';

  /**
   * {@inheritdoc}
   */
  protected static function componentMachineName(): string {
    return 'prop_propagation_test';
  }

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
          'component_id' => 'js.prop_propagation_test',
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
   * Gets the Spanish translation's inputs for the test component instance.
   */
  private static function getEsInputs(Page $page): ?array {
    $es = $page->getTranslation('es');
    $item = $es->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    if ($item === NULL) {
      return NULL;
    }
    return $item->getInputs();
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
    ?string $removed_key,
  ): void {
    $page = $this->createPageWithTranslation();

    $this->{$setup_method}();
    $this->generateComponentConfig();

    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertSame($expected_modified, $was_modified);

    $es_inputs = self::getEsInputs($page);
    self::assertNotNull($es_inputs);

    if ($removed_key !== NULL) {
      self::assertArrayNotHasKey($removed_key, $es_inputs, "Deleted prop must be removed from translation.");
    }
    if ($new_key !== NULL) {
      self::assertArrayHasKey($new_key, $es_inputs, "New prop must appear in translation.");
    }
    // Existing translatable props must be preserved.
    if ($expected_modified) {
      self::assertSame('Hola mundo', $es_inputs['required_text'] ?? NULL, "Existing translatable prop must be preserved.");
    }
  }

  public static function providerPropagation(): \Generator {
    yield 'New optional prop added — translation gets default value' => [
      'setup_method' => 'addOptionalProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'removed_key' => NULL,
    ];
    yield 'New required prop added — translation gets example value' => [
      'setup_method' => 'addRequiredProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'removed_key' => NULL,
    ];
    yield 'Prop deleted — orphaned input removed from translation' => [
      'setup_method' => 'removeOptionalProp',
      'expected_modified' => TRUE,
      'new_key' => NULL,
      'removed_key' => 'optional_text',
    ];
    yield 'Unsafe prop type change — update blocked, translation unchanged' => [
      'setup_method' => 'changePropType',
      'expected_modified' => FALSE,
      'new_key' => NULL,
      'removed_key' => NULL,
    ];
    yield 'Prop removed and another added — both changes propagated' => [
      'setup_method' => 'removeAndAddProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'removed_key' => 'optional_text',
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
    $es_inputs = self::getEsInputs($page);
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

}
