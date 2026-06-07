---
name: check
description: TODO — Tier-Playbook (Konzept §8). Noch nicht implementiert.
---

# check — Tier-Playbook (Platzhalter)

> Dieser Skill ist noch ein Stub. Die Struktur folgt §4; das eigentliche
> Playbook (§8) wird als nächster Schritt geschrieben und an einem Tier-1-Fall
> getestet, dann am Fork-Fall (Tier 3).

Geplanter Ablauf (siehe `CONCEPT.md` §8):

1. `drift-checker` aufrufen, JSON-Report lesen.
2. Items nach Tier abarbeiten:
   - **Tier 1** (`outdated-minor`): semver-sichere Bumps, automatisch + Tests.
   - **Tier 2** (`missing`, `misconfigured`): Vorschlag + Diff, Review-Gate.
   - **Tier 3** (`outdated-major`, `forbidden-source`): nie automatisch —
     Changelog/UPGRADING lesen, Migration schrittweise mit Gates. Forks =
     Git-Rebase, kein `composer update`.
