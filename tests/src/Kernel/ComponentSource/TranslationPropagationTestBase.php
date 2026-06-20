<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;

/**
 * Shared fixture for translation propagation kernel tests.
 *
 * Provides the JavaScriptComponent fixture and prop-mutation helpers that are
 * identical across ConfigEntityTranslationPropagationTest and
 * ContentEntityTranslationPropagationTest.
 *
 * @see \Drupal\Tests\canvas\Kernel\ComponentSource\ConfigEntityTranslationPropagationTest
 * @see \Drupal\Tests\canvas\Kernel\ComponentSource\ContentEntityTranslationPropagationTest
 */
abstract class TranslationPropagationTestBase extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  /**
   * Modules required by both config and content entity propagation tests.
   *
   * Subclasses spread this constant and add their own modules:
   * @code
   *   protected static $modules = [
   *     ...parent::BASE_MODULES,
   *     'my_extra_module',
   *   ];
   * @endcode
   */
  protected const array BASE_MODULES = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'field',
    'language',
  ];

  protected static $modules = self::BASE_MODULES;

  protected JavaScriptComponent $jsComponent;
  protected string $originalVersion;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);

    ConfigurableLanguage::createFromLangcode('es')->save();

    $this->jsComponent = JavaScriptComponent::create([
      'machineName' => static::componentMachineName(),
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

    $component = \Drupal::entityTypeManager()->getStorage('component')->load('js.' . static::componentMachineName());
    self::assertNotNull($component);
    $this->originalVersion = $component->getActiveVersion();
  }

  /**
   * Returns the machine name used when creating the JavaScriptComponent fixture.
   *
   * Subclasses override this to avoid machine name collisions when multiple
   * test classes share the same test database.
   */
  abstract protected static function componentMachineName(): string;

  protected function addOptionalProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    $props['voice'] = ['type' => 'string', 'title' => 'Voice', 'examples' => ['polite']];
    $this->jsComponent->setProps($props)->save();
  }

  protected function addRequiredProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    $props['voice'] = ['type' => 'string', 'title' => 'Voice', 'examples' => ['polite']];
    $required = $this->jsComponent->getRequiredProps();
    $required[] = 'voice';
    $this->jsComponent->setProps($props)->set('required', $required)->save();
  }

  protected function removeOptionalProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    unset($props['optional_text']);
    $this->jsComponent->setProps($props)->save();
  }

  protected function changePropType(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    // Change required_text from string to integer — an unsafe change that
    // blocks the update for all translations.
    $props['required_text'] = ['type' => 'integer', 'title' => 'Required Int', 'examples' => [42]];
    $this->jsComponent->setProps($props)->save();
  }

  protected function removeAndAddProp(): void {
    $props = $this->jsComponent->getProps();
    \assert($props !== NULL);
    unset($props['optional_text']);
    $props['voice'] = ['type' => 'string', 'title' => 'Voice', 'examples' => ['polite']];
    $this->jsComponent->setProps($props)->save();
  }

}
