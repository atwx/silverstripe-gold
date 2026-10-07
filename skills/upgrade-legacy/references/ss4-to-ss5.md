# Silverstripe 4 → 5: manual checklist

Prerequisite: project is on 4.13 with deprecations addressed (`UP_TO_SS_4_13` Rector run done).
Full changelog: https://docs.silverstripe.org/en/5/changelogs/5.0.0/

## Environment & project structure

- **PHP ≥ 8.1** required.
- **`public/` webroot is mandatory.** If the project still serves from the root, migrate per the 4.1 changelog instructions *before* the hop.
- Default exposed-resources dir changed `resources/` → **`_resources/`**. If `composer.json` has `extra.resources-dir: _resources` it can be removed; if you must keep `resources/`, set the key explicitly. Grep templates for hardcoded resource paths — prefer `$resourcePath()` / `$resourceURL()`.
- Legacy file resolution strategy (from 4.4) removed — finish file migration and use default `assets.yml` strategy.
- `isDev` / `isTest` query-string switches removed (security).

## Email (biggest functional break)

- SwiftMailer → **symfony/mailer**. Any custom SMTP/transport YAML config must be rewritten as a **DSN string** (e.g. `MAILER_DSN='smtp://user:pass@host:587'`). Read the SS5 email docs.
- `Email` now extends `Symfony\Component\Mime\Email` (was `ViewableData`).
- Return types changed: `getFrom()/getTo()/getCc()/getBcc()` return `Address[]`; `getCC()`/`getBCC()` renamed to `getCc()`/`getBcc()`; `send()`/`sendPlain()` return `void` — catch `TransportExceptionInterface` to detect failures.
- `MailTransport` (PHP `mail()`) removed.

## ORM / database

- `DataList::sort()` **no longer accepts raw SQL** — use `orderBy()` for raw SQL (prefer structured `sort()`); `sort(null)` clears sort, `sort('')`/`sort([])` now throw.
- `Limitable::limit(0)` now means "0 rows" (was: unset). Use `limit(null)` to unset; same for `SQLSelect::setLimit()`.
- **PDO connectors removed** — change `SS_DATABASE_CLASS` from e.g. `MySQLPDODatabase`/`MySQLPDOConnector` to `MySQLDatabase`.
- `Query` implements `IteratorAggregate` — `seek()`, `first()`, `nextRecord()` etc. gone; use `getIterator()` / `foreach`.
- Lists return generators from `getIterator()`; `getGenerator()` removed.
- `SilverStripe\Dev\CSVParser` removed — use `League\Csv\Reader`.

## Controllers & CMS

- `SiteTree` no longer auto-detects `<PageClass>_Controller` — controllers must be named `<PageClass>Controller` or declared via `SiteTree.controller_name` config.
- `SecurityAdmin` is now a `ModelAdmin`; Users/Groups/Roles have their own admin paths.
- `updateRelativeLink()` hook signature changed.
- `SilverStripeNavigator*` classes moved to `SilverStripe\Admin\Navigator` / `SilverStripe\VersionedAdmin\Navigator` (Rector handles PHP; grep YAML/templates).

## GraphQL

- `silverstripe/graphql` v3 → v4: schemas are now **statically built** (`dev/graphql/build`), config format changed completely. If the project defines custom schemas/resolvers, budget dedicated time; asset-admin & CMS schemas rebuild automatically.

## PHP 8 fallout

- Dynamic properties deprecated (PHP 8.2): `ViewableData` shims them via `__get/__set`, but subclasses overriding `__get/__set/getField/setField/hasField` without calling parent may break — see `getDynamicData()/setDynamicData()`.
- Run the PHP sets in Rector (`->withPhpSets()`) to convert legacy constructs.

## After the hop

- `dev/build flush=1`, fix errors, run `UP_TO_SS_5_4` Rector level, test email sending, test CMS login/asset upload, run test suite.
