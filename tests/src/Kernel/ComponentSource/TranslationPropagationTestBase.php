<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Shared fixture for symmetrical translation propagation kernel tests.
 *
 * Provides the JavaScriptComponent fixture, prop-mutation helpers, a shared
 * testPropagation() test, and its data provider. Concrete subclasses supply
 * the entity-type-specific translation setup and assertion logic via three
 * abstract methods.
 */
abstract class TranslationPropagationTestBase extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'language',
    // - Content-defined component trees: validates symmetrically translations
    // - Config-defined component trees: makes PageRegion and ContentTemplate
    //   config entities translatable
    'canvas_dev_translation',
  ];

  protected JavaScriptComponent $jsComponent;
  protected string $originalVersion;

  /**
   * @var \Drupal\canvas\Entity\ComponentTreeConfigEntityBase|\Drupal\Core\Entity\ContentEntityInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);

    ConfigurableLanguage::createFromLangcode('es')->save();

    $this->jsComponent = JavaScriptComponent::create([
      'machineName' => 'translatable_js_component',
      'name' => 'Prop Propagation Test',
      'status' => TRUE,
      'props' => [
        'required_text' => [
          'type' => 'string',
          'title' => 'Required Text',
          'examples' => ['Press'],
        ],
        'optional_text' => [
          'type' => 'string',
          'title' => 'Optional Text',
          'examples' => ['Click me'],
        ],
      ],
      'required' => ['required_text'],
      'slots' => [
        'test_slot' => [
          'title' => 'Test slot',
          'description' => 'A slot used to exercise exposed-slot translations.',
          'examples' => ['Slot content'],
        ],
      ],
      'js' => [
        'original' => 'console.log("test")',
        'compiled' => 'console.log("test")',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'dataDependencies' => [],
    ]);
    self::assertSame(SAVED_NEW, $this->jsComponent->save());
    $this->generateComponentConfig();

    $component_id = JsComponentDiscovery::getComponentConfigEntityId($this->jsComponent->id());
    $component = Component::load($component_id);
    self::assertNotNull($component);
    $this->originalVersion = $component->getActiveVersion();
  }

  /**
   * Sets up the non-default (ES) translation before the component is updated.
   *
   * Called once at the start of testPropagation(), before the prop mutation
   * and generateComponentConfig() are called.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|\Drupal\canvas\Entity\ComponentTreeConfigEntityBase
   *   The default-translation entity whose component tree will be updated.
   */
  abstract protected function setUpTranslation(): ContentEntityInterface|ComponentTreeConfigEntityBase;

  /**
   * Asserts the non-default (ES) translation state after the update.
   *
   * @param bool $was_modified
   *   Whether updateComponentInstances() reported a modification.
   * @param string|null $new_key
   *   The name of the new prop added to the component, or NULL if none.
   * @param bool $new_key_is_required
   *   Whether the new prop is required. Ignored when $new_key is NULL.
   * @param string|null $removed_key
   *   The name of the prop removed from the component, or NULL if none.
   */
  abstract protected function assertTranslationAfterUpdate(bool $was_modified, ?string $new_key, bool $new_key_is_required, ?string $removed_key): void;

  /**
   * Tests that non-default translations are updated when component props change.
   *
   * @param string $setup_method
   *   A method name on $this that mutates $this->jsComponent.
   * @param bool $expected_modified
   *   Whether updateComponentInstances() should report a modification.
   * @param string|null $new_key
   *   The name of the new prop added to the component, or NULL if none.
   * @param bool $new_key_is_required
   *   Whether the new prop is required. Ignored when $new_key is NULL.
   * @param string|null $removed_key
   *   The name of the prop removed from the component, or NULL if none.
   */
  #[DataProvider('providerPropagation')]
  public function testPropagation(
    string $setup_method,
    bool $expected_modified,
    ?string $new_key,
    bool $new_key_is_required,
    ?string $removed_key,
  ): void {
    $this->entity = $this->setUpTranslation();
    self::assertEntityIsValid($this->entity);

    $this->{$setup_method}();
    $this->generateComponentConfig();

    $loader = $this->container->get(ComponentTreeLoader::class);
    \assert($loader instanceof ComponentTreeLoader);
    $tree = $loader->load($this->entity);
    $manager = $this->container->get(ComponentSourceManager::class);
    \assert($manager instanceof ComponentSourceManager);
    $was_modified = $manager->updateComponentInstances($tree);
    self::assertSame($expected_modified, $was_modified);

    $this->assertTranslationAfterUpdate($was_modified, $new_key, $new_key_is_required, $removed_key);
  }

  public static function providerPropagation(): \Generator {
    yield 'New optional prop added — translation unchanged (updater skips optional props)' => [
      'setup_method' => 'addOptionalProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'new_key_is_required' => FALSE,
      'removed_key' => NULL,
    ];
    yield 'New required prop added — translation gets example value' => [
      'setup_method' => 'addRequiredProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'new_key_is_required' => TRUE,
      'removed_key' => NULL,
    ];
    yield 'Optional prop deleted — orphaned input removed from translation' => [
      'setup_method' => 'removeOptionalProp',
      'expected_modified' => TRUE,
      'new_key' => NULL,
      'new_key_is_required' => FALSE,
      'removed_key' => 'optional_text',
    ];
    yield 'Unsafe prop type change — update blocked, translation unchanged' => [
      'setup_method' => 'changePropType',
      'expected_modified' => FALSE,
      'new_key' => NULL,
      'new_key_is_required' => FALSE,
      'removed_key' => NULL,
    ];
    yield 'Prop removed and another added — removed key gone, new optional key absent from translation' => [
      'setup_method' => 'removeAndAddProp',
      'expected_modified' => TRUE,
      'new_key' => 'voice',
      'new_key_is_required' => FALSE,
      'removed_key' => 'optional_text',
    ];
  }

  protected function addOptionalProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(!\array_key_exists('voice', $props));
    $props['voice'] = ['type' => 'string', 'title' => 'Voice', 'examples' => ['polite']];
    $this->jsComponent->setProps($props)->save();
  }

  protected function addRequiredProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(!\array_key_exists('voice', $props));
    $props['voice'] = ['type' => 'string', 'title' => 'Voice', 'examples' => ['polite']];
    $required = $this->jsComponent->getRequiredProps();
    $required[] = 'voice';
    $this->jsComponent->setProps($props)->set('required', $required)->save();
  }

  protected function removeOptionalProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(\array_key_exists('optional_text', $props));
    unset($props['optional_text']);
    $this->jsComponent->setProps($props)->save();
  }

  protected function changePropType(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(\array_key_exists('required_text', $props));
    // Change required_text from string to integer — an unsafe change that
    // blocks the update for all translations.
    $props['required_text'] = ['type' => 'integer', 'title' => 'Required Int', 'examples' => [42]];
    $this->jsComponent->setProps($props)->save();
  }

  protected function removeAndAddProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(\array_key_exists('optional_text', $props));
    \assert(!\array_key_exists('voice', $props));
    unset($props['optional_text']);
    $props['voice'] = ['type' => 'string', 'title' => 'Voice', 'examples' => ['polite']];
    $this->jsComponent->setProps($props)->save();
  }

  protected function removeBothProps(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    \assert(\array_key_exists('optional_text', $props));
    \assert(\array_key_exists('required_text', $props));
    \assert(!\array_key_exists('count', $props));
    unset($props['required_text'], $props['optional_text']);
    $props['count'] = ['type' => 'integer', 'title' => 'Count', 'examples' => [3]];
    $this->jsComponent->setProps($props)->set('required', [])->save();
  }

}
