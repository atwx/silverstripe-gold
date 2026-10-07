---
name: upgrade-legacy
description: Upgrade legacy Silverstripe CMS projects and modules across several major versions in one chain — 3.x → 4.x with the official silverstripe/upgrader tool (namespacing, .env, recipes, public/ webroot, file migration), then 4.x → 5.x → 6.x with Rector — plus per-hop checklists for everything the tools cannot automate. Use whenever the project is on Silverstripe 3 or older, when the user mentions "SS3", silverstripe/upgrader, upgrade-code, namespacing an old Silverstripe project, mysite/code, _ss_environment.php or Object::create, or when a module has to be made compatible across several majors. For a straightforward Silverstripe 4/5 → 6 upgrade of an already-namespaced project, use the `silverstripe-gold:upgrade` skill instead.
---

# Silverstripe CMS Upgrade — legacy / multi-major (SS3 → 4 → 5 → 6)

Upgrade a Silverstripe project or module one major version at a time, using
[`wernerkrauss/silverstripe-rector`](https://packagist.org/packages/wernerkrauss/silverstripe-rector)
to automate PHP code changes, plus a checklist for everything Rector cannot do
(composer.json, YAML config, `.ss` templates, environment, database).

Based on the workflow from
[Update from Silverstripe 5 to Silverstripe 6 (s2-hub.com)](https://www.s2-hub.com/articles/update-from-silverstripe-5-to-silverstripe-6),
generalised for multi-major upgrades.

> **Scope.** This skill covers the long chain, starting at Silverstripe 3. If the
> project is already on Silverstripe 4 or 5 and namespaced, the shorter
> `silverstripe-gold:upgrade` skill is the better fit.

## Core principles (never skip these)

1. **One major version hop at a time.** 4.x → 5.x → 6.x. Never jump two majors in one composer update.
2. **Latest minor first.** Before hopping majors, update to the latest minor of the *current* major (4.13, 5.4, …) so all deprecation notices are available.
3. **One change at a time, commit after each.** Run one Rector set, review, commit, then the next. Never run everything at once on an old codebase — the diff becomes unreviewable.
4. **Commit policy: ask before committing.** The steps here define *logical checkpoints* (after PHP set, after SS set, after each manual fix). Whether each checkpoint becomes its own commit or several get squashed is the user's call — confirm before each `git commit`, never commit autonomously just because the steps are described as separate.
5. **Always `--dry-run` first.** Show/inspect the diff before letting Rector write.
6. **Rector only touches PHP.** YAML config, `.ss` templates, composer.json, `_config.php` class-name strings and JS must be handled separately (see Step 6).
7. **Working tree must be clean** before every Rector run so it can be rolled back with git.

## Workflow

### Step 0 — Assess the project

- Read `composer.json`: determine current Silverstripe version (`silverstripe/framework`, `silverstripe/recipe-cms` or `silverstripe/cms` constraint), PHP constraint, and list all third-party modules.
- Check the installed PHP version (`php -v`) and confirm the target version's PHP requirement is met — see `references/version-matrix.md` for the PHP-per-CMS-version table.
- Confirm the project is under git with a clean working tree; create an upgrade branch (e.g. `upgrade/ss6`).
- Plan the hop chain, e.g. from 4.11: `4.11 → 4.13 → 5.4 → 6.x`; from SS3: `3.x → 4.13 → 5.4 → 6.x`.
- **Project on Silverstripe 3?** The 3 → 4 hop uses the official `silverstripe/upgrader` tool instead of Rector (namespacing, `.env` migration, recipes, `public/` webroot, file migration). Follow `references/ss3-to-ss4.md` first, land on 4.13, then rejoin this workflow at Step 2.
- For any hop, treat the official docs as the authoritative source and consult the changelog of the exact target version: https://docs.silverstripe.org/en/<major>/changelogs/ (markdown sources per major in branches `4.13` / `5` / `6` of https://github.com/silverstripe/developer-docs, under `en/04_Changelogs/` and `en/03_Upgrading/`). If web access is available during an upgrade, fetch the target changelog rather than relying on this skill's summaries alone.

### Step 1 — Update to latest minor of the current major

```bash
composer update
# or raise constraints to the latest minor, e.g. silverstripe/recipe-cms:^4.13, then
composer update -W
vendor/bin/sake dev/build flush=1   # SS4/SS5; in SS6: vendor/bin/sake db:build --flush
```

Fix anything broken, run the test suite, commit.

### Step 2 — Install Rector tooling

```bash
composer require phpstan/extension-installer --dev
composer require cambis/silverstan --dev
composer require wernerkrauss/silverstripe-rector --dev
```

Create `rector.php` in the project root from the template in
`references/rector-setup.md`, and a minimal `phpstan.neon` (level 1, paths: `app/src`).
Commit.

### Step 3 — Clean up on the current major

Run Rector in small increments, committing between each:

1. PHP-version sets only (`->withPhpSets()`, Silverstripe sets commented out) — modernise to the currently used PHP version.
2. `SilverstripeSetList::CODE_STYLE` (e.g. `new Foo()` → `Foo::create()`, `@config` annotations).
3. `SilverstripeLevelSetList::UP_TO_SS_4_13` / `UP_TO_SS_5_4` (highest level of the *current* major) — fixes deprecations before the jump.

Each increment:

```bash
vendor/bin/rector --dry-run   # review
vendor/bin/rector             # apply
# run tests / dev/build, then commit
```

### Step 4 — Audit and update modules

For every non-core module in `composer.json`:

- Check on Packagist whether a release compatible with the target major exists.
- If not, check the module's GitHub "Insights → Network" for forks that already did the upgrade.
- Compatible → raise constraint. Not compatible and no fork → either remove temporarily or upgrade it yourself (see `references/module-upgrade.md`).

Record the audit as a table (module / current / target-compatible? / action) for the user.

### Step 5 — Hop the major version

1. Update composer constraints to the new major (recipe/framework/cms and all modules), plus the required PHP constraint. See `references/version-matrix.md`.
2. `composer update -W`. Resolve conflicts (usually a module still pinning the old major).
3. Enable the target level set in `rector.php` (`SilverstripeLevelSetList::UP_TO_SS_5_4` when landing on 5, `UP_TO_SS_6_2` when landing on 6 — or the exact minor you target), run `--dry-run`, apply, commit.
4. Run `dev/build` (`db:build` on SS6) and fix remaining errors — the per-hop checklists list what Rector does **not** fix:
   - 4 → 5: `references/ss4-to-ss5.md` (mailer, GraphQL 4, `public/` webroot, `DataList::sort()` raw SQL, iterator changes …)
   - 5 → 6: `references/ss5-to-ss6.md` (class-rename table for non-PHP files, extension hook renames, `BuildTask` → Symfony console rewrite, validator moves …)

### Step 6 — Fix what Rector can't see (non-PHP)

Grep the whole project (YAML under `app/_config`, `.ss` templates, `_config.php` strings, JS, docs) for old class names and update them. For 5 → 6 use the rename table in `references/ss5-to-ss6.md`, e.g.:

```bash
grep -rn "SilverStripe\\\\ORM\\\\ArrayList\|SilverStripe\\\\View\\\\ArrayData\|SilverStripe\\\\ORM\\\\DataExtension" app/_config app/templates themes
```

Also check: `.env` values, `composer.json` `extra` keys (`resources-dir`, `project-files`), CI pipelines (PHP version!), Dockerfiles/ddev config, and deployment scripts.

### Step 7 — Verify and raise quality

- `dev/build` / `db:build` succeeds without errors.
- Run the test suite; click through critical CMS + frontend flows.
- Run PHPStan (`vendor/bin/phpstan`); optionally raise Rector's `withTypeCoverageLevel` / `withDeadCodeLevel` / `withCodeQualityLevel` step by step in later commits.
- If another major hop remains (e.g. arrived at 5, target is 6), go back to Step 3.

### Step 8 — Contribute back

If modules were upgraded (Step 4 / `references/module-upgrade.md`), open pull requests on the original repositories so the whole ecosystem benefits.

## Reference files

| File | Read when |
|---|---|
| `references/version-matrix.md` | Planning the hop chain: PHP requirements, composer constraints, all available `SilverstripeLevelSetList` / `SilverstripeSetList` constants (4.0 – 6.2) |
| `references/rector-setup.md` | Writing/editing `rector.php`; templates and run commands |
| `references/ss3-to-ss4.md` | Project is on Silverstripe 3: full `silverstripe/upgrader` workflow (recompose → environment → add-namespace → upgrade → inspect → entry point → reorganise → webroot → asset paths → ClassName remapping → MigrateFileTask) |
| `references/ss4-to-ss5.md` | During a 4 → 5 hop: manual breaking-change checklist |
| `references/ss5-to-ss6.md` | During a 5 → 6 hop: manual checklist + full class rename table + extension hook renames + BuildTask rewrite recipe |
| `references/module-upgrade.md` | A required module has no target-compatible release: fork → path repo → Rector → VCS fork → PR |

## Reporting to the user

After each major step, summarise: what Rector changed (rule counts / notable diffs), what was changed manually, what still needs human review (visual QA, content, third-party integrations), and the commit hashes. Never claim the upgrade is "done" without a passing `dev/build` and test run.
