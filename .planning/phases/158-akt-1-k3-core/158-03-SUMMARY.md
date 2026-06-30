---
phase: 158-akt-1-k3-core
plan: "03"
subsystem: campaigns
tags: [ghostline, content, k3, lpic, grep, sed, term-02, fixture]
dependency_graph:
  requires:
    - app/data/campaigns/ghostline_act1.json (9-node K3 arc from 158-02, with 6 PLACEHOLDERs)
  provides:
    - app/data/campaigns/fixtures/ghost_journal.txt (deterministic shell fixture)
    - app/data/campaigns/ghostline_act1.json (TERM-02 complete — real shell outputs)
  affects: []
tech_stack:
  added: []
  patterns:
    - TERM-02: deterministic fixture → real shell capture → JSON output embedding
    - gitignore exception pattern for committed content fixtures (*.txt glob + negation)
key_files:
  created:
    - app/data/campaigns/fixtures/ghost_journal.txt
  modified:
    - app/data/campaigns/ghostline_act1.json
    - .gitignore
decisions:
  - "Added gitignore exception !app/data/campaigns/fixtures/*.txt because root *.txt glob blocked the committed fixture (*.txt rule was for CompTIA question pools)"
  - "Fixture has 8 lines starting with T (more than the required ≥4) — all 8 appear in grep output, which is correct and deterministic"
  - "sed output includes all 15 lines with ERROR→WARN substitution applied (3 lines changed), remaining 12 lines unchanged"
metrics:
  duration: "~15min"
  completed: "2026-06-30"
  tasks_completed: 2
  tasks_total: 2
  files_created: 1
  files_modified: 2
---

# Phase 158 Plan 03: TERM-02 Terminal Fixtures Summary

One-liner: Deterministic ghost_journal.txt fixture created and committed; all 6 PLACEHOLDER terminal outputs replaced with real captured stdout from grep and sed.

## What Was Built

### app/data/campaigns/fixtures/ghost_journal.txt (new)

15-line journal in Noir/Ghostline tone — the Geist's fragmented diary:

```
Tagebuch des Geists — Version 0
Alles begann mit einem Signal, das niemand senden wollte.
Timestamp: 1969-01-01T00:00:00Z — Initialisierung
Tue Mar 10 03:14:07 UTC 1970 — Systemstart
ERROR: Speicher nicht initialisiert — Daten verloren
Das Netz kennt keine Namen, nur Adressen.
Traceroute endet im Nichts. Paket verloren.
ERROR: Verbindung unterbrochen — Neustart erforderlich
Suchmaschinen lügen. grep lügt nicht.
Terminierung ohne Rückmeldung.
The pattern persists. The signal remains.
ERROR: Zeitstempel außerhalb des gültigen Bereichs
Nur Muster bleiben. Texte vergehen.
Tue, 14 Apr 1970 — letzter bekannter Eintrag
Terminal: Keine weiteren Daten verfügbar.
```

Content verification:
- Lines starting with T: **8** (≥4 required)
- Lines containing ERROR: **3** (≥3 required)

### Real Shell Commands Run (TERM-02 sign-off)

All commands run from `app/data/campaigns/fixtures/` against committed fixture.

**Terminal 1 — `grep ^T ghost_journal.txt`:**

```
Tagebuch des Geists — Version 0
Timestamp: 1969-01-01T00:00:00Z — Initialisierung
Tue Mar 10 03:14:07 UTC 1970 — Systemstart
Traceroute endet im Nichts. Paket verloren.
Terminierung ohne Rückmeldung.
The pattern persists. The signal remains.
Tue, 14 Apr 1970 — letzter bekannter Eintrag
Terminal: Keine weiteren Daten verfügbar.
```

This exact stdout was written to all 3 valid_commands entries in `a1_k3_grep1` (bare / single-quote / double-quote variants).

**Terminal 2 — `sed 's/ERROR/WARN/g' ghost_journal.txt`:**

```
Tagebuch des Geists — Version 0
Alles begann mit einem Signal, das niemand senden wollte.
Timestamp: 1969-01-01T00:00:00Z — Initialisierung
Tue Mar 10 03:14:07 UTC 1970 — Systemstart
WARN: Speicher nicht initialisiert — Daten verloren
Das Netz kennt keine Namen, nur Adressen.
Traceroute endet im Nichts. Paket verloren.
WARN: Verbindung unterbrochen — Neustart erforderlich
Suchmaschinen lügen. grep lügt nicht.
Terminierung ohne Rückmeldung.
The pattern persists. The signal remains.
WARN: Zeitstempel außerhalb des gültigen Bereichs
Nur Muster bleiben. Texte vergehen.
Tue, 14 Apr 1970 — letzter bekannter Eintrag
Terminal: Keine weiteren Daten verfügbar.
```

This exact stdout was written to all 3 valid_commands entries in `a1_k3_grep2` (single-quote / double-quote / bare variants).

### ghostline_act1.json — 6 PLACEHOLDERs replaced

| Node | Command | Variant | Required |
|------|---------|---------|----------|
| a1_k3_grep1 | `grep ^T ghost_journal.txt` | bare | true |
| a1_k3_grep1 | `grep '^T' ghost_journal.txt` | single-quote | false |
| a1_k3_grep1 | `grep "^T" ghost_journal.txt` | double-quote | false |
| a1_k3_grep2 | `sed 's/ERROR/WARN/g' ghost_journal.txt` | single-quote | true |
| a1_k3_grep2 | `sed "s/ERROR/WARN/g" ghost_journal.txt` | double-quote | false |
| a1_k3_grep2 | `sed s/ERROR/WARN/g ghost_journal.txt` | bare | false |

## Vitest Output (GREEN — Task 2)

```
 Test Files  1 passed (1)
       Tests  12 passed (12)
   Duration  2.13s
```

## Verification Results

| Check | Result |
|-------|--------|
| Fixture exists | OK (680 bytes) |
| Lines starting with T | 8 (≥4 required) |
| Lines containing ERROR | 3 (≥3 required) |
| PLACEHOLDER count in JSON | 0 |
| JSON valid (node parse) | OK |
| ghostlineGraph.test.js | 12/12 passed |
| Outputs hand-written/invented | NO — real shell capture via subprocess |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Added gitignore exception for fixtures directory**

- **Found during:** Task 1 commit
- **Issue:** Root `.gitignore` has `*.txt` glob (for CompTIA question pools). This blocked `ghost_journal.txt` from being staged.
- **Fix:** Added `!app/data/campaigns/fixtures/*.txt` negation after the existing README/CHANGELOG exceptions.
- **Files modified:** `.gitignore`
- **Commit:** fba5781 (included in Task 1 commit)

## Commits

| Hash | Message |
|------|---------|
| fba5781 | feat(158-03): add ghost_journal.txt fixture for terminal challenges |
| fa42ccf | feat(158-03): replace 6 PLACEHOLDER outputs with real shell stdout (TERM-02) |

## TERM-02 Sign-Off

Executor confirms: commands `grep ^T ghost_journal.txt` and `sed 's/ERROR/WARN/g' ghost_journal.txt` were **actually run** on the shell against the committed fixture file. Output was captured via `subprocess.run()` in Python (not generated, not hand-written). The JSON was updated programmatically from the captured bytes, preserving Unicode (em-dashes, umlauts) without escaping.

## Self-Check: PASSED

Files exist:
- FOUND: app/data/campaigns/fixtures/ghost_journal.txt
- FOUND: app/data/campaigns/ghostline_act1.json (0 PLACEHOLDERs)

Commits exist:
- FOUND: fba5781 (feat(158-03): add ghost_journal.txt fixture)
- FOUND: fa42ccf (feat(158-03): replace 6 PLACEHOLDER outputs)

Vitest: 12/12 passed
JSON: valid
PHP files touched: none
Vue files touched: none
