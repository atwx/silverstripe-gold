---
name: check
description: Prüft das aktuelle Silverstripe-/PHP-Projekt gegen den Gold-Idealstand (Drift-Check) und arbeitet die Abweichungen nach Tier-Playbook ab. Nutzen, wenn der User ein Repo gegen den Idealstand prüfen, Updates/Drift finden oder gestaffelt durchführen will.
---

# check — Drift gegen den Gold-Idealstand prüfen und abarbeiten

Dieses Skill bündelt das Tool `drift-checker` (liegt via Plugin im PATH) mit dem
Tier-Playbook. Es interpretiert den deterministischen JSON-Report und handelt —
**Urteil fällt nur hier** (Konzept §2/§8).

## 1. Report holen

Vorbedingung: im Projekt liegt eine `.gold-profile.yml` (sonst dem User
anbieten, eine anzulegen — `profile`, `features`, `gold_repo`, `gold_ref`).

```bash
drift-checker --project="$CLAUDE_PROJECT_DIR"
```

- **DDEV wird automatisch erkannt** (`.ddev/config.yaml`) und Composer im
  Container ausgeführt — nichts zu tun. Das verwendete Binary steht im Report
  unter `composer`. Übersteuern nur bei Bedarf mit `--composer="..."`.
- Das Tool ist read-only; es ändert nichts.

Den JSON-Report parsen und die `items` nach `tier` gruppieren.

## 2. Items nach Tier abarbeiten

**Tier 1 — automatisch, ohne Rückfrage.** `outdated-minor` (semver-sicher),
sofern kein Fork und kein eigenes Modul mit Breaking-Potenzial:
```bash
composer update vendor/pkg --with-dependencies
```
Danach Tests laufen lassen. Sicher, weil Semver die Garantie gibt.

**Tier 2 — vorschlagen + freigeben.** `missing` (Modul nachinstallieren),
`misconfigured` (Frontend/Node/Config). Befehl + Diff generieren und **anhalten**.
Review-Gate — nicht ohne Freigabe ausführen.

**Tier 3 — nie automatisch.** `outdated-major` und `forbidden-source`:
- **Major:** stoppen, Changelog bzw. `UPGRADING.md` des Moduls lesen, einordnen,
  Migration **schrittweise mit Gates** planen. Keine durchlaufende Automatik.
- **`forbidden-source` (Fork):** das ist **keine** `composer update`, sondern
  eine **Git-Operation** — den Fork-Branch auf das neue Upstream-Tag
  rebasen/mergen, Konflikte zeigen, Auflösungen vorschlagen, der Mensch nickt ab,
  neu taggen.

## 3. Zusammenfassen

`summary` wiedergeben (ok / tier1 / tier2 / tier3), klar trennen: was wurde
automatisch erledigt (Tier 1), was wartet auf Freigabe (Tier 2), was braucht eine
geplante Migration (Tier 3). Bei Tier 3 nichts ohne ausdrückliche Zustimmung tun.
