# Konzept: `atwx/silverstripe-gold` — Idealstand & Drift-Checker für Silverstripe-Projekte

> Implementierungs-Vorlage für Claude Code. Dieses Dokument beschreibt **was**
> gebaut werden soll und **warum** — nicht jede Codezeile. Es ist als Briefing
> gedacht, das in der Wurzel des neuen Repos liegt (z.B. als `CONCEPT.md`) und an
> dem die Implementierung schrittweise mit Review-Gates erfolgt.

---

## 1. Zweck & Philosophie

Wir pflegen viele Silverstripe-Projekte mit unterschiedlichen Versionen, Forks
einzelner Module, optionalen Modulen (z.B. `projectinfo`) und projektspezifischer
Konfiguration. Ein pauschales „Update alle Repos" kann es nicht geben.

Was es geben kann, ist ein **Konformitäts-Checker (Drift-Detektor)**: Er
vergleicht ein Repo gegen einen deklarierten **Idealstand** („Gold") und meldet,
was abweicht. Daraus folgt erst der Vorschlag, dann — gestaffelt — die
Durchführung.

**Leitprinzip:** Trenne strikt das *Deterministische* (Fakten, reproduzierbar,
darf vollautomatisch laufen) vom *Urteilshaften* (Breaking Changes, Fork-Rebases,
Major-Migrationen — hier entscheidet ein Mensch bzw. Claude im Loop). Diese Linie
ist die wichtigste Designentscheidung des gesamten Systems — und zugleich die,
die das Ganze für andere Firmen wiederverwendbar macht (siehe §12).

**Nicht-Ziele:**

- Kein Ersatz für Renovate. Renovate bumpt Versionen; dieses System prüft
  *Profil-Konformität* inkl. Forks, Config und Frontend — und fährt Migrationen
  mit Verstand.
- Keine vollautomatische Breaking-Change-Erkennung für eigene Module. Das System
  *flaggt* Major-Sprünge und *liest* Changelogs, aber die Migrationsentscheidung
  bleibt beim Menschen.

---

## 2. Architektur: vier Schichten

| Schicht | Inhalt | Eigenschaft |
|---|---|---|
| **1 — Daten** | Profile (`profiles/`, `features/`) = der Idealstand | tool-agnostisches YAML, eine Quelle der Wahrheit |
| **2 — Werkzeug (das Auge)** | PHP-CLI, das prüft und JSON-Report ausgibt | deterministisch, läuft auch in CI / als Cron |
| **3 — Orchestrierung (das Gehirn)** | Skill mit Tier-Playbook | Claude Code interpretiert den Report und handelt |
| **4 — Verteilung** | Plugin im Marketplace-Repo | versioniert, org-weit ausrollbar |

Der Witz: Schicht 2 *rät nicht* — sie liefert harte Fakten. Schicht 3 ist die
einzige Stelle, an der Urteil fällt, und nur dort, wo Urteil wirklich nötig ist.
Schicht 2 + 3 zusammen sind die **Engine** und enthalten nichts
Firmenspezifisches; firmenspezifisch ist nur Schicht 1 (die Daten).

---

## 3. Namensgebung & Identitäten

Damit die Namen kohärent bleiben (und der Skill-Aufruf kurz):

| Was | Wert | Anmerkung |
|---|---|---|
| Repo | `atwx/silverstripe-gold` | Datenquelle **und** Marketplace in einem |
| Plugin (`plugin.json` → `name`) | `silverstripe-gold` | bestimmt den Skill-Namespace |
| Marketplace (`marketplace.json` → `name`) | `atwx` | Org-Registry, Platz für weitere Plugins |
| Skill-Ordner | `check` | → Aufruf `/silverstripe-gold:check` |
| CLI-Tool (intern) | `drift-checker` | funktionaler Name, im PATH via Plugin |
| Installation | `/plugin install silverstripe-gold@atwx` | Plugin@Marketplace |

Im Prototyp liegen **Engine und Daten zusammen** in einem Repo. Für die spätere
Aufteilung auf mehrere Firmen (Engine generisch, Daten pro Firma) siehe §12 — die
Naht dafür wird schon jetzt sauber gehalten.

---

## 4. Repo-Struktur

Das `atwx/silverstripe-gold`-Repo ist gleichzeitig **Datenquelle** (Profile)
**und** **Claude-Code-Marketplace** (Plugin mit Skill + Tool). Beides in einem
Repo, weil es dieselbe „eine Quelle der Wahrheit" ist.

```
silverstripe-gold/
├── CONCEPT.md                      # dieses Dokument
├── README.md                       # Setup für Menschen: marketplace add, install
│
├── .claude-plugin/
│   ├── plugin.json                 # Plugin-Manifest (name: silverstripe-gold)
│   └── marketplace.json            # Marketplace-Registry (name: atwx)
│
├── profiles/                       # SCHICHT 1 — Idealstand (firmenspezifisch)
│   ├── base.yml                    # gemeinsamer Sockel (PHP, CI-Konventionen)
│   └── silverstripe.yml            # extends: base — Framework-Recipe, Standardmodule
│
├── features/                       # komponierbare Overlays
│   ├── fluent.yml
│   ├── frontdesk-kit.yml
│   └── projectinfo.yml
│
├── skills/                         # SCHICHT 3 — Orchestrierung (Engine, generisch)
│   └── check/
│       └── SKILL.md                # das Tier-Playbook (siehe §8)
│
├── bin/                            # vom Plugin in PATH gelegt, solange aktiv
│   └── drift-checker.phar          # SCHICHT 2 — gebautes, self-contained CLI (Engine)
│
└── tool/                           # Quellcode des CLI (Dev-Zeit, nicht verteilt)
    ├── composer.json
    ├── box.json                    # PHAR-Build-Config
    └── src/
        ├── ProfileResolver.php     # lokalen Zeiger lesen, Gold-Profile holen + mergen
        ├── ComposerInspector.php   # composer.lock + composer outdated --json
        ├── ForkChecker.php         # source.url aus lock prüfen
        ├── FrontendChecker.php     # Build-Tool / Node-Version
        └── ReportBuilder.php       # JSON-Report mit Severities
```

**In jedem Projekt-Repo** liegt nur ein dünner, **firmenneutral benannter** Zeiger:

```yaml
# .gold-profile.yml  (im Projekt, nicht hier)
profile: silverstripe
features:
  - fluent
  - frontdesk-kit
gold_repo: atwx/silverstripe-gold   # wo die Profile liegen — Config, nicht hartcodiert (Naht, §12)
gold_ref: main                      # oder ein gepinnter Tag, z.B. v2 (siehe §7)
```

### Designentscheidung: Tool als PHAR

Das CLI wird als **PHAR** (`box`) gebaut und committet, damit Konsumenten kein
`composer install` im Tool brauchen. Die schwere Arbeit (`composer outdated`)
delegiert das Tool an den **projekteigenen** Composer im Projektverzeichnis — es
parst nur dessen JSON-Output plus die Profil-YAMLs. So bleibt das Tool leicht und
verteilbar. Das `tool/`-Verzeichnis ist Dev-Zeit; verteilt wird nur `bin/*.phar`.

---

## 5. Profile: Intent deklarieren, komponieren statt aufzählen

Zwei Prinzipien verhindern, dass die Profile explodieren oder veralten:

**(a) Komponieren, nicht aufzählen.** Es gibt *keine* Vollprofile pro Kombination
(mit/ohne Fluent × mit/ohne Frontdesk × …). Stattdessen `base` + unabhängige
Feature-Overlays, die gemergt werden. Das Projekt deklariert in seiner
`.gold-profile.yml`, welche Features es haben *soll*; der Checker merged
`base.yml + silverstripe.yml + <features>` zum effektiven Idealstand.

**(b) Intent, nicht Pinning.** Profile schreiben *Absicht* (`constraint: "^6"`,
`freshness: latest-minor`), keine festen Versionsnummern. Was „neueste" konkret
ist, löst der Checker zur Laufzeit über Composer auf — sonst pflegt man Versionen
an zwei Stellen.

### Skizze `base.yml`

```yaml
schema_version: 1
php:
  constraint: ">=8.3"
ci:
  required_workflows: [test, lint]
```

### Skizze `silverstripe.yml`

```yaml
schema_version: 1
extends: base
framework:
  constraint: "^6"
  freshness: latest-minor        # neueste im erlaubten Range erwartet
recipe:
  name: "silverstripe/recipe-cms"
  constraint: "^6"
modules:
  required:
    - { name: "atwx/silverstripe-restapi", freshness: latest-minor }
  forks:
    # erwartete Herkunft: muss aus der atwx-Org aufgelöst werden, nicht Upstream.
    # Das Org-Prefix steht NUR hier in den Daten, nie im Code (Naht, §12).
    - { name: "somevendor/somemodule", expected_source: "github.com/atwx/" }
frontend:
  build: vite
  node: ">=20"
```

### Skizze `features/frontdesk-kit.yml`

```yaml
schema_version: 1
requires:
  framework: "^6"               # Feature-Constraint; Widerspruch = Report-Fehler
modules:
  required:
    - { name: "atwx/silverstripe-frontdesk-kit", freshness: latest-minor }
```

Feature-Constraints, die sich widersprechen (z.B. Feature verlangt `^7`, Profil
erlaubt nur `^6`), tauchen automatisch als `misconfigured` im Report auf.

---

## 6. Das Werkzeug (Schicht 2): Ein- und Ausgabe

**Ablauf bei jedem Lauf:**

1. Lies lokale `.gold-profile.yml`.
2. Hole `silverstripe.yml` (+ `base.yml` via `extends` + Features) aus dem in
   `gold_repo` angegebenen Repo (Prototyp: `atwx/silverstripe-gold`) am Ref aus
   `gold_ref` — via `raw.githubusercontent.com` oder `gh api`.
3. Merge zum effektiven Idealstand.
4. Vergleiche gegen den Ist-Zustand:
   - **Versionen/Freshness:** Wrappe `composer outdated -D -f json` im
     Projektverzeichnis. Nutze das `latest-status`-Feld pro Direct-Dependency:
     `up-to-date` → ok, `semver-safe-update` → Minor/Patch verfügbar,
     `update-possible` → faktisch Major/Breaking-Verdacht.
   - **Forks:** Lies `composer.lock`; prüfe pro Fork-Eintrag `source.url` gegen
     `expected_source`. Fängt „Fork-Override ist rausgeflogen, kommt jetzt vom
     Upstream".
   - **Module vorhanden?** Required-Module, die fehlen → `missing`.
   - **Frontend/Config:** Build-Tool und Node-Version gegen Profil.

**Ausgabe:** ein JSON-Report. Severities pro geprüftem Item:

| Severity | Bedeutung | Tier (§8) |
|---|---|---|
| `ok` | entspricht dem Profil | — |
| `outdated-minor` | semver-sicheres Update verfügbar | Tier 1 |
| `outdated-major` | Major verfügbar (`update-possible`) | Tier 3 |
| `missing` | Required-Modul fehlt | Tier 2 |
| `forbidden-source` | Fork kommt aus falscher Quelle | Tier 3 |
| `misconfigured` | Constraint-Widerspruch / Config fehlt | Tier 2 |

Report-Skizze:

```json
{
  "repo": "client-xyz",
  "profile": "silverstripe+fluent+frontdesk-kit",
  "gold_repo": "atwx/silverstripe-gold",
  "gold_ref": "main",
  "checked_at": "2026-05-28T10:00:00Z",
  "items": [
    { "name": "silverstripe/framework", "status": "outdated-minor",
      "current": "6.1.2", "latest": "6.3.0", "tier": 1 },
    { "name": "atwx/silverstripe-frontdesk-kit", "status": "outdated-major",
      "current": "1.4.0", "latest": "2.0.1", "tier": 3,
      "note": "MAJOR — Migration prüfen (UPGRADING.md)" },
    { "name": "somevendor/somemodule", "status": "forbidden-source",
      "expected_source": "github.com/atwx/", "actual_source": "github.com/somevendor/",
      "tier": 3 }
  ],
  "summary": { "ok": 12, "tier1": 1, "tier2": 0, "tier3": 2 }
}
```

Das JSON ist das Bindeglied zu Schicht 3 **und** org-weit aggregierbar (siehe §9).

---

## 7. Floating vs. Pinning des Idealstands

`gold_ref` im Projekt steuert, gegen welchen Idealstand geprüft wird:

- **`main` (floating):** beim *Prüfen* erwünscht — der Check ist read-only,
  ändert nichts, sagt nur die Wahrheit über die Abweichung. Hebst du in
  `silverstripe.yml` das Framework von `^6` auf `^7`, meldet beim nächsten Check
  *jedes* Repo auf 6 sauber „driftet" — ohne dass du irgendwo etwas pushst.
- **Tag (pinning), z.B. `v2`:** für den *Apply*-Schritt, wenn Migrationen
  reproduzierbar sein sollen („dieses Repo wurde gegen v2 migriert").

**Empfehlung:** Check floatet auf `main`; der Apply-Lauf notiert den verwendeten
`gold_ref` in den Report.

---

## 8. Das Tier-Playbook (Schicht 3): `skills/check/SKILL.md`

Das Skill ist model-invoked (über die `description`) und arbeitet bei „prüf dieses
Repo gegen den Idealstand" das folgende Playbook ab. Es bündelt das Tool aus
Schicht 2 (`drift-checker.phar` liegt via Plugin im PATH).

**Ablauf:**

1. Rufe `drift-checker` auf, lies das JSON.
2. Gehe die Items nach Tier durch:

**Tier 1 — automatisch, ohne Rückfrage.**
Semver-sichere Patch/Minor-Bumps (`outdated-minor`), sofern *kein* Fork und *kein*
eigenes Modul mit Breaking-Potenzial. `composer update vendor/pkg
--with-dependencies`, danach Tests laufen lassen. Sicher, weil Semver die Garantie
gibt.

**Tier 2 — vorschlagen + freigeben.**
`missing`-Module, `misconfigured`, Frontend-/Node-Anhebungen. Claude generiert
Befehl + Diff und **hält an**. Review-Gate.

**Tier 3 — nie automatisch.**
Majors (`outdated-major`), Breaking Changes in eigenen Modulen,
`forbidden-source` (Fork-Rebases). Claude **stoppt**, liest Changelog bzw.
`UPGRADING.md` des betroffenen Moduls, ordnet ein und plant die Migration
**schrittweise mit Gates**. Durchlaufende Automatik ist hier ausdrücklich
unerwünscht.

**Sonderfall Fork (`forbidden-source`):** Das ist *kein* `composer update`, sondern
eine Git-Operation — den atwx-Fork-Branch auf das neue Upstream-Tag rebasen/mergen,
Konflikte lösen, neu taggen. Claude holt Upstream, zeigt die Konflikte, schlägt
Auflösungen vor, der Mensch nickt ab. Das ist der Paradefall, in dem die
Orchestrierungsschicht ihren Wert beweist.

---

## 9. Zwei Betriebsmodi

**Check (read-only, aggregierbar).** Org-weit über die atwx-Repos per `gh`-CLI
(Muster wie bei der Gate-Client-Verteilung): nur Schicht 2 aufrufen, JSON
einsammeln, Dashboard bauen, sehen wo es driftet und wo ein Major lauert.
Gefahrlos, darf gegen `silverstripe-gold@main` floaten, braucht Claude nicht.

**Apply (interaktiv, gegated).** Pro Repo, in Claude Code, mit dem vollen
Tier-Playbook. Claude im Loop, weil hier Urteil fällt. Notiert den `gold_ref` in
den Report → reproduzierbar.

Der Batch-Check sagt *wo* du hinmusst; der interaktive Apply fährt dich *mit
Verstand* dorthin.

---

## 10. Verteilung (Schicht 4)

Das Plugin wird über das Marketplace-Repo verteilt. Zwei Wege für die Mitarbeiter:

- **Abonnieren:** `/plugin marketplace add atwx/silverstripe-gold` →
  `/plugin install silverstripe-gold@atwx`.
- **Zero-friction:** Marketplace in der `.claude/settings.json` eines
  Projekt-Repos referenzieren — beim Klonen ist das Plugin automatisch da, ohne
  manuelle Installation.

**Versionierung:** Das `version`-Feld in `plugin.json` steuert Updates — explizit
gesetzt = kontrollierte Releases; weggelassen + per Git verteilt = jeder Commit
zählt als neue Version (Bleeding-Edge). Empfehlung: explizite `version` für das
Plugin, damit Skill-Updates kontrolliert ausrollen.

**Hinweis Namespacing:** Plugin-Skills sind geprefixt → der Aufruf lautet
`/silverstripe-gold:check`, nicht `/check`.

---

## 11. Build-Reihenfolge (empfohlen)

Billig iterieren, dann verteilen. **Nicht** mit dem vollen Plugin-Overhead starten.

1. **Standalone-Prototyp in EINEM Repo.** Skill nach `.claude/skills/check/`,
   Tool als einfaches PHP-Script (noch kein PHAR). An einem echten Repo
   durchspielen.
2. **Profile minimal aufsetzen:** `base.yml` + `silverstripe.yml`, ein Feature
   (`frontdesk-kit`). Merge-Logik + `extends` zum Laufen bringen.
3. **Checker Schritt für Schritt:** erst Freshness (`composer outdated --json`),
   dann Fork-Check (`composer.lock` `source.url`), dann Frontend/Config.
4. **Tier-Playbook in SKILL.md** schreiben und an einem Tier-1-Fall testen.
5. **Härtester Test zuerst:** einen echten **Fork-Fall** (Tier 3) durchspielen —
   wenn das Playbook den überlebt, sitzt das Konzept.
6. **Zum Plugin konvertieren:** `.claude-plugin/`, Tool als PHAR nach `bin/`,
   `marketplace.json` (name: `atwx`), `plugin.json` (name: `silverstripe-gold`).
   Nach `atwx/silverstripe-gold` heben.
7. **Org-weiten Check-Modus** per `gh`-CLI aufsetzen (read-only Dashboard).

---

## 12. Wiederverwendbarkeit: Engine-Daten-Split

Das System ist von Grund auf so geschnitten, dass es für **andere Firmen**
wiederverwendbar ist — weil die **Mechanik** (Schicht 2 + 3) nichts
Firmenspezifisches enthält. Firmenspezifisch ist ausschließlich, was in den
**Profilen** (Schicht 1) steht: welche Module, welche Fork-Org, welches Recipe.
Das ist exakt die Linie, an der man für Wiederverwendung schneidet.

### Die zwei Hälften

- **Engine** (generisch, teilbar): das Plugin — Tool-PHAR + Skill +
  Tier-Playbook. Kennt kein „atwx".
- **Gold-Daten** (pro Firma): die Profile + die Fork-Org. `atwx/gold-profiles`,
  `acme/gold-profiles`, …

Das Vorbild ist **ESLint**: die Engine ist das Produkt, `eslint-config-airbnb` die
teilbare Config. Genauso hier — eine Engine, die jede Firma installiert, und jede
pflegt ihre eigenen Gold-Profile. Der Name `silverstripe-gold` passt dann sogar
besser auf die *Engine* (das Werkzeug, das gegen einen Gold-Standard prüft),
während „dein Gold-Repo" die Daten meint.

### Bonus: framework-agnostisch

Die Engine generalisiert nicht nur über Firmen, sondern über **PHP-Frameworks**.
Das `composer outdated`-Wrapping, der Lock-Fork-Check, die Frontend-Prüfung —
nichts davon ist Silverstripe-spezifisch. Ein Laravel-Projekt gegen ein
`laravel.yml`-Profil zu prüfen bräuchte *null* Engine-Änderung, nur ein anderes
Profil. „Silverstripe" ist bloß das erste Profil, keine Annahme der Engine.

### Die Naht — jetzt schon sauber halten (ohne zu splitten)

Bau den atwx-Prototyp als ein Repo (wie in §4), aber lege die Naht von Anfang an
richtig an. Drei Stellen, an denen kein Firmen- oder Framework-Token in den Code
sickern darf:

1. **Generischer Dateiname:** `.gold-profile.yml` statt `.atwx-profile.yml` — kein
   Firmen-Token im Namen des Projekt-Zeigers.
2. **Gold-Repo als Config, nicht hartcodiert:** der Zeiger trägt
   `gold_repo: atwx/silverstripe-gold`. Das Tool *liest* die Location aus dem
   Zeiger, statt sie zu kennen.
3. **Org-Prefix nur in den Daten:** `expected_source: github.com/atwx/` steht in
   den Profilen, nicht im Code — bereits richtig, so halten.

So kostet der spätere Split fast nichts: Engine raus aus dem Daten-Repo,
`gold_repo` zeigt auf das ausgelagerte `…/gold-profiles`, fertig.

### Timing

Jetzt **nicht** vorsplitten. Erst beweisen, dass es bei atwx trägt, dann
productizen — dieselbe „iterier billig, dann verteile"-Logik wie bei
Standalone→Plugin. Die Naht oben kostet heute fast nichts und macht den Split
später trivial.

---

## 13. Offene Punkte / Entscheidungen für die Umsetzung

- **YAML-Parsing im Tool:** `symfony/yaml` (dann PHAR mit Dependency) oder
  minimal-eigenes Parsen? → Entscheidung beim Bau von `ProfileResolver`.
- **Breaking-Change-Manifest für eigene Module:** Reicht die Semver-Heuristik
  (Major = Tier 3), oder wollen wir eine maschinenlesbare `UPGRADING.md`-Konvention
  in den atwx-Modulen, die der Checker ausliest? → Erst die Heuristik, Manifest
  nur falls nötig.
- **Inferred-Modus:** Für den Rollout, solange noch nicht überall eine
  `.gold-profile.yml` liegt — Checker leitet das Profil aus dem Installierten ab
  und prüft nur interne Konsistenz + Freshness. Optional, später.
- **Auth fürs Gold-Holen:** private Repos brauchen `gh`-Token; öffentlich reicht
  `raw.githubusercontent.com`. → abhängig von der Sichtbarkeit des Gold-Repos.

---

## 14. Ehrliche Grenze

Breaking-Change-Erkennung bei eigenen Modulen ist **nicht** voll automatisierbar.
Das Skill kann den Major-Sprung *flaggen* und Claude kann den Changelog *lesen und
einordnen* — die Entscheidung, ob die Migration jetzt passiert, bleibt beim
Menschen. Das System verspricht kein „macht alles allein", sondern: nimmt das
Mechanische ab und macht das Urteilshafte handhabbar.
