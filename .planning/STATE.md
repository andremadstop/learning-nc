# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-21)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — für Einzellerner und Kursgruppen.
**Current focus:** Phase 28 — Brettspiel-Backend

## Current Position

Phase: 30 of 31 (Lernwürfel)
Plan: 0 of ? in current phase
Status: Ready to plan
Last activity: 2026-03-21 — Phase 29 complete (29-01-SUMMARY.md)

Progress: [█████████████████████░░░░░░░░░] 74% (29/31 phases complete)

## Performance Metrics

**Velocity:**
- Total plans completed: 34 (Phases 1-27)
- Average duration: ~30 min
- Total execution time: ~17 hours

**By Phase:**

| Phase | Plans | Avg/Plan |
|-------|-------|----------|
| v2.3 (1-6) | 10 | ~30 min |
| v2.6 (7) | 3 | ~30 min |
| v3.0 (8-13) | 12 | ~30 min |
| v3.1 (14-16) | 5 | ~30 min |
| v3.2 (17-21) | 6 | ~30 min |
| v4.0 (22-27) | 6 | ~30 min |

*Updated after each plan completion*

## Accumulated Context

### Decisions

- v5.0 uses existing gameshow_sessions/gameshow_players schema (mode='lernwuerfel'/'wissensturm')
- SVG for game boards (no Canvas) — consistent with PBQ topology renderer pattern
- Vue 2.7 Options API — no composition API
- OldschoolSelector.vue mirrors ArenaSelector.vue pattern
- 2-4 players (not 2-5 like Gameshow)
- Board state stored as JSON TEXT in existing gameshow_sessions column — no new table needed (Phase 28)
- Turn-based phase machine: roll→question→roll (next player), special_effect=bonus_roll keeps same player
- Lernwürfel uses round-robin question recycling (mod questionIds length) not linear exhaustion
- Wissensturm win condition: 5 unique category colours (not total block count)

### Pending Todos

None yet.

### Blockers/Concerns

None yet.

## Session Continuity

Last session: 2026-03-21
Stopped at: Phase 29 complete — 29-01-SUMMARY.md created. Ready for Phase 30 Lernwürfel.
Resume file: None
