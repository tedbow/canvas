<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Shared fixture for symmetrical translation propagation kernel tests.
 *
 * Provides the JavaScriptComponent fixture and prop-mutation helpers that are
 * identical across all translation propagation test classes.
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
