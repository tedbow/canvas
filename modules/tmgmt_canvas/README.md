# Canvas TMGMT

Provides a translation dashboard for Canvas content, powered by [TMGMT](https://www.drupal.org/project/tmgmt). Abstracts TMGMT's job/job-item concepts so content creators can translate pages with one click — no knowledge of the underlying translation management system required.

## Requirements

- [Drupal Canvas](https://www.drupal.org/project/canvas) (`canvas`)
- [TMGMT](https://www.drupal.org/project/tmgmt) (`tmgmt`)
- TMGMT Content (`tmgmt_content`) — for content entity translation
- TMGMT Local (`tmgmt_local`) — recommended translator plugin
- Content Translation (`content_translation`) — core module

At least one TMGMT translator must be configured at `/admin/tmgmt/translators` before the workflow can be used.

## Installation

Enable with Drush:

```
drush en tmgmt_canvas
```

Or via the Drupal UI at `/admin/modules`.

## Usage

### Translation Dashboard

Visit `/admin/canvas/translations` (also linked under **Admin > Content > Canvas Translations**).

Filter by:
- **Entity type** — Node, Media, Taxonomy Term, Canvas Page (content entities); Canvas Content Template, Canvas Page Region (config entities when `canvas_dev_translation` is enabled)
- **Bundle** — shown when the selected entity type has multiple translatable bundles
- **Language** — non-default configurable languages
- **Title** — partial string match
- **Status** — Not translated / Translated / Outdated

Click **Translate** or **Edit** to open the TMGMT translation review form. After saving, the user is returned to the dashboard.

### Translate from Canvas Editor

The Canvas editor language selector includes an **Edit translation** link that opens `/admin/canvas/translate/{type}/{id}/{lang}?origin=canvas`. After saving, the user is returned to the Canvas editor instead of the dashboard.

## Architecture

See `architecture-summary.md` for a detailed description of the routing logic, TMGMT integration, and entity type mapping.
