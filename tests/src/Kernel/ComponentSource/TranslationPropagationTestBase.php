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
 *
 * @phpstan-import-type OptimizedSingleComponentInputArray from \Drupal\canvas\Plugin\DataType\ComponentInputs
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

  /**
   * The ES translation inputs written before each test's component update.
   *
   * Subclasses use this constant both in their setUp() and in providerPropagation()
   * expected values so the two stay automatically in sync.
   */
  protected const array ES_TRANSLATION_INPUTS = [
    'required_text' => 'Hola mundo',
    'optional_text' => 'opcional ES',
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
   * Asserts the ES translation state after the update.
   *
   * @param OptimizedSingleComponentInputArray $expected_content
   *   Exact expected ES inputs for the content entity translation.
   * @param OptimizedSingleComponentInputArray|false $expected_config
   *   Exact expected ES inputs stored in the config entity's
   *   LanguageConfigOverride, or FALSE when the override record is deleted
   *   entirely (all translatable inputs removed).
   */
  abstract protected function assertTranslationAfterUpdate(array $expected_content, array|false $expected_config): void;

  /**
   * Tests that non-default translations are updated when component props change.
   *
   * @param string $setup_method
   *   A method name on $this that mutates $this->jsComponent.
   * @param bool $expected_modified
   *   Whether updateComponentInstances() should report a modification.
   * @param OptimizedSingleComponentInputArray $expected_content
   *   Exact expected ES inputs for the content entity translation after update.
   * @param OptimizedSingleComponentInputArray|false $expected_config
   *   Exact expected ES inputs for the config entity's LanguageConfigOverride
   *   after update, or FALSE when the override is deleted entirely.
   */
  #[DataProvider('providerPropagation')]
  public function testPropagation(
    string $setup_method,
    bool $expected_modified,
    array $expected_content,
    array|false $expected_config,
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

    $this->assertTranslationAfterUpdate($expected_content, $expected_config);
  }

  public static function providerPropagation(): \Generator {
    yield 'New optional prop added — translation unchanged (updater skips optional props)' => [
      'setup_method' => 'addOptionalProp',
      'expected_modified' => TRUE,
      'expected_content' => self::ES_TRANSLATION_INPUTS,
      'expected_config' => self::ES_TRANSLATION_INPUTS,
    ];
    yield 'New required prop added — translation gets example value' => [
      'setup_method' => 'addRequiredProp',
      'expected_modified' => TRUE,
      'expected_content' => self::ES_TRANSLATION_INPUTS + ['voice' => 'polite'],
      'expected_config' => self::ES_TRANSLATION_INPUTS,
    ];
    yield 'Optional prop deleted — orphaned input removed from translation' => [
      'setup_method' => 'removeOptionalProp',
      'expected_modified' => TRUE,
      'expected_content' => ['required_text' => 'Hola mundo'],
      'expected_config' => ['required_text' => 'Hola mundo'],
    ];
    yield 'Unsafe prop type change — update blocked, translation unchanged' => [
      'setup_method' => 'changePropType',
      'expected_modified' => FALSE,
      'expected_content' => self::ES_TRANSLATION_INPUTS,
      'expected_config' => self::ES_TRANSLATION_INPUTS,
    ];
    yield 'Prop removed and another added — removed key gone, new optional key absent from translation' => [
      'setup_method' => 'removeAndAddProp',
      'expected_modified' => TRUE,
      'expected_content' => ['required_text' => 'Hola mundo'],
      'expected_config' => ['required_text' => 'Hola mundo'],
    ];
    yield 'All translatable props deleted — config override deleted entirely, content inputs emptied' => [
      'setup_method' => 'removeBothProps',
      'expected_modified' => TRUE,
      'expected_content' => [],
      'expected_config' => FALSE,
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
