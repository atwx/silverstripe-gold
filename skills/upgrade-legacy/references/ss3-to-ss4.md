# Silverstripe 3 → 4: upgrader-tool workflow

Rector's Silverstripe sets start at 4.0 — the 3 → 4 hop uses the **official
`silverstripe/upgrader` tool** instead. Primary source (follow it during the upgrade,
this file is the operating summary):
https://docs.silverstripe.org/en/4/upgrading/upgrading_project/
(markdown source: https://github.com/silverstripe/developer-docs branch `4.13`,
file `en/03_Upgrading/04_Upgrading_project.md`).

## What changes fundamentally in SS4

- All core classes are **namespaced** (`DataObject` → `SilverStripe\ORM\DataObject`); string references (has_one/has_many values, Injector keys, ModelAdmin models, YAML, lang files, `_t()` keys, templates `<%t %>`) must use FQCNs — prefer `Foo::class`.
- Modules install into `vendor/`; **recipes** (`silverstripe/recipe-cms`, `recipe-core`) replace individual core requirements.
- `_ss_environment.php` → **`.env`** file; `$_FILE_TO_URL_MAPPING` → `SS_BASE_URL`.
- Entry point `framework/main.php` → `index.php`; optional (mandatory in SS5) `public/` webroot and `mysite/code` → `app/src` structure.
- New asset system: files are versioned/protected; requires `MigrateFileTask`.
- `Object` class removed (traits: `Injectable`, `Configurable`, `Extensible`); `$table_name` needed for sane table names once namespaced.
- PHP: SS 4.0–4.4 allow PHP 5.6; 4.5+ requires 7.1; target **4.13 on PHP 7.4** as the landing point before the 5.x hop.

## Tooling: silverstripe/upgrader

```bash
composer global require silverstripe/upgrader
# or the phar: wget https://silverstripe.github.io/silverstripe-upgrader/upgrade-code.phar
```

Caveats: the tool is legacy/archived — run it with an old PHP (7.x) if it errors on modern PHP; most commands accept `--write` (apply; omit for dry-run) and `--root-dir`. An `all` command chains every step (`upgrade-code all --namespace="App\\Web" --psr4`) but rarely succeeds first try — prefer the step-by-step below, committing after each step on a dedicated branch.

## Step-by-step (each step = review + commit)

**0. Prerequisites** — dev copy only (never live!), DB + codebase backups, git branch, composer installed.

**1. `recompose` — dependencies**
`upgrade-code recompose --write` upgrades PHP constraint, swaps core modules for `silverstripe/recipe-cms:^4.13` (use `--recipe-core-constraint` to pin), finds SS4 versions of third-party modules. Modules without an SS4 release: fork & upgrade (see `module-upgrade.md` — for 3→4 also add a `.upgrade.yml` mapping file), inline into the project (discouraged), or remove. `composer update` must finish cleanly. Recipes implicitly require framework/cms/admin/asset-admin/campaign-admin/errorpage/reports/graphql/siteconfig/versioned — drop those explicit requirements.

**2. `environment` — env config**
`upgrade-code environment --write` converts `_ss_environment.php` → `.env` (KEY=VALUE). Manually: replace `define()` pairs; `$_FILE_TO_URL_MAPPING[...]` → `SS_BASE_URL="https://example.com/"`. Clean `mysite/_config.php`: remove `conf/ConfigureFromEnv.php` require and `$database`/`$databaseConfig` globals; use `Environment::getEnv()`. Add `.env` to `.gitignore`, keep an `.env.sample`.

**3. `add-namespace` — namespace project code (optional, recommended)**
`upgrade-code add-namespace "App\\Web" ./mysite/code --recursive --psr4 --write`
- `Page` and `PageController` MUST stay in the global namespace.
- PSR-4 requires UpperCamelCase subfolders; add the `autoload.psr-4` mapping to composer.json.
- Writes `mysite/.upgrade.yml` with old→new class mappings (used by step 4).
- Namespaced classes change template lookup paths (`templates/App/Web/Layout/…`) and default table names — add `private static $table_name = 'ShortName';` to every DataObject.

**4. `upgrade` — rewrite references to namespaced classes**
`upgrade-code upgrade ./mysite/ --write` — updates PHP, YAML config and lang files using every module's `.upgrade.yml`. Use `/** @skipUpgrade */` to protect strings that must not be rewritten; `--prompt` for ambiguous renames (e.g. `Image`, `File`); `--rule=code|config|lang` to scope. Review the diff carefully — this touches everything.

**5. `inspect` — deprecated API usage**
`upgrade-code inspect ./mysite/ --write` — flags/rewrites SS3 APIs to SS4 equivalents (e.g. `manyManyComponent()` → `getSchema()->manyManyComponent()`, `SS_Cache` → symfony/cache). Requires step 4 done first (files must be loadable). Remaining warnings = manual work; consult the 4.0.0 changelog.

**6. Entry point**
Copy `vendor/silverstripe/recipe-core/public/index.php` (and generic `.htaccess`/`web.config`) into the webroot, reconciling any custom `main.php`/htaccess logic. Point the vhost at it.

**7. `reorganise` — new structure (optional here, mandatory before SS5)**
`upgrade-code reorganise --write`: `mysite` → `app`, `mysite/code` → `app/src`. Fix composer.json psr-4 paths and set `SilverStripe\Core\Manifest\ModuleManifest: project: app` in YAML.

**8. `webroot` — public/ web root (optional here, mandatory before SS5)**
`upgrade-code webroot --write`, or manually move `index.php`, `.htaccess`/`web.config`, `assets/`, favicons, `robots.txt` into `public/`; delete root `resources`/`_resources`; run `composer vendor-expose`; repoint server config and `.gitignore`.

**9. Static asset references**
Expose project asset dirs via `extra.expose` in composer.json + `composer vendor-expose`. Replace hardcoded paths: PHP `Requirements::css('app/css/styles.css')`, module syntax `'silverstripe/blog: js/main.bundle.js'`, templates `$resourceURL(app/images/x.png)` / `<% require css("app: css/styles.css") %>`.

**10. Database ClassName remapping**
Namespaced DataObjects leave stale `ClassName` values ("obsolete" pages). Copy the mappings from `.upgrade.yml` (DataObject subclasses only) into `app/_config/legacy.yml`:

```yaml
SilverStripe\ORM\DatabaseAdmin:
  classname_value_remapping:
    HomePage: App\Web\HomePage
```

Applied automatically on the next dev/build.

**11. First run & data migration**

```bash
vendor/bin/sake dev/build flush=1
vendor/bin/sake dev/tasks/MigrateFileTask      # new asset system — required
vendor/bin/sake dev/tasks/TagsToShortcodeTask  # rewrite <img>/<a> refs in HTML fields
```

Check third-party modules' release notes for their own migration tasks. Then verify CMS login, page tree, asset admin, forms, and the frontend.

## Handover to the Rector pipeline

Once stable on 4.x: update to **4.13**, then continue with the standard skill workflow
(Step 2 onwards in SKILL.md) — install silverstripe-rector, run `UP_TO_SS_4_13`, and hop
to 5. Complete steps 7 (app structure) and 8 (public webroot) *before* the SS5 hop — they
are mandatory in SS5.
