# 13. Symmetric translation propagation on component instance update

Date: 2026-06-20

Issue: <https://www.drupal.org/project/canvas/issues/3591596>

## Status

Accepted

## Context

[ADR #6](0006-One-field-row-per-component-instance.md) established that each component instance stores a `component_version` alongside its `inputs`. When a component's implementation changes, Canvas creates a new version of the `Component` config entity and existing component instances must be migrated forward: obsolete input keys removed, newly required input keys seeded with defaults, and the stored `component_version` updated to match. This migration is performed by a component source's *instance updater* (`ComponentInstanceUpdaterInterface`).

[ADR #10](0010-dynamic-config-schema-for-component-tree-translatability.md) established that each component instance's inputs have a per-instance translatability classification: some input keys are translatable, others are not. This classification is determined dynamically from the component source's schema generator.

[ADR #12](0012-symmetric-content-translation-store-all-inputs-validate-at-write-time.md) established that every translation stores the full set of inputs for every component instance — both translatable and non-translatable key-value pairs — and that non-translatable keys are synchronized from the default translation at write time.

### The gap: version updates were not propagated to translations

Before this ADR, an instance version update was applied only to the default translation's component tree. After such an update, the default translation's instances referenced the new component version with updated inputs, but every non-default translation still referenced the old version and still held inputs shaped for that old version — stale `component_version`, orphaned input keys, missing keys for new props. This violated the invariant from ADR #12 (all translations store complete, synchronized inputs).

### Symmetric translations make this propagation trivial

The symmetric translation model — same component tree structure across all translations, only inputs may differ per language — means a component instance update is structurally a change to *all* translations at once. The instance updater is deterministic: given the same instance and the same source-version transition, it produces the same structural result regardless of which translation's tree it runs on (it removes the same orphaned keys, seeds the same required-prop defaults, prunes the same deleted-slot children, and sets the same `component_version`). It does not touch existing valid keys, so each translation keeps its own translated values.

Therefore propagation does not need a bespoke reconciliation algorithm. It only needs to run the *same updater* over *each translation's* tree.

## Decision

### 1. Propagation is "run the updater on every translation"

After updating the default translation, `ComponentSourceManager::updateComponentInstances()` applies the very same instance updaters to each non-default translation's full component tree. There are no separate reconciliation rules and no per-instance update snapshots; the updater is the single authority on which keys survive, which defaults are seeded, and which structure is pruned. Symmetry is preserved by construction.

### 2. Content entities: apply the updater to each translation's field item list

Content entity translations are loaded in memory as field item lists. For each non-default translation, the updater is applied directly to that translation's `ComponentTreeItemList`. Existing translated (translatable) values are preserved because the updater leaves valid existing keys untouched. Non-translatable values converge to the default translation through the existing write-time synchronizer (`ComponentTreeFieldSymmetricalTranslationSynchronizer`, ADR #12) — not through this propagation step.

### 3. Config entities: rebuild the full tree from base + override, then re-derive the sparse override

Config entity translations are stored as sparse `LanguageConfigOverride` records containing only translatable overrides, so the updater cannot run on them directly. For each translation:

1. The full translated tree is reconstructed by merging the base config with the translation's override, then loaded as a dangling `ComponentTreeItemList`.
2. The same updater is applied to that full tree.
3. The sparse override is re-derived: each instance keeps only its prior override values whose keys are still translatable in the new version. Orphaned keys disappear (the updater removed them from the tree), new props are not added (their value comes from the base config), and `component_version` is not stored in overrides (it lives in the base config). If an instance's override becomes empty it is dropped.

Staged config translation mutations are kept in memory on `StagedLanguageConfigOverride` entities — the same auto-save pattern as `StagedConfigUpdate` — so they participate in the review-and-publish workflow. Publishing writes a real `LanguageConfigOverride`, or deletes it if empty.

Core already prunes overrides whose keys vanished from the base config (orphaned props, shrunk cardinality) via `ConfigFactoryOverrideBase::filterOverride()`, invoked from `LanguageConfigFactoryOverride::onConfigSave()` when the base config is saved. Canvas cannot reuse it: `filterOverride()` is a `protected` method with no public entry point other than that save-time event subscriber, so it is private API and out of reach — and it is coupled to a real `Config::save()`, whereas Canvas mutates staged, in-memory overrides before publish. Canvas therefore re-derives the override itself. This is not merely a reimplementation of `filterOverride()`: the staged override must be self-consistent and valid *before* publish (for preview and validation), and the re-derivation additionally drops keys whose translatability classification changed between versions — which `filterOverride()`, being key-existence-only, cannot detect.

### 4. The config override re-derivation reuses ADR #10's translatability classification

The config override re-derivation (Decision 3) decides which keys belong in an override using the same schema-driven translatability classification established in ADR #10 — the `inputs` field property's method for enumerating translatable keys (`ComponentInputs::getTranslatableInputKeys()`). This is what drops keys whose translatability classification changed between component versions. The content path needs no such classification: non-translatable convergence is handled by the write-time synchronizer (Decision 2, ADR #12), and the updater itself is translatability-agnostic.

### 5. Propagation is in-memory only; the caller persists

The updated translations (content field item lists, config staged overrides) are mutated in memory only. The caller is responsible for persisting them — by creating auto-saves for the content or config entity with a component tree, for both its default translation and every translation it has.

## Consequences

In order of importance, with the following markers:
- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+TOB` **Translation integrity is maintained through component version transitions.** After an update, every translation — content entity or config entity — carries inputs shaped for the new component version, with the correct version identifier. The ADR #12 invariant is preserved through updates.

2. `+T` **No bespoke reconciliation logic.** There is no snapshot capture and no hand-written remove/seed/sync/preserve rule set. The instance updater — already the authority on a single-translation update — is the only mechanism. This removes a large class of "the rules disagree with the updater" bugs by construction.

3. `+T` **Single source of truth for "which keys survive".** Content and config translations both prune via the same updater run, rather than re-deriving valid keys from a snapshot. Cardinality truncation and deleted-slot child pruning are handled identically everywhere.

4. `+TOB` **Content and config translations share one conceptual model** — "run the updater on the full tree" — even though config requires reconstructing the full tree from base + override and re-deriving the sparse override afterward.

5. `+T` **No new services.** The propagation responsibility stays in `ComponentSourceManager`, with a small read helper on the config entity to expose a translation's full tree.

6. `-T` **Propagation requires all translations to be accessible in memory at update time.** For content entities all translations are loaded; for config entities only sparse override records are loaded and a full tree is rebuilt per translation. This is analogous to what `content_translation.synchronizer` already incurs.

7. `≃T` **The update is applied independently per translation rather than reconciled against the default.** This is correct precisely because the updater is deterministic and symmetric: independent application yields the same structure on every translation, and existing translated values are preserved.
