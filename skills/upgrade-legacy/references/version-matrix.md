# Version matrix & Rector set lists

## PHP requirements per Silverstripe CMS version

| CMS version | Minimum PHP | Notes |
|---|---|---|
| 4.10 – 4.13 | 7.4 | 4.11+ supports PHP 8.1; 4.13 is the final 4.x minor (EOL) |
| 5.0 | 8.1 | |
| 5.2 – 5.4 | 8.1 | PHP 8.2/8.3 supported; 5.4 is the final 5.x minor |
| 6.0+ | **8.3** | Hard minimum — verify CLI *and* web PHP before hopping |

Always verify the exact current support matrix on
https://docs.silverstripe.org/en/project_governance/major_release_policy/ and the
release changelogs (https://docs.silverstripe.org/en/6/changelogs/) — new minors may
have shifted requirements since this skill was written.

## Composer constraints per target

| Target | Typical core constraint |
|---|---|
| Latest SS4 | `silverstripe/recipe-cms:^4.13` |
| Latest SS5 | `silverstripe/recipe-cms:^5.4` (or `^5`) |
| SS6 | `silverstripe/recipe-cms:^6` |

Notes:
- `silverstripe/recipe-plugin` and `silverstripe/vendor-plugin` normally follow automatically.
- SS4 → SS5: `silverstripe/graphql` jumps to v4/v5 (schema build model changed); modules like `cwp/*` recipes may pin cores — check them first when composer conflicts appear.
- **SS6: `silverstripe/htmleditor-tinymce` must be required explicitly** — TinyMCE was extracted from core and `recipe-cms` does NOT pull it in. Without it every HTMLEditorField (CMS rich-text editor) is broken. Typical SS6 module constraints: `silverstripe/userforms:^7`, `dnadesign/silverstripe-elemental:^6`, `silverstripe/htmleditor-tinymce:^1`.
- SS6 merges several reports modules into `silverstripe/reports`.
- Installing rector may hit composer's plugin gate: `composer config allow-plugins.phpstan/extension-installer true` then `composer install`.

## silverstripe-rector set lists

Install: `composer require wernerkrauss/silverstripe-rector --dev` (v1.x needs PHPStan 2 / Rector 2; use v0.x if stuck on PHPStan 1).

### `SilverstripeLevelSetList` (cumulative — "everything up to X")

```
UP_TO_SS_4_1  UP_TO_SS_4_2  UP_TO_SS_4_3  UP_TO_SS_4_4  UP_TO_SS_4_5
UP_TO_SS_4_6  UP_TO_SS_4_7  UP_TO_SS_4_8  UP_TO_SS_4_9  UP_TO_SS_4_10
UP_TO_SS_4_11 UP_TO_SS_4_12 UP_TO_SS_4_13
UP_TO_SS_5_0  UP_TO_SS_5_1  UP_TO_SS_5_2  UP_TO_SS_5_3  UP_TO_SS_5_4
UP_TO_SS_6_0  UP_TO_SS_6_1  UP_TO_SS_6_2
```

Namespace: `Netwerkstatt\SilverstripeRector\Set\SilverstripeLevelSetList`.
Each level includes all lower levels (e.g. `UP_TO_SS_6_0` = `UP_TO_SS_5_4` + the 6.0 set), so a
single `UP_TO_SS_6_2` covers every rule from 4.0 through 6.2 — but for reviewability run
per-major (current-major level first, then target level after the composer hop).

### `SilverstripeSetList` (single-version / style sets)

```
CODE_STYLE
SS_4_0 … SS_4_13
SS_5_0 … SS_5_4
SS_6_0  SS_6_1  SS_6_2
```

Namespace: `Netwerkstatt\SilverstripeRector\Set\SilverstripeSetList`.

`CODE_STYLE` includes, among others: `new Foo()` → `Foo::create()` for Injectable classes,
`@config` annotations on private statics, `DataObject::get_by_id('Class', $id)` →
`Class::get()->byID($id)`.

Notable version-set contents:
- `SS_4_0`: `EnsureTableNameIsSetRector` (adds `$table_name`), namespacing helpers.
- `SS_6_0`: mass class renames (see ss5-to-ss6.md), `BuildTaskUpdateRector`, removal of `Deprecation` comments, static method renames (`SSViewer::flush` → `SSTemplateEngine::flush`, `DBEnum::flushCache` → `reset`), property rename `LeftAndMain::$tree_class` → `$model_class`.
- `SS_6_1`: `DataObjectStaticMethodsToFluentRector` — `DataObject::get_by_id/get_one/delete_by_id($class, …)` → fluent `DataObject::get($class)->…` equivalents.
- `SS_6_2`: `FieldList::dataFields()` → `getDataFields()`, `GetIDListToColumnIDRector`.

The authoritative rule list ships with the package:
`vendor/wernerkrauss/silverstripe-rector/docs/all_rectors_overview.md` — read it after
installing if in doubt what a set will do.

## Related tooling

- `cambis/silverstan` — PHPStan extension with Silverstripe magic (config properties, Injectable).
- `cambis/silverstripe-rector` — alternative/complementary rector rules package.
- Silverstripe 3 → 4 only: `silverstripe/upgrader` (archived official tool) + manual namespacing; not covered by Rector sets.
