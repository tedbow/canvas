<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore mundo Opcional Hola Página prueba Optionnel Etiqueta Española Hijo EDITADO

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests component instance version update propagation to content translations.
 *
 * Validates that after the default translation's component tree is updated via
 * ComponentSourceManager::updateComponentInstances(), all non-default
 * translation component trees receive the same updater pass independently.
 */
#[CoversClass(ComponentSourceManager::class)]
#[CoversClass(ComponentTreeItem::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
#[Group('slow')]
final class ContentEntityTranslationPropagationTest extends TranslationPropagationTestBase {

  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
  ];

  /**
   * @var \Drupal\Core\Entity\ContentEntityInterface
   * @phpstan-ignore-next-line property.phpDocType
   */
  protected $entity;

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
    array $en_inputs = ['required_text' => 'Hello world', 'optional_text' => 'Optional EN', 'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']],
    array $es_inputs = self::ES_TRANSLATION_INPUTS,
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
   * {@inheritdoc}
   */
  protected function setUpTranslation(): Page {
    $this->entity = $this->createPageWithTranslation();
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function assertTranslationAfterUpdate(array $expected_content, array|false $expected_config): void {
    \assert($this->entity instanceof Page);
    $es_inputs = self::getInputs($this->entity, 'es', self::COMPONENT_UUID);
    self::assertSame($expected_content, $es_inputs);
  }

  /**
   * Tests that multiple translations are all reconciled on a single update.
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

    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertTrue($was_modified);

    // Spanish translation.
    $es_inputs = self::getInputs($page, 'es', self::COMPONENT_UUID);
    self::assertNotNull($es_inputs);
    self::assertArrayNotHasKey('optional_text', $es_inputs);
    self::assertArrayNotHasKey('voice', $es_inputs);
    self::assertSame('Hola mundo', $es_inputs['required_text']);

    // French translation.
    $fr_inputs = $page->getTranslation('fr')->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID)?->getInputs();
    self::assertNotNull($fr_inputs);
    self::assertArrayNotHasKey('optional_text', $fr_inputs);
    self::assertArrayNotHasKey('voice', $fr_inputs);
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
   */
  public function testNonDefaultLanguageTriggersPropagation(): void {
    $page = $this->createPageWithTranslation();

    // A new required prop forces a value into every translation, so the
    // default's convergence is observable in its inputs.
    $this->addRequiredProp();

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
   * Tests that a component instance's per-translation label is preserved.
   */
  public function testLabelPreservedDuringReconciliation(): void {
    $page = $this->createPageWithTranslation();

    $this->removeOptionalProp();

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
   * Tests that empty translation inputs are handled without error.
   *
   * Simulates content_translation's FieldTranslationSynchronizer creating a new
   * delta with empty translatable columns: the updater must run without error
   * and bump the component version even when inputs are empty.
   */
  public function testEmptyTranslationInputsHandled(): void {
    $page = $this->createPageWithTranslation();

    $this->addOptionalProp();

    // Empty the Spanish inputs in-memory before running the updater.
    $es_translation = $page->getTranslation('es');
    $es_item = $es_translation->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($es_item);
    $es_item->setInput([]);

    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    self::assertTrue($manager->updateComponentInstances($tree));

    // The updater runs without error. The new optional prop is not seeded
    // (the updater skips optional props); the version is bumped.
    $es_item_after = $page->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($es_item_after);
    self::assertArrayNotHasKey('voice', $es_item_after->getInputs() ?? []);
    self::assertNotSame($this->originalVersion, $es_item_after->getComponentVersion());
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
    self::assertSame('opcional ES', $es_inputs['optional_text']);
  }

  /**
   * Tests that deleting a slot cleans up the orphaned child in translations.
   */
  public function testSlotDeletedCleanup(): void {
    $page = Page::create([
      'title' => 'Slot test page',
      'langcode' => 'en',
      'components' => [
        [
          'uuid' => self::COMPONENT_UUID,
          'component_id' => 'js.translatable_js_component',
          'component_version' => $this->originalVersion,
          'parent_uuid' => NULL,
          'inputs' => ['required_text' => 'Parent EN'],
        ],
        [
          'uuid' => self::SECOND_UUID,
          'component_id' => 'js.translatable_js_component',
          'component_version' => $this->originalVersion,
          'parent_uuid' => self::COMPONENT_UUID,
          'slot' => 'test_slot',
          'inputs' => ['required_text' => 'Child EN'],
        ],
      ],
    ]);
    self::assertSame(SAVED_NEW, $page->save());

    $translation = $page->addTranslation('es');
    $translation->set('title', 'Página de prueba');
    $translation->set('components', $page->get('components')->getValue());
    $es_tree = $translation->getComponentTree();
    $child_item = $es_tree->getComponentTreeItemByUuid(self::SECOND_UUID);
    \assert($child_item !== NULL);
    $child_item->setInput(['required_text' => 'Hijo ES']);
    $translation->save();

    $page = Page::load($page->id());
    \assert($page instanceof Page);

    // Delete every slot from the component — orphans the child instance.
    $this->jsComponent->set('slots', [])->save();

    $tree = $page->getComponentTree();
    self::assertCount(2, $tree);
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    self::assertTrue($manager->updateComponentInstances($tree));

    // The child is gone from the (shared) default-translation tree.
    self::assertCount(1, $tree);
    self::assertNull($tree->getComponentTreeItemByUuid(self::SECOND_UUID));

    // Persist the pruned tree (as the controller does) and reload.
    $page->setComponentTree($tree->getValue());
    $page->save();
    \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
    $page = Page::load($page->id());
    \assert($page instanceof Page);

    // The Spanish translation must no longer carry the orphaned child's inputs,
    // while the parent's translated inputs stay intact.
    self::assertNull(self::getInputs($page, 'es', self::SECOND_UUID), 'Translated inputs for a child in a deleted slot must be cleaned up.');
    self::assertNotNull(self::getInputs($page, 'es', self::COMPONENT_UUID));
  }

  /**
   * Tests that adding a slot leaves existing translations intact.
   */
  public function testNewSlotAddedPreservesTranslations(): void {
    $page = $this->createPageWithTranslation();

    // Add a new slot to the component.
    $slots = $this->jsComponent->get('slots');
    $slots['new-slot'] = [
      'title' => 'new',
      'description' => 'A new slot',
      'examples' => ['New slot content'],
    ];
    $this->jsComponent->set('slots', $slots)->save();

    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    self::assertTrue($manager->updateComponentInstances($tree));

    $es_inputs = self::getInputs($page, 'es', self::COMPONENT_UUID);
    self::assertNotNull($es_inputs);
    self::assertSame('Hola mundo', $es_inputs['required_text']);
    self::assertSame('opcional ES', $es_inputs['optional_text']);
  }

  /**
   * Previews the requested translation, creating the necessary auto-saves.
   *
   * @param int|string $page_id
   *   The Page entity ID.
   * @param string $langcode
   *   The translation langcode to preview.
   */
  private static function previewTranslation(int|string $page_id, string $langcode): void {
    $layout_controller = \Drupal::classResolver(ApiLayoutController::class);
    \assert($layout_controller instanceof ApiLayoutController);
    \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID)->resetCache();
    \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
    $reloaded = Page::load($page_id);
    \assert($reloaded instanceof Page);
    $translation = $reloaded->hasTranslation($langcode) ? $reloaded->getTranslation($langcode) : $reloaded;
    $layout_controller->get($translation);
  }

  /**
   * Tests that previewing a translation creates its reconciled auto-save.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::get()
   */
  public function testControllerCreatesTranslationAutoSaves(): void {
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);

    $page = $this->createPageWithTranslation();
    $page_id = $page->id();
    \assert($page_id !== NULL);
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    self::assertTrue($auto_save_manager->getAutoSaveEntity($page)->isEmpty());

    // New required prop → new component version.
    $this->addRequiredProp();

    // Previewing a translation creates an auto-save for translation + default.
    self::previewTranslation($page_id, 'es');
    self::assertCount(2, $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE), 'Previewing both translations creates an auto-save for each.');

    // The ES auto-save carries the reconciled inputs (new required prop +
    // preserved translated value).
    \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
    $page = Page::load($page_id);
    \assert($page instanceof Page);
    $es_auto_save = $auto_save_manager->getAutoSaveEntity($page->getTranslation('es'));
    self::assertFalse($es_auto_save->isEmpty());
    \assert($es_auto_save->entity instanceof Page);
    $es_inputs = $es_auto_save->entity->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID)?->getInputs();
    self::assertNotNull($es_inputs);
    self::assertArrayHasKey('voice', $es_inputs);
    self::assertSame('polite', $es_inputs['voice']);
    self::assertSame('Hola mundo', $es_inputs['required_text']);
  }

  /**
   * Publishing the reconciled translations applies the new version to all.
   *
   * After a component version change, only previewing the default translation
   * creates a reconciled auto-save also for the `es` translation. Publishing
   * only the default translation applies the new version and the reconciled
   * inputs to all translations, with translatable values preserved.
   *
   * @param string[] $selected_langcodes
   *   The translations selected to publish.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiAutoSaveController::post()
   */
  #[DataProvider('providerPublishSelection')]
  public function testPublishAfterPropagationSucceeds(array $selected_langcodes): void {
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION]);

    $page = $this->createPageWithTranslation();
    $page_id = $page->id();
    \assert($page_id !== NULL);

    // New required prop → new component version.
    $this->addRequiredProp();

    self::previewTranslation($page_id, 'en');

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $all_auto_saves = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertCount(2, $all_auto_saves, 'Both EN and ES auto-saves exist after previewing.');

    // Build the publish payload from only the selected translations.
    $client_payload = [];
    foreach ($all_auto_saves as $key => $entry) {
      foreach ($selected_langcodes as $langcode) {
        if (\str_ends_with($key, ':' . $langcode)) {
          $client_payload[$key] = ['data_hash' => $entry['data_hash']];
        }
      }
    }
    self::assertCount(\count($selected_langcodes), $client_payload, 'Publish payload contains only the selected translations.');
    $request = Request::create('/canvas/api/v0/auto-saves/publish', 'POST', content: (string) \json_encode($client_payload));

    $publish_controller = \Drupal::classResolver(ApiAutoSaveController::class);
    \assert($publish_controller instanceof ApiAutoSaveController);
    $response = $publish_controller->post($request);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    // Every selected translation is published with the new required prop, the
    // new version, and its original translatable value intact.
    \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
    $published = Page::load($page_id);
    \assert($published instanceof Page);
    $expected_required_text = ['en' => 'Hello world', 'es' => 'Hola mundo'];
    foreach ($selected_langcodes as $langcode) {
      $inputs = self::getInputs($published, $langcode, self::COMPONENT_UUID);
      self::assertNotNull($inputs, "$langcode inputs exist after publishing.");
      self::assertArrayHasKey('voice', $inputs, "$langcode published with the new required prop.");
      self::assertSame('polite', $inputs['voice']);
      self::assertSame($expected_required_text[$langcode], $inputs['required_text']);
    }

    // The selected translations' auto-saves are consumed.
    $remaining = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    foreach (\array_keys($client_payload) as $published_key) {
      self::assertArrayNotHasKey($published_key, $remaining, 'Published auto-save must be consumed.');
    }
  }

  public static function providerPublishSelection(): \Generator {
    yield 'both translations selected' => [['en', 'es']];
    yield 'only the default (en) translation selected' => [['en']];
    yield 'only the non-default (es) translation selected' => [['es']];
  }

  /**
   * Tests all translations are updated, and an existing auto-save is respected.
   *
   * A translation can carry an auto-save created at the old component version:
   * the Content Creator drafted it, then the component evolved and either the
   * default translation was previewed, or a translation's. That stale auto-save
   * must not be published as-is: it must also get its component instances
   * updated, without losing the Content Creator's data.
   *
   * @param string $preview_langcode
   * @param 'translate-before-auto-save'|'translate-after-auto-save' $scenario
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiAutoSaveController::post()
   * @todo Expand this to test with a user-created non-default language auto-save when asymmetrical translation support is added in https://git.drupalcode.org/project/canvas/-/work_items/3571130
   */
  #[TestWith(['en', 'translate-before-auto-save'])]
  #[TestWith(['es', 'translate-before-auto-save'])]
  #[TestWith(['en', 'translate-after-auto-save'])]
  #[TestWith(['es', 'translate-after-auto-save'])]
  public function testPreviewTriggersInstanceUpdateWrittenToAutoSaveForAllTranslations(string $preview_langcode, string $scenario): void {
    \assert(\in_array($scenario, ['translate-before-auto-save', 'translate-after-auto-save'], TRUE));

    $get_version_and_inputs = fn (?ComponentTreeItem $instance) => [
      'version' => $instance?->getComponentVersion(),
      'inputs' => $instance?->getInputs(),
    ];

    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION]);

    $page = $this->createPageWithTranslation();
    $page_id = $page->id();
    \assert($page_id !== NULL);

    // Record the essential aspects of the "before" state.
    $actual['en']['before'] = $get_version_and_inputs($page->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID));
    $actual['es']['before'] = $get_version_and_inputs($page->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID));

    if ($scenario === 'translate-after-auto-save') {
      $page->removeTranslation('es');
      $page->save();
      self::assertSame(['en'], \array_keys($page->getTranslationLanguages(TRUE)));
    }

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    $get_component_instance_inputs = fn (array $auto_save_entry) => [
      'component_version' => $auto_save_entry['data']['components'][0]['component_version'],
      'inputs' => \json_decode($auto_save_entry['data']['components'][0]['inputs'], TRUE, flags: \JSON_THROW_ON_ERROR),
    ];

    // The Content Creator drafts a change to the default translation, creating
    // an EN auto-save before the code component evolves. Deletes the value for
    // the (untranslatable) `features` prop, changes the strings for the two
    // others.
    $tree = $page->getComponentTree();
    $item = $tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($item);
    $item->setInput([
      'required_text' => 'YO',
      'optional_text' => 'HO',
    ]);
    $page->setComponentTree($tree->getValue());
    $auto_save_manager->saveEntity($page);
    self::assertSame(
      [
        'canvas_page:1:en' => [
          'component_version' => $this->originalVersion,
          'inputs' => [
            'required_text' => 'YO',
            'optional_text' => 'HO',
          ],
        ],
      ],
      \array_map(
        $get_component_instance_inputs,
        $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE),
      ),
    );

    if ($scenario === 'translate-after-auto-save') {
      $translation = $page->addTranslation('es');
      $translation->set('title', 'Página de prueba');
      $translation->set('components', $page->get('components')->getValue());
      $es_tree = $translation->getComponentTree();
      $es_item = $es_tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
      self::assertNotNull($es_item);
      $es_item->setInput(self::ES_TRANSLATION_INPUTS);
      $translation->save();
      \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
      $page = Page::load($page_id);
      \assert($page instanceof Page);
      self::assertSame(['en', 'es'], \array_keys($page->getTranslationLanguages(TRUE)));
    }

    // The component evolves: optional_text removed, voice added → new version.
    $this->removeAndAddProp();
    $versions = Component::load('js.translatable_js_component')?->getVersions() ?? [];
    self::assertCount(2, $versions);
    [$active_version, $old_version] = $versions;
    self::assertSame($this->originalVersion, $old_version);

    // Even though only a single language (ES or EN) is previewed, an auto-save
    // for the default translation is created (EN) and both auto-saves are
    // updated to use the new component version: because symmetrical
    // translations require them to remain in sync.
    // This also models the real path to a stale per-translation auto-save: a
    // direct write (e.g. via TMGMT) that never goes through Canvas' preview.
    self::previewTranslation($page_id, $preview_langcode);
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    // Both EN and ES auto-saves exist after previewing.
    self::assertSame(
      [
        'canvas_page:1:en' => [
          'component_version' => $active_version,
          'inputs' => [
            'required_text' => 'YO',
          ],
        ],
        'canvas_page:1:es' => [
          'component_version' => $active_version,
          'inputs' => [
            'required_text' => 'Hola mundo',
            // ⚠️ Note how `features` did NOT disappear from ES, even though the
            // Content Creator deleted it from EN. That's because:
            // - ::removeAndAddProp() did not remove this prop from the schema
            // - the Content Creator simply chose to not populate this input in
            //   the default translation (EN).
            // It is permissible for translations to populate optional inputs
            // that are not populated in the default translation.
            'features' => self::ES_TRANSLATION_INPUTS['features'],
          ],
        ],
      ],
      \array_map(
        $get_component_instance_inputs,
        $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE),
      ),
    );

    // The Content Creator should see only the default translation.
    $response = $this->request(Request::create('/canvas/api/v0/auto-saves/pending', 'GET'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $json = \json_decode($response->getContent() ?: '', TRUE, flags: JSON_THROW_ON_ERROR);
    $pending = \array_map(
      fn (array $auto_save_entry) => \array_intersect_key($auto_save_entry, \array_flip(['label', 'data_hash'])),
      $json['data'] ?? [],
    );
    $expected_auto_save_key = Page::ENTITY_TYPE_ID . ':1:en';
    self::assertSame([$expected_auto_save_key], \array_keys($pending));
    self::assertSame($page->label(), $pending[$expected_auto_save_key]['label']);

    // The Content Creator should be able to publish the default translation,
    // and this should publish all symmetrical translations, too.
    $request_body = [
      $expected_auto_save_key => [
        'data_hash' => $pending[$expected_auto_save_key]['data_hash'],
      ],
    ];
    $response = $this->request(Request::create('/canvas/api/v0/auto-saves/publish', 'POST',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode($request_body, flags: \JSON_THROW_ON_ERROR),
    ));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $json = json_decode($response->getContent() ?: '', TRUE, flags: JSON_THROW_ON_ERROR);
    self::assertSame(['message' => "Successfully published 1 item."], $json);

    // Verify the expected translated Page is live, with:
    // - new revision was created
    // - both translations have been updated to the new component version
    // - hence the deleted prop no longer is populated by either translation
    // - the original EN auto-save is respected: `features` is empty in EN
    // - the ES translation still populates `features`
    \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
    $published = Page::load($page_id);
    self::assertNotNull($published);
    self::assertSame((string) 1, $page->getRevisionId());
    self::assertSame((string) 2, $published->getRevisionId());
    // Record the essential aspects of the "after"" state.
    $actual['en']['after'] = $get_version_and_inputs($published->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID));
    $actual['es']['after'] = $get_version_and_inputs($published->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID));

    // Assert the precise before and after, in an optimized-for-reading format.
    // @phpcs:disable
    self::assertSame([
      'before' => ['version' => $old_version,    'inputs' => ['required_text' => 'Hello world', 'optional_text' => 'Optional EN', 'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']]],
      'after'  => ['version' => $active_version, 'inputs' => ['required_text' => 'YO',                                                                                             ]],
    ], $actual['en']);
    self::assertSame([
      'before' => ['version' => $old_version,    'inputs' => ['required_text' => 'Hola mundo', 'optional_text' => 'opcional ES', 'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']]],
      'after'  => ['version' => $active_version, 'inputs' => ['required_text' => 'Hola mundo',                                   'features' => ['Alpha', 'Beta', 'Gamma', 'Delta']]],
    ], $actual['es']);
    // @phpcs:enable
  }

  /**
   * Discarding clears every translation's auto-save, whichever one is acted on.
   *
   * Propagation creates an auto-save in every translation. Because symmetric
   * translation writes the shared component-tree columns for all translations
   * at once, discarding one must clear them all; otherwise a stale sibling
   * draft is left pending. The discard route carries no langcode, so the
   * translation passed in must not change the outcome.
   *
   * @param string $discard_langcode
   *   The translation whose entity is passed to the discard endpoint.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiAutoSaveController::delete()
   * @legacy-covers \Drupal\canvas\AutoSave\AutoSaveManager::getTranslationGroupAutoSaves()
   */
  #[DataProvider('providerDiscardTranslation')]
  public function testDiscardAfterPropagationClearsAllTranslations(string $discard_langcode): void {
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION]);

    $page = $this->createPageWithTranslation();
    $page_id = $page->id();
    \assert($page_id !== NULL);

    $this->addRequiredProp();

    self::previewTranslation($page_id, 'en');

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    self::assertCount(2, $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE));

    \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
    $page = Page::load($page_id);
    \assert($page instanceof Page);
    $target = $page->hasTranslation($discard_langcode) ? $page->getTranslation($discard_langcode) : $page;
    $delete_controller = \Drupal::classResolver(ApiAutoSaveController::class);
    \assert($delete_controller instanceof ApiAutoSaveController);
    $response = $delete_controller->delete($target);
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());

    // Every translation's auto-save is cleared, not just the targeted one.
    self::assertCount(0, $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE));
  }

  public static function providerDiscardTranslation(): \Generator {
    yield 'discard via the default (en) translation' => ['en'];
    yield 'discard via the non-default (es) translation' => ['es'];
  }

}
