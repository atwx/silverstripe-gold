# atwx/silverstripe-gold

Idealstand („Gold") + Drift-Checker für unsere PHP-/Silverstripe-Projekte.
Dieses Repo ist zugleich **Datenquelle** (Profile) und **Engine** (Tool + Skill).
Konzept & Begründung: siehe [`CONCEPT.md`](CONCEPT.md).

## Was ist hier drin?

| Pfad | Schicht | Inhalt |
|---|---|---|
| `.claude-plugin/` | 4 — Verteilung | `marketplace.json` (`atwx`) + `plugin.json` (`silverstripe-gold`) |
| `profiles/` | 1 — Daten | Idealstand-Profile (`base`, `silverstripe`, `features/*`) |
| `bin/drift-checker` | 2 — Werkzeug | On-PATH-Entry (Plugin legt `bin/` in den PATH) |
| `tool/` | 2 — Werkzeug | dependency-freier Engine-Quellcode (`src/`) |
| `skills/check/` | 3 — Orchestrierung | Tier-Playbook (`SKILL.md`) → `/silverstripe-gold:check` |

In jedem **Projekt-Repo** liegt nur der dünne Zeiger `.gold-profile.yml` — er
wählt Profil + Features und zeigt via `gold_repo`/`gold_ref` hierher.

## In ein Projekt einbinden

**Schritt A — Plugin installieren (einmal pro Mitarbeiter):**

```text
/plugin marketplace add atwx/silverstripe-gold
/plugin install silverstripe-gold@atwx
```

Danach liegt das Tool als Bare-Command `drift-checker` im PATH und der Skill ist
als `/silverstripe-gold:check` verfügbar.

**Schritt B — Zeiger ins Projekt (einmal pro Repo):** eine `.gold-profile.yml`
in den Projekt-Root committen:

```yaml
profile: silverstripe
features:
  - frontdesk-kit
gold_repo: atwx/silverstripe-gold
gold_ref: main
```

**Optional — Zero-Friction:** statt Schritt A pro Person, im Projekt-Repo eine
`.claude/settings.json` hinterlegen — beim Klonen + Trust wird das Plugin
automatisch aktiviert:

```json
{
  "extraKnownMarketplaces": {
    "atwx": { "source": { "source": "github", "repo": "atwx/silverstripe-gold" } }
  },
  "enabledPlugins": { "silverstripe-gold@atwx": true }
}
```

> Privates Repo? Für `marketplace add` braucht es GitHub-Zugriff (z.B. via `gh`-Auth / SSH-Key).

## Lokal ausführen (Dev, ohne Plugin)

Das Tool ist abhängigkeitsfrei — kein `composer install` nötig:

```bash
bin/drift-checker --project=/pfad/zum/projekt
```

| Flag | Default | Zweck |
|---|---|---|
| `--project` | aktuelles Verzeichnis | zu prüfendes Projekt-Repo |
| `--profiles` | `profiles/` in diesem Repo | Profil-Ordner |
| `--composer` | auto (`ddev composer` bei `.ddev/`, sonst `composer`) | Composer-Binary übersteuern |
| `--compact` | aus | JSON ohne Pretty-Print |

DDEV wird automatisch erkannt (Vorhandensein von `.ddev/config.yaml`); das
tatsächlich verwendete Binary steht im Report-Feld `composer`.

## Implementierungsstand

- ✅ Schritt 1–3 (§11): Profile-Merge (`extends` + Features), Freshness
  (`composer outdated`), Fork-Check (`composer.lock` `source.url`),
  Missing-Module, Frontend/Config. JSON-Report nach Schema §6.
- ✅ Tier-Playbook `skills/check/SKILL.md` (§8).
- ✅ Plugin/Marketplace-Manifeste (§10/§11.6) — installierbar via `/plugin`.
- ⬜ Gold-Holen aus GitHub (§6 Schritt 2) — aktuell lokaler `profiles/`-Ordner.
- ⬜ PHAR-Build (`tool/box.json` → `bin/drift-checker.phar`) — optional, da die
  Engine dependency-frei ist; nur nötig für Verteilung außerhalb des Plugins
  (z.B. `composer global require`).

Details & offene Punkte: `tool/` und `CONCEPT.md` §13.
