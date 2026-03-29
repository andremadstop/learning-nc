---
gsd_state_version: 1.0
milestone: v2.3
milestone_name: milestone
status: executing
stopped_at: Completed 112-02-PLAN.md — Wettbewerb + Teilnehmer mega-tabs extracted, all 5 mega-tabs done
last_updated: "2026-03-29T20:35:42Z"
last_activity: 2026-03-29 — Completed 111-02 CompTIA Vault Import
progress:
  total_phases: 4
  completed_phases: 2
  total_plans: 7
  completed_plans: 6
  percent: 86
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-29)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** Phase 112 — Erklaerbot Integration

## Current Position

Phase: 112 (3 of 4 in v3.6.0) (Tab Reduktion)
Plan: 2 of 3 in current phase
Status: Executing
Last activity: 2026-03-29 — Completed 112-02 Wettbewerb + Teilnehmer extraction

Progress: [████████░░] 86% (6/7 plans)

## Performance Metrics

**Velocity:**
- Total plans completed: 4 (v3.6.0)
- Average duration: ~3min
- Total execution time: ~0.2 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 110 | 2/2 | ~2min | ~1min |
| 111 | 2/2 | ~8min | ~4min |
| 112 | 2/3 | ~18min | ~9min |
| 113 | 0/2 | - | - |
| Phase 111 P01 | 10min | 2 tasks | 9 files |
| Phase 112 P01 | 8min | 2 tasks | 5 files |
| Phase 112 P02 | 10min | 2 tasks | 6 files |

## Accumulated Context

### Decisions

- Telos Encryption: only encrypt bio/telos_json, NOT help_offer/help_wanted (buddy matching needs SQL)
- CourseDetail.vue (1500+ lines) must be decomposed BEFORE adding Erklaerbot features
- ICS feed: individual VEVENTs (no RRULE), use sabre/vobject library
- L10n JSON uses nested translations object — tests must access .translations property
- Privacy category matching uses name substrings for resilience against reordering
- Vault import uses bind mount ~/comptia-vault on learning-dev (ro mount), not docker cp
- _devcloud/ subdirectories included in vault import (DevCloud course material)
- [Phase 111]: Simulator badge counts finished coop sessions (JOIN), not learning_sessions — simulator mode does not exist in sessions table
- [Phase 111]: Migration boolean notnull=false, default=0 for NC Doctrine compatibility
- [Phase 112]: Mega-tabs kommunikation/verwaltung replace individual leaf tabs in visibleTabs; leaf IDs preserved internally
- [Phase 112]: visibleTabs now returns 5 mega-tabs (instructor) or 4 (student); all leaf IDs moved to *LeafTabs computed properties
- [Phase 112]: Wettbewerb always visible for students (leaderboard always enabled); arenaSubMode propagated via event for VirtuProf

### Pending Todos

None yet.

### Blockers/Concerns

- Course 20 has duplicate vault chunks (1076 old + 1001 new = 2077) — consider dedup if affecting RAG quality

## Session Continuity

Last session: 2026-03-29T20:35:42Z
Stopped at: Completed 112-02-PLAN.md — Wettbewerb + Teilnehmer mega-tabs extracted, all 5 mega-tabs done
Resume file: None
