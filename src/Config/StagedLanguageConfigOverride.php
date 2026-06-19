<?php

declare(strict_types=1);

namespace Drupal\canvas\Config;

use Drupal\Component\Utility\NestedArray;
use Drupal\language\Config\LanguageConfigOverride;

/**
 * An in-memory, unsaved language configuration override.
 *
 * Mirrors the subset of LanguageConfigOverride's API used by
 * ComponentTreeItemList::reconcileConfigEntityTranslations(), but does NOT
 * write to storage. The caller is responsible for persisting the staged data
 * (e.g. via auto-save) when it chooses to do so.
 *
 * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::reconcileConfigEntityTranslations()
 * @see \Drupal\language\Config\LanguageConfigOverride
 */
final class StagedLanguageConfigOverride {

  /**
   * The data held in this staged override.
   *
   * @var array<string, mixed>
   */
  private array $data;

  /**
   * Whether this staged override is "new" (i.e. has no data yet).
   */
  private bool $isNew;

  /**
   * The language code this override applies to.
   */
  private string $langcode;

  /**
   * The config name (e.g. canvas.page_region.stark.sidebar_first).
   */
  private string $configName;

  private function __construct(string $langcode, string $config_name, array $data, bool $is_new) {
    $this->langcode = $langcode;
    $this->configName = $config_name;
    $this->data = $data;
    $this->isNew = $is_new;
  }

  /**
   * Creates a staged override from an existing live LanguageConfigOverride.
   *
   * Copies the stored data in-memory so that reconciliation does not touch the
   * live override record until the caller explicitly persists the staged state.
   */
  public static function fromLanguageConfigOverride(LanguageConfigOverride $stored_override): self {
    return new self(
      langcode: $stored_override->getLangcode(),
      config_name: $stored_override->getName(),
      data: $stored_override->getRawData(),
      is_new: $stored_override->isNew(),
    );
  }

  /**
   * Creates an empty staged override for a given langcode + config name.
   */
  public static function createEmpty(string $langcode, string $config_name): self {
    return new self(
      langcode: $langcode,
      config_name: $config_name,
      data: [],
      is_new: TRUE,
    );
  }

  public function getLangcode(): string {
    return $this->langcode;
  }

  public function getName(): string {
    return $this->configName;
  }

  public function isNew(): bool {
    return $this->isNew;
  }

  /**
   * Returns all data in this staged override.
   *
   * @return array<string, mixed>
   */
  public function get(string $key = ''): mixed {
    if ($key === '') {
      return $this->data;
    }
    $parts = \explode('.', $key);
    if (\count($parts) === 1) {
      return $this->data[$key] ?? NULL;
    }
    $value = NestedArray::getValue($this->data, $parts, $key_exists);
    return $key_exists ? $value : NULL;
  }

  /**
   * Sets a value at the given dot-separated key path.
   */
  public function set(string $key, mixed $value): self {
    $parts = \explode('.', $key);
    if (\count($parts) === 1) {
      $this->data[$key] = $value;
    }
    else {
      NestedArray::setValue($this->data, $parts, $value);
    }
    $this->isNew = empty($this->data);
    return $this;
  }

  /**
   * Clears the value at the given dot-separated key path.
   */
  public function clear(string $key): self {
    $parts = \explode('.', $key);
    if (\count($parts) === 1) {
      unset($this->data[$key]);
    }
    else {
      NestedArray::unsetValue($this->data, $parts);
    }
    $this->isNew = empty($this->data);
    return $this;
  }

  /**
   * Returns the raw data array.
   *
   * @return array<string, mixed>
   */
  public function getRawData(): array {
    return $this->data;
  }

}
