<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideAccessControlHandler;
use Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\language\Config\LanguageConfigOverride;

/**
 * A staged LanguageConfigOverride (language configuration override).
 *
 * Stores a language config override that has not yet been applied
 * to the live LanguageConfigOverride record. Lives in auto-save storage until
 * explicitly published, at which point it is written as a real override.
 *
 * The entity ID is "{langcode}.{config_name}" — identical to the key used by
 * the language config override storage — making it trivial to map between the
 * two representations.
 *
 * @see \Drupal\language\Config\LanguageConfigOverride
 * @see \Drupal\canvas\Entity\StagedConfigUpdate
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup("Staged language config override"),
  label_collection: new TranslatableMarkup("Staged language config overrides"),
  label_singular: new TranslatableMarkup("staged language config override"),
  label_plural: new TranslatableMarkup("staged language config overrides"),
  entity_keys: [
    'id' => 'id',
  ],
  handlers: [
    'storage' => StagedLanguageConfigOverrideStorage::class,
    'access' => StagedLanguageConfigOverrideAccessControlHandler::class,
  ],
  config_export: [
    'id',
    'langcode',
    'config_name',
    'data',
  ],
  constraints: [
    'ImmutableProperties' => [
      'id',
      'langcode',
      'config_name',
    ],
  ],
)]
final class StagedLanguageConfigOverride extends ConfigEntityBase implements CanvasHttpApiEligibleConfigEntityInterface, AutoSavePublishAwareInterface {

  public const string ENTITY_TYPE_ID = 'staged_language_config_override';

  /**
   * {@inheritdoc}
   *
   * Disabled by default to prevent the staged override from being applied
   * automatically.
   */
  protected $status = FALSE;

  /**
   * ID, composed of content langcode + config name: "{langcode}.{config_name}".
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\StringPartsConstraint
   */
  protected string $id;

  /**
   * The config name (e.g. canvas.page_region.stark.sidebar_first).
   *
   * The language code is stored in ConfigEntityBase::$langcode.
   */
  protected string $config_name;

  /**
   * The sparse override data.
   *
   * @var array<string, mixed>
   */
  protected array $data = [];

  public function id(): string {
    return $this->id;
  }

  public function label(): TranslatableMarkup {
    // @todo improve
    return new TranslatableMarkup('@langcode translation of @config_name', [
      '@langcode' => $this->langcode,
      '@config_name' => $this->config_name,
    ]);
  }

  /**
   * Returns the config name (target) this override applies to.
   */
  public function getName(): string {
    return $this->config_name;
  }

  /**
   * {@inheritdoc}
   *
   * Returns FALSE when this instance was loaded from auto-save storage by
   * AutoSaveManager. Used by
   * CanvasConfigEntityTranslationsAreValidConstraintValidator to skip
   * validation for overrides that will be validated by
   * LanguageConfigOverrideSchemaChecker when published in the same request.
   */
  public function isNew(): bool {
    return parent::isNew();
  }

  /**
   * Returns whether this staged override has no data.
   *
   * Distinct from ConfigEntityBase::isNew() (which indicates whether the entity
   * has been persisted to auto-save storage). An override is empty when
   * reconciliation pruned all translatable inputs from it.
   */
  public function isEmpty(): bool {
    return empty($this->data);
  }

  /**
   * Creates a staged override from an existing live LanguageConfigOverride.
   */
  public static function fromLanguageConfigOverride(LanguageConfigOverride $stored_override): self {
    $langcode = $stored_override->getLangcode();
    $config_name = $stored_override->getName();
    return self::create([
      'id' => "$langcode.$config_name",
      'langcode' => $langcode,
      'config_name' => $config_name,
      'data' => $stored_override->getRawData(),
    ]);
  }

  /**
   * Creates an empty staged override for a given langcode + config name.
   */
  public static function createEmpty(string $langcode, string $config_name): self {
    return self::create([
      'id' => "$langcode.$config_name",
      'langcode' => $langcode,
      'config_name' => $config_name,
      'data' => [],
    ]);
  }

  /**
   * Returns a value at the given dot-separated key path within the data.
   *
   * @see \Drupal\Core\Config\ConfigBase::get()
   */
  public function getData(string $key = ''): mixed {
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
   * Sets a value at the given dot-separated key path within the data.
   *
   * @see \Drupal\Core\Config\ConfigBase::set()
   */
  public function setData(string $key, mixed $value): self {
    $parts = \explode('.', $key);
    if (\count($parts) === 1) {
      $this->data[$key] = $value;
    }
    else {
      NestedArray::setValue($this->data, $parts, $value);
    }
    return $this;
  }

  /**
   * Clears the value at the given dot-separated key path within the data.
   *
   * @see \Drupal\Core\Config\ConfigBase::clear()
   */
  public function clearData(string $key): self {
    $parts = \explode('.', $key);
    if (\count($parts) === 1) {
      unset($this->data[$key]);
    }
    else {
      NestedArray::unsetValue($this->data, $parts);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTagsToInvalidate(): array {
    // @see \Drupal\canvas\Entity\StagedConfigUpdate::getCacheTagsToInvalidate()
    return ["config:$this->config_name"];
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    return ClientSideRepresentation::create(
      values: [
        'id' => $this->id,
        'langcode' => $this->langcode,
        'config_name' => $this->config_name,
        'data' => $this->data,
      ],
      preview: NULL
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function createFromClientSide(array $data): static {
    return self::create($data);
  }

  /**
   * {@inheritdoc}
   */
  public function updateFromClientSide(array $data): void {
    // Prevent the client from changing the status. It should only be modified
    // when staged changes are published. Also prevent the client from
    // changing the langcode, config name or ID. Only modifying `data` is
    // allowed.
    if (\array_key_exists('data', $data)) {
      parent::set('data', $data['data']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function autoSavePublish(): self {
    // @see \Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage::save()
    $this->setStatus(TRUE);
    return $this;
  }

}
