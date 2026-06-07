# atwx/silverstripe-gold

Idealstand („Gold") + Drift-Checker für unsere PHP-/Silverstripe-Projekte.
Dieses Repo ist zugleich **Datenquelle** (Profile) und **Engine** (Tool + Skill).
Konzept & Begründung: siehe [`CONCEPT.md`](CONCEPT.md).

## Was ist hier drin?

| Pfad | Schicht | Inhalt |
|---|---|---|
| `profiles/` | 1 — Daten | Idealstand-Profile (`base`, `silverstripe`, `features/*`) |
| `tool/` | 2 — Werkzeug | dependency-freies PHP-CLI (`drift-checker`), Dev-Zeit |
| `bin/` | 2 — Werkzeug | gebautes, self-contained `drift-checker.phar` (Verteilung) |
| `skills/check/` | 3 — Orchestrierung | Tier-Playbook (`SKILL.md`) |

In jedem **Projekt-Repo** liegt nur der dünne Zeiger `.gold-profile.yml` — er
wählt Profil + Features und zeigt via `gold_repo`/`gold_ref` hierher.

## Lokal ausführen (Dev)

Das Tool ist abhängigkeitsfrei — kein `composer install` nötig:

```bash
tool/bin/drift-checker --project=/pfad/zum/projekt
```

| Flag | Default | Zweck |
|---|---|---|
| `--project` | aktuelles Verzeichnis | zu prüfendes Projekt-Repo |
| `--profiles` | `profiles/` in diesem Repo | Profil-Ordner |
| `--composer` | `composer` | Composer-Binary (z.B. `"ddev composer"`) |
| `--compact` | aus | JSON ohne Pretty-Print |

## Implementierungsstand

- ✅ Schritt 1–3 (§11): Profile-Merge (`extends` + Features), Freshness
  (`composer outdated`), Fork-Check (`composer.lock` `source.url`),
  Missing-Module, Frontend/Config. JSON-Report nach Schema §6.
- ⬜ Gold-Holen aus GitHub (§6 Schritt 2) — aktuell lokaler `profiles/`-Ordner.
- ⬜ Tier-Playbook `skills/check/SKILL.md` (§8).
- ⬜ PHAR-Build (`tool/box.json` → `bin/drift-checker.phar`) + Plugin (§10/§11.6).

Details & offene Punkte: `tool/` und `CONCEPT.md` §13.
