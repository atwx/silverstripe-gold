---
name: upgrade
description: Upgrade a Silverstripe CMS project from version 4 or 5 to version 6 using Rector (wernerkrauss/silverstripe-rector). Covers PHP version bump, composer module updates, rector.php config, incremental migration, forking incompatible modules, and post-upgrade verification. Use when the user wants to upgrade a Silverstripe 4/5 project to Silverstripe 6.
---

# Silverstripe 4/5 → 6 Upgrade

Automatisierter Upgrade-Pfad via [wernerkrauss/silverstripe-rector](https://github.com/wernerkrauss/silverstripe-rector). Rector kann direkt von SS 4 auf SS 6 migrieren — der 4 → 5 Zwischenschritt ist in der Regel harmlos und wird mit erledigt.

Grundlage: https://www.s2-hub.com/articles/update-from-silverstripe-5-to-silverstripe-6/

## Voraussetzungen

- **PHP ≥ 8.3** (SS 6 Pflicht)
- Projekt unter Git, sauberer Arbeitsbaum
- Tests grün vor Start (falls vorhanden)
- Deprecation-Warnings aus dem IDE idealerweise schon auf der letzten SS 4/5 behoben

## Schritte

### 1. Letzte SS-4-/5-Minor einspielen

Vor dem Major-Sprung auf die letzte Minor-Version der aktuellen Major updaten und Deprecation-Warnings abarbeiten:

```bash
composer update
ddev exec php vendor/silverstripe/framework/cli-script.php dev/build flush=1
```

### 2. PHP-Version in DDEV anheben

In `.ddev/config.yaml`:

```yaml
php_version: "8.3"
```

Dann:

```bash
ddev restart
```

Falls Composer bei lokalen Tools meckert: `--ignore-platform-req=ext-*` nutzen oder erst nach dem composer-Update ausführen.

### 3. Rector + Silverstan installieren

```bash
composer require phpstan/extension-installer --dev
composer require cambis/silverstan --dev
composer require wernerkrauss/silverstripe-rector:^1 --dev
vendor/bin/rector init
```

Composer blockt den phpstan-Plugin per default. Einmalig freischalten:

```bash
composer config allow-plugins.phpstan/extension-installer true
composer install
```

### 4. `rector.php` konfigurieren

Projekt-Root:

```php
<?php
declare(strict_types=1);

use Netwerkstatt\SilverstripeRector\Set\SilverstripeLevelSetList;
use Netwerkstatt\SilverstripeRector\Set\SilverstripeSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app/src',
        __DIR__ . '/app/_config.php',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        instanceOf: true,
        earlyReturn: true,
        rectorPreset: true
    )
    ->withPhpSets()
    ->withSets([
        SilverstripeSetList::CODE_STYLE,
        SilverstripeLevelSetList::UP_TO_SS_6_0,
    ]);
```

Optional `phpstan.neon` für Silverstan:

```neon
parameters:
  level: 1
  paths:
    - app/src
```

### 5. Rector inkrementell ausführen

**Nicht alles auf einmal.** Stufenweise:

1. Erst nur PHP-Sets aktivieren (Silverstripe-Set temporär auskommentieren), `--dry-run` prüfen, anwenden, committen.
2. Dann `SilverstripeLevelSetList::UP_TO_SS_6_0` dazu, wieder dry-run → apply → commit.

```bash
vendor/bin/rector --dry-run
vendor/bin/rector            # anwenden
git add -A && git commit -m "Rector: PHP upgrade"
```

Zwischen Iterationen committen, damit problematische Diffs zurückrollbar bleiben.

**Commit-Policy:** Vor jedem `git commit` beim User rückfragen. Dieser Skill beschreibt *logische* Checkpoints (nach PHP-Set, nach SS-Set, nach jeder manuellen Korrektur) — ob daraus ein eigener Commit wird oder mehrere zu einem zusammengefasst werden, entscheidet der User. Nicht selbstständig committen, nur weil die Schritte als separat beschrieben sind.

### 6. Composer-Dependencies anheben

In `composer.json` Core + Module auf SS-6-kompatible Constraints heben:

```json
"require": {
    "php": "^8.3",
    "silverstripe/recipe-cms": "^6",
    "silverstripe/userforms": "^7",
    "dnadesign/silverstripe-elemental": "^6",
    "silverstripe/htmleditor-tinymce": "^1"
    // ...
}
```

**Wichtig:** Die TinyMCE-Integration ist in SS 6 aus dem Core ausgelagert und muss **explizit** als `silverstripe/htmleditor-tinymce` requiret werden. Ohne sie sind alle HTMLEditorFields (CMS-Richtext-Editor) kaputt. `silverstripe/recipe-cms` zieht sie nicht automatisch mit.

Jedes Modul auf [packagist.org](https://packagist.org) auf SS-6-Support prüfen. Inkompatible Module:
- **Temporär entfernen**, wenn verzichtbar
- **Fork suchen** auf GitHub (oft gibt's schon Community-Forks mit SS-6-Support)
- **Selbst forken & patchen** (siehe Schritt 8)

Dann:

```bash
composer update
```

### 7. Build-Tasks manuell refactoren

`BuildTask` ist in SS 6 auf **Symfony Console** umgestellt. Rector erkennt `extends BuildTask` und setzt teilweise neue Typen, aber die komplette Portierung muss von Hand passieren. Betroffene Dateien: `grep -rln "extends BuildTask" app/src`.

**Was sich ändert:**

| SS 4/5 | SS 6 |
|---|---|
| `public function run($request)` | `protected function execute(InputInterface $input, PolyOutput $output): int` |
| `protected $title = "..."` | `protected string $title = "..."` *(non-static!)* |
| `protected $description = "..."` | `protected static string $description = "..."` *(static)* |
| *(nicht vorhanden)* | `private static string $segment = 'TaskName';` *(Pflicht für CLI-Aufruf)* |
| `print "..."` / `echo "..."` | `$output->writeln("...")` |
| kein `return` | `return 0;` (oder `Command::SUCCESS`) |

**Imports ergänzen:**

```php
use Symfony\Component\Console\Input\InputInterface;
use SilverStripe\PolyExecution\PolyOutput;
```

**Achtung:** Das Parent `BuildTask` hat `$title` **non-static** (`protected string $title`), `$description` dagegen **static**. Wer beides static deklariert, bekommt einen Fatal Error wegen `Cannot redeclare non static as static`.

Rector lässt manchmal die alte Signatur `run($request, PolyOutput $output)` stehen. Das erzeugt "contains abstract method BuildTask::execute" — die Methode heißt jetzt `execute`, nicht `run`.

**ideannotator-Altlasten:** Wenn bereits `silverleague/ideannotator` lief, können veraltete DocBlocks wie

```
@@property \Foo dataRecord
@mixin \Foo dataRecord
```

vorhanden sein. Die werfen `InvalidArgumentException: The tag "@@property ..." does not seem to be wellformed` bei `sake db:build`. Fix per sed:

```bash
sed -i -E '/@@property .* dataRecord$/d' app/src/**/*Controller.php
sed -i -E 's| \* @mixin (\\[A-Za-z\\]+) dataRecord$| * @mixin \1|' app/src/**/*Controller.php
```

### 8. Inkompatible Module selbst upgraden

Pro problematischem Modul:

1. Auf GitHub **forken**, lokal in Schwesterordner clonen.
2. Upgrade-Branch anlegen (`upgrade-ss6`).
3. Im Hauptprojekt als Path-Repo einbinden:

   ```json
   "repositories": [
       {"type": "path", "url": "../modul-name"}
   ],
   "require": {
       "vendor/modul-name": "*"
   }
   ```

4. Modul-Pfad in `rector.php` zu `withPaths()` hinzufügen, Rector drüberlaufen lassen, iterieren bis `dev/build` grün.
5. Fork pushen, Path-Repo durch Git-Repo ersetzen:

   ```json
   "repositories": [
       {"type": "vcs", "url": "https://github.com/<you>/modul-name"}
   ],
   "require": {
       "vendor/modul-name": "dev-upgrade-ss6 as 6.0"
   }
   ```

6. PR beim Original-Maintainer einreichen.

### 9. Verifizieren

`cli-script.php` gibt es in SS 6 nicht mehr — alles läuft über **sake** (Symfony Console):

```bash
ddev exec vendor/bin/sake db:build --flush
ddev exec vendor/bin/sake <TaskSegment>      # z.B. vendor/bin/sake UpdateOverallValueTask
vendor/bin/phpcs                              # Linting
vendor/bin/phpcbf                             # Auto-Fix für phpcs-Befunde
vendor/bin/phpstan analyse                    # Silverstan (Level 1 als Einstieg)
vendor/bin/phpunit                            # Tests
```

Admin-UI im Browser durchklicken: Login, CMS-Tree, Elemental-Editor, GridFields, FileUploads.

**`public/_resources` (mit Unterstrich)** ist der neue Expose-Pfad in SS 6. Ältere Projekte haben `/public/resources` in `.gitignore` — ergänzen:

```gitignore
/public/resources
/public/_resources
```

Falls bereits Dateien versehentlich committed sind: `git rm -r --cached public/_resources`.

## Besonderheiten & typische Stolpersteine

### SS 4 → 5
- PHP 7.4 → 8.1+ (Property-Typen, Named Arguments, Enums erlaubt)
- CMS-Theme-Overrides über `SilverStripe\View\SSViewer.themes` ggf. anpassen
- TinyMCE-Konfig über `HTMLEditorConfig` — API weitgehend stabil, aber einige Shortcut-Keys entfernt

### SS 5 → 6
- Viele **Class-Renames** (Rector erledigt das Gros automatisch)
- `switch`-Statements werden teils zu `match` umgeschrieben — nachprüfen, ob Semantik stimmt
- String-Funktionen (`strpos` → `str_contains` etc.) via PHP-Set
- **`DataList::sort()` lehnt Raw-SQL ab.** Muster wie `->sort("RAND()")` schlagen fehl mit `InvalidArgumentException: Invalid sort column RAND()`. Ersatz: `->orderBy("RAND()")` — `orderBy()` akzeptiert weiterhin Raw-SQL.
- `cli-script.php` ist entfernt — stattdessen `vendor/bin/sake <command>`.
- `dev/build?flush=1` per HTTP funktioniert noch; CLI-Äquivalent ist `sake db:build --flush`.
- **Rector-Regression: zu enger `string`-Typehint.** Wenn eine Methode `foo(string $x = "")` als Default-Signatur hatte, setzt Rector unter PHP-8-Strict-Types gern `string $x` (ohne `?`). Caller, die den Wert aus `$request->getVar(...)`, `$_GET[...]` oder einem `has_one`-Accessor holen, reichen aber `null` durch — das sprengt mit `Argument #N ($x) must be of type string, null given`. Findet sich erst zur Laufzeit, weil der Pfad nur unter bestimmten URL-Parametern getriggert wird. Zwei Fixes:
  - **Caller coalesce-n**: `$x = $request->getVar('foo') ?? "";`
  - **Typehint aufweichen**: `function foo(?string $x = null)`
  Nach dem Rector-Durchlauf per `grep -rn 'getVar([^)]*)' app/src/` die Stellen durchgehen, wo das Ergebnis direkt in typisierte Methoden fließt.
- **Template-Iteratoren umbenannt.** `$First` → `$IsFirst`, `$Last` → `$IsLast`. `$Pos`, `$FirstLast`, `$Middle`, `$Even`, `$Odd` etc. bleiben. Rector fasst Templates nicht an — selbst ausbessern:
  ```bash
  grep -rln '\$First\|\$Last' app/templates/
  sed -i -E 's/\\$First\\b/\\$IsFirst/g; s/\\$Last\\b/\\$IsLast/g' app/templates/**/*.ss
  ```
  Symptom ohne Fix: `<% if $First %>`-Blöcke feuern *nie*, weil `$First` im Loop-Scope als undefiniert gilt (fällt ggf. auf `DataList::First()` zurück, was nicht was du willst).

### Elemental
- Elemental 4 (SS 4) → 6 (SS 6): `ElementalArea`-API weitgehend kompatibel, aber Template-Pfade und CMS-Fields für Elements prüfen.

### wkhtmltopdf / Legacy-Binaries
- `h4cc/wkhtmltopdf-amd64` ist abandoned. Für SS 6 auf Alternative umstellen (z. B. `chrome-php/chrome`, `dompdf/dompdf`) oder Fork suchen.

## Post-Upgrade

- PHPStan auf steigende Level (2, 3, …) hochziehen und Befunde abarbeiten.
- Häufig wiederkehrende manuelle Fixes dem Rector-Projekt als Issue/PR melden.
- `CLAUDE.md` des Projekts auf neue PHP-/SS-Version aktualisieren.
