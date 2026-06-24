<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore mundo Opcional Hola Página prueba Optionnel Etiqueta Española Hijo EDITADO

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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
   * {@inheritdoc}
   */
  protected function setUpTranslation(): Page {
    $this->entity = $this->createPageWithTranslation();
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function assertTranslationAfterUpdate(bool $was_modified, ?string $new_key, bool $new_key_is_required, ?string $removed_key): void {
    \assert($this->entity instanceof Page);
    $es_inputs = self::getInputs($this->entity, 'es', self::COMPONENT_UUID);
    self::assertNotNull($es_inputs);

    if ($new_key !== NULL) {
      if ($new_key_is_required) {
        self::assertArrayHasKey($new_key, $es_inputs, 'New required prop must be seeded in translation.');
      }
      else {
        self::assertArrayNotHasKey($new_key, $es_inputs, 'New optional prop must not be injected into translation (updater skips optional props).');
      }
    }
    if ($removed_key !== NULL) {
      self::assertArrayNotHasKey($removed_key, $es_inputs, 'Removed prop must be absent from translation inputs.');
    }
    if ($was_modified) {
      self::assertSame('Hola mundo', $es_inputs['required_text'] ?? NULL, 'Existing translatable prop must be preserved.');
    }
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
    $this->generateComponentConfig();

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
   * Tests that a component instance's per-translation label is preserved.
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
   * Tests that empty translation inputs are handled without error.
   *
   * Simulates content_translation's FieldTranslationSynchronizer creating a new
   * delta with empty translatable columns: the updater must run without error
   * and bump the component version even when inputs are empty.
   */
  public function testEmptyTranslationInputsHandled(): void {
    $page = $this->createPageWithTranslation();

    $this->addOptionalProp();
    $this->generateComponentConfig();

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
    $this->generateComponentConfig();

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
    $this->generateComponentConfig();

    $tree = $page->getComponentTree();
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    self::assertTrue($manager->updateComponentInstances($tree));

    $es_inputs = self::getInputs($page, 'es', self::COMPONENT_UUID);
    self::assertNotNull($es_inputs);
    self::assertSame('Hola mundo', $es_inputs['required_text']);
    self::assertSame('Opcional ES', $es_inputs['optional_text']);
  }

  /**
   * Previews each given translation, creating a reconciled auto-save for each.
   *
   * Mirrors the editor loading each language in turn: every GET runs
   * updateComponentInstances() and, when that reports a change, persists the
   * translation's reconciled tree as a per-translation auto-save. Each preview
   * uses a freshly loaded entity, as a real request would.
   *
   * @param int|string $page_id
   *   The Page entity ID.
   * @param string[] $langcodes
   *   The translation langcodes to preview.
   */
  private static function previewTranslations(int|string $page_id, array $langcodes): void {
    $layout_controller = \Drupal::classResolver(ApiLayoutController::class);
    \assert($layout_controller instanceof ApiLayoutController);
    foreach ($langcodes as $langcode) {
      \Drupal::entityTypeManager()->getStorage('component')->resetCache();
      \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
      $reloaded = Page::load($page_id);
      \assert($reloaded instanceof Page);
      $translation = $reloaded->hasTranslation($langcode) ? $reloaded->getTranslation($langcode) : $reloaded;
      $layout_controller->get($translation);
    }
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
    $this->generateComponentConfig();

    // Preview both translations; each creates its own reconciled auto-save.
    self::previewTranslations($page_id, ['en', 'es']);

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
   * After a component version change, each previewed translation has a
   * reconciled auto-save. Publishing them applies the new version and the
   * reconciled inputs to every selected translation, with translatable values
   * preserved.
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
    $this->generateComponentConfig();

    // Preview both translations so each has a reconciled auto-save.
    self::previewTranslations($page_id, ['en', 'es']);

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
   * Publishing reconciles a translation auto-save left at an older version.
   *
   * A translation can carry an auto-save taken at the old component version:
   * the editor drafted it, then the component evolved and only another
   * translation was re-previewed. That stale snapshot must not be published
   * as-is. Publishing reconciles it to the active version — preserving the
   * editor's translated value, pruning deleted props, and bumping the version —
   * so no translation is ever published outdated.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiAutoSaveController::post()
   */
  public function testPublishReconcilesStaleTranslationAutoSave(): void {
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION]);

    $page = $this->createPageWithTranslation();
    $page_id = $page->id();
    \assert($page_id !== NULL);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    // The editor drafts the ES translation at the original version, creating an
    // ES auto-save before the component evolves.
    $es_page = $page->getTranslation('es');
    $es_tree = $es_page->getComponentTree();
    $es_item = $es_tree->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    \assert($es_item !== NULL);
    $es_item->setInput([
      'required_text' => 'Hola mundo EDITADO',
      'optional_text' => 'Opcional EDITADO',
    ]);
    $es_page->setComponentTree($es_tree->getValue());
    $auto_save_manager->saveEntity($es_page);
    self::assertFalse($auto_save_manager->getAutoSaveEntity($es_page)->isEmpty());

    // The component evolves: optional_text removed, voice added → new version.
    $this->removeAndAddProp();
    $this->generateComponentConfig();

    // Only EN is re-previewed, so only its auto-save is reconciled; the ES
    // auto-save is left at the original version.
    self::previewTranslations($page_id, ['en']);

    // Sanity: the stored ES auto-save is still at the original version, carrying
    // the now-deleted optional_text.
    \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
    $page = Page::load($page_id);
    \assert($page instanceof Page);
    $stale = $auto_save_manager->getAutoSaveEntity($page->getTranslation('es'));
    self::assertFalse($stale->isEmpty());
    \assert($stale->entity instanceof Page);
    $stale_item = $stale->entity->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($stale_item);
    self::assertSame($this->originalVersion, $stale_item->getComponentVersion(), 'The ES auto-save remains at the original version before publishing.');
    self::assertArrayHasKey('optional_text', $stale_item->getInputs() ?? []);

    // Publish both translations together.
    $all_auto_saves = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertCount(2, $all_auto_saves);
    $client_payload = [];
    foreach ($all_auto_saves as $key => $entry) {
      $client_payload[$key] = ['data_hash' => $entry['data_hash']];
    }
    $request = Request::create('/canvas/api/v0/auto-saves/publish', 'POST', content: (string) \json_encode($client_payload));
    $publish_controller = \Drupal::classResolver(ApiAutoSaveController::class);
    \assert($publish_controller instanceof ApiAutoSaveController);
    $response = $publish_controller->post($request);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    // The published ES translation is reconciled: the editor's translated value
    // survives, the deleted prop is gone, and the version matches the default.
    \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
    $published = Page::load($page_id);
    \assert($published instanceof Page);
    $es_inputs = self::getInputs($published, 'es', self::COMPONENT_UUID);
    self::assertNotNull($es_inputs);
    self::assertSame('Hola mundo EDITADO', $es_inputs['required_text'], 'The translated value is preserved.');
    self::assertArrayNotHasKey('optional_text', $es_inputs, 'The deleted prop must not be published.');

    $en_version = $published->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID)?->getComponentVersion();
    $es_version = $published->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID)?->getComponentVersion();
    self::assertNotSame($this->originalVersion, $es_version, 'The ES translation is published at the new version, not the original.');
    self::assertSame($en_version, $es_version, 'Both translations are published at the same component version.');
  }

  /**
   * Discarding a translation's auto-save clears it via the delete endpoint.
   *
   * @param string $discard_langcode
   *   The translation whose auto-save is deleted.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiAutoSaveController::delete()
   */
  #[DataProvider('providerDiscardTranslation')]
  public function testDiscardAfterPropagationClearsTranslation(string $discard_langcode): void {
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION]);

    $page = $this->createPageWithTranslation();
    $page_id = $page->id();
    \assert($page_id !== NULL);

    $this->addRequiredProp();
    $this->generateComponentConfig();

    self::previewTranslations($page_id, ['en', 'es']);

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

    // The targeted translation's auto-save is gone.
    \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->resetCache();
    $page = Page::load($page_id);
    \assert($page instanceof Page);
    $target = $page->hasTranslation($discard_langcode) ? $page->getTranslation($discard_langcode) : $page;
    self::assertTrue($auto_save_manager->getAutoSaveEntity($target)->isEmpty(), 'Discarded translation auto-save must be cleared.');
  }

  public static function providerDiscardTranslation(): \Generator {
    yield 'discard via the default (en) translation' => ['en'];
    yield 'discard via the non-default (es) translation' => ['es'];
  }

}
