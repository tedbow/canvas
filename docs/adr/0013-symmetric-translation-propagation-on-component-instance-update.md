# 13. Symmetric translation propagation on component instance update

Date: 2026-06-20

Issue: <https://www.drupal.org/project/canvas/issues/3591596>

## Status

Accepted

## Context

[ADR #6](0006-One-field-row-per-component-instance.md) established that each component instance stores a `component_version` alongside its `inputs`. When a component's implementation changes, Canvas creates a new version of the `Component` config entity and existing component instances must be migrated forward: obsolete input keys removed, newly required input keys seeded with defaults, and the stored `component_version` updated to match.

[ADR #10](0010-dynamic-config-schema-for-component-tree-translatability.md) established that each component instance's inputs have a per-instance translatability classification: some input keys are translatable (e.g. a static string prop typed by the content author), others are not (e.g. a prop whose value is derived from an entity field). This classification is determined dynamically from the component source's schema generator.

[ADR #12](0012-symmetric-content-translation-store-all-inputs-validate-at-write-time.md) established that every translation stores the full set of inputs for every component instance — both translatable and non-translatable key-value pairs — and that non-translatable keys are synchronized from the default translation at write time.

### The gap: version updates were not propagated to translations

Before this ADR, component instance version updates were applied only to the default translation's component tree. This created a structural integrity problem: after an update, the default translation's component instances referenced the new component version with updated inputs, but every non-default translation's component instances still referenced the old version and still held inputs shaped for that old version.

This violated the invariant from ADR #12 (all translations store complete, synchronized inputs) and produced a new class of integrity problems specific to version transitions:

1. **Stale `component_version`**: non-default translations still carried the old version identifier after the default translation was updated, making it impossible to render the non-default translations correctly.

2. **Orphaned input keys**: inputs for props that no longer exist in the new version persisted in non-default translations, producing invalid stored state.

3. **Missing input keys for new props**: new props introduced by a version update were absent from non-default translations entirely, even for required props or non-translatable props whose value should be identical to the default translation.

4. **Non-translatable inputs diverged**: for a prop that existed before the update and is non-translatable, the default translation's value may change during the update (e.g. a default is computed from a new component implementation); non-default translations holding the old value would then diverge from the default, violating the invariant in ADR #12.

### Symmetric translations make the coupling explicit

The symmetric translation model — same component tree structure across all translations, only inputs may differ per language — means that a component instance update on the default translation is structurally a change to all translations at once. The structural identity of the tree (which instances exist, in which parent-child relationships, carrying which component identities and versions) is shared. Input propagation must therefore happen at the same moment the default translation is updated, not deferred to a later save cycle.

### Config entities require a different propagation mechanism

Content entities store each translation as a separate entity object (via `TranslatableInterface`), and all translations are loaded in memory during a request that triggers an update. Config entity translations, however, are stored as `LanguageConfigOverride` records that contain only the overridden (translatable) keys — they do not store a complete copy of the base config. Propagation for config entities must operate directly on those override records rather than on full entity objects.

## Decision

### 1. Component instance update propagation belongs at the component tree data model layer

The reconciliation of non-default translations after a component instance version update is a responsibility of the component tree data model itself — not of any application service, controller, or HTTP layer. The data model already owns the invariant (ADR #12) that all translations carry complete, synchronized inputs; maintaining that invariant through version transitions is a natural extension of the same responsibility.

This keeps the entry point for updates (the component source manager that iterates the default translation's tree and applies version updates) minimal: it triggers propagation once, after all default-translation updates are complete, by delegating to the data model layer.

### 2. Propagation is triggered exactly once, after all default-translation updates complete

Before any update is applied, a snapshot of the current state of each to-be-updated component instance is captured: inputs before the update, inputs after the update, the new version identifier, and the full set of prop defaults defined by the new component version. After all default-translation instances have been updated, propagation is triggered with the complete set of snapshots.

This batch-then-propagate ordering means non-default translations see a consistent view of all changes atomically, rather than receiving a partial update mid-iteration.

### 3. The reconciliation rules for each updated instance are symmetry-aware

For each component instance that was updated in the default translation, every non-default translation's copy of that instance is reconciled using the following rules:

- **Removed props**: input keys that existed in the previous version but not in the new version are removed from the non-default translation's inputs. This mirrors the cleanup already applied to the default translation.

- **New props**: input keys introduced by the new version are seeded with the value the default translation now holds for that key (whether the updater injected it as a required-prop default, or it comes from the component's example/default value for optional props). The translation receives the same starting value regardless of whether the prop is translatable, because the translation had no prior value to preserve.

- **Existing non-translatable props**: input keys that existed in both the old and new versions but are classified as non-translatable are updated to match the default translation's current value. This upholds the ADR #12 invariant that non-translatable inputs are identical across all translations.

- **Existing translatable props**: input keys that existed in both versions and are classified as translatable are left unchanged. The translation's own value is the correct value to preserve.

- **`component_version`**: the stored version identifier on every non-default translation's instance is updated to the new version, identical to the default translation. The version is structural metadata shared across all translations.

### 4. The translatability classification reuses the same infrastructure established in ADR #10

Whether an input key is translatable is determined by the same schema-driven mechanism established in ADR #10 — the `inputs` field property's method for enumerating translatable keys. There is no separate or parallel classification for the update propagation path.

### 5. Config entity translations are reconciled at the override level

For config entities, where non-default translations are stored as sparse `LanguageConfigOverride` records containing only translatable overrides, propagation operates directly on those records:

- Orphaned keys (props deleted in the new version) are pruned from the override.
- The version identifier is structural metadata in the base config, not in the override, so it is already correct after the default-translation update.
- New props do not appear in the override at all (their value comes from the base config), so no action is needed.
- Non-translatable props do not appear in the override either (by definition, only translatable overrides are stored), so there is nothing to reconcile for them.

If pruning leaves a component instance's override entry empty, the entry is removed entirely. If that leaves the override record itself empty, the override record is deleted. This prevents accumulation of empty override artifacts.

### 6. Reconciliation is guarded against being applied to the default translation

The reconciliation operation is defined to operate on a non-default translation's copy of a component instance. Attempting to invoke it on a component instance that belongs to the default translation is a programming error and results in an exception. This guard makes the contract explicit: the default translation is the source of truth that drives reconciliation; it is never the target.

## Consequences

In order of importance, with the following markers:
- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+TOB` **Translation integrity is maintained through component version transitions.** After an update, every translation — content entity or config entity — carries inputs shaped for the new component version, with the correct version identifier. The ADR #12 invariant (all translations store complete, synchronized inputs) is preserved through updates, not just through saves.

2. `+T` **Single call site triggers propagation.** The component source manager, which already iterates the default translation's tree and applies updates, is the only place that needs to be aware of translation propagation. All reconciliation logic is encapsulated at the data model layer. No controller, route, or other application-layer entry point needs to change.

3. `+T` **Translatability classification is shared with ADR #10's machinery.** There is no risk of the update path and the translation-save path disagreeing about which inputs are translatable, because both use the same underlying classification.

4. `+TOB` **Content and config entity translations are handled by the same conceptual rules**, even though the storage mechanisms differ. The reconciliation rules (remove orphans, seed new props, sync non-translatable props, preserve translatable props) are identical; only the storage access pattern differs.

5. `+T` **No new services are introduced.** The propagation responsibility is absorbed by the existing component tree data model types, consistent with the principle that the data model owns its invariants.

6. `-T` **Propagation requires all non-default translations to be accessible in memory at update time.** For content entities this means all translations are loaded during the update request. For a content entity with many translations, this is a memory overhead analogous to what `content_translation.synchronizer` already incurs on every presave. For config entities the overhead is minimal because only sparse override records are loaded.

7. `≃T` **The update is still applied only to the default translation's tree.** Non-default translations are reconciled, not re-updated independently. This is correct because the update logic (which inputs to add, which to remove, what default values to use) is defined with respect to the component source and the default translation's actual values — not independently per translation.
