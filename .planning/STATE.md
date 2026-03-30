---
gsd_state_version: 1.0
milestone: v2.3
milestone_name: milestone
status: completed
stopped_at: Completed 113-02-PLAN.md — Narrative Portfolio + ICS Calendar (Phase 113 complete, v3.6.0 milestone done)
last_updated: "2026-03-30T04:18:12.261Z"
last_activity: 2026-03-30 — Completed 113-02 Narrative Portfolio + ICS Calendar
progress:
  total_phases: 4
  completed_phases: 4
  total_plans: 9
  completed_plans: 9
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-29)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** Phase 113 — AI Erklaerbot

## Current Position

Phase: 113 (4 of 4 in v3.6.0) (AI Erklaerbot)
Plan: 2 of 2 in current phase
Status: Phase Complete
Last activity: 2026-03-30 — Completed 113-02 Narrative Portfolio + ICS Calendar

Progress: [██████████] 100% (9/9 plans)

## Performance Metrics

**Velocity:**
- Total plans completed: 5 (v3.6.0)
- Average duration: ~4min
- Total execution time: ~0.2 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 110 | 2/2 | ~2min | ~1min |
| 111 | 2/2 | ~8min | ~4min |
| 112 | 3/3 | ~22min | ~7min |
| 113 | 2/2 | ~11min | ~5.5min |
| Phase 111 P01 | 10min | 2 tasks | 9 files |
| Phase 113 P01 | 5min | 2 tasks | 5 files |
| Phase 112 P01 | 8min | 2 tasks | 5 files |
| Phase 112 P02 | 10min | 2 tasks | 6 files |
| Phase 112 P03 | 4min | 2 tasks | 3 files |
| Phase 113 P02 | 6min | 2 tasks | 12 files |

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
- [Phase 112]: activeMegaTab as explicit data property synced bidirectionally with currentTab via megaTabForLeaf; visibleMegaTabs primary computed, visibleTabs alias for watcher compat
- [Phase 113]: VirtuProf lazy-loaded via dynamic import to reduce initial bundle size
- [Phase 113]: previousMainView tracked as data property for reliable close-to-previous navigation
- [Phase 113]: Fullscreen placeholder div in App.vue kept empty (no aria-hidden) for accessibility
- [Phase 113]: Narrative cached in snapshot blob via UPDATE (not new row) to keep one snapshot per course/user
- [Phase 113]: loadNarrative() and loadIcsToken() fire-and-forget after summary loads (non-blocking UX)
- [Phase 113]: Pre-existing PHPStan errors (20) from Codex ICS backend left as-is (out of scope)

### Pending Todos

None yet.

### Blockers/Concerns

- Course 20 has duplicate vault chunks (1076 old + 1001 new = 2077) — consider dedup if affecting RAG quality

## Session Continuity

Last session: 2026-03-30T03:50:00Z
Stopped at: Completed 113-02-PLAN.md — Narrative Portfolio + ICS Calendar (Phase 113 complete, v3.6.0 milestone done)
Resume file: None
