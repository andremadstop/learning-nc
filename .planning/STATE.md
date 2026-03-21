---
gsd_state_version: 1.0
milestone: v3.1
milestone_name: UX-Konsolidierung
status: planning
stopped_at: Completed 15-01-PLAN.md
last_updated: "2026-03-21T02:42:42.647Z"
last_activity: 2026-03-20 — Roadmap for v3.1 UX-Konsolidierung created
progress:
  total_phases: 16
  completed_phases: 14
  total_plans: 26
  completed_plans: 25
  percent: 88
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-20)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung
**Current focus:** Phase 14 — Training-Merge

## Current Position

Phase: 14 of 16 (Training-Merge)
Plan: 0 of 2 in current phase
Status: Ready to plan
Last activity: 2026-03-20 — Roadmap for v3.1 UX-Konsolidierung created

Progress: [█████████░] 88%

## Performance Metrics

**Velocity:**
- Total plans completed: 22 (across v2.3, v2.6, v3.0)
- Average duration: —
- Total execution time: —

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1-13 | 22/22 | — | — |
| 14-16 | 0/5 | — | — |

*Updated after each plan completion*
| Phase 14-training-merge P01 | 6 | 1 tasks | 1 files |
| Phase 14-training-merge P02 | 15 | 2 tasks | 5 files |
| Phase 15-arena P01 | 15 | 3 tasks | 5 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- SwipeMode wird IN TrainingMode integriert (nicht umgekehrt), weil TrainingMode groesser und feature-reicher ist
- Duell+Gameshow bekommen gemeinsamen Namen (z.B. "Arena") — konkreter Name noch offen
- [Phase 14-training-merge]: wfMode toggle integrated into TrainingMode.vue — selectWfAnswer maps True/False to existing submitAnswer() without new API endpoint
- [Phase 14-training-merge]: SwipeMode.vue unchanged in plan 01 — removal/deprecation deferred to later plan
- [Phase 14-training-merge]: wfMode added as Boolean prop to TrainingMode; localWfMode as toggleable internal copy; SwipeMode.vue deleted
- [Phase 15-arena]: arenaSubMode null=selector, string=sub-component; selectTab resets arenaSubMode

### Pending Todos

None yet.

### Blockers/Concerns

- CourseDetail.vue hat 10+ Tabs — Arena-Merge reduziert um 1 Tab, Training-Merge um 1 weiteren
- DuelMode und GameshowMode haben separate Session-Backends — Session-Robustheit muss beide handlen

## Session Continuity

Last session: 2026-03-21T02:42:42.634Z
Stopped at: Completed 15-01-PLAN.md
Resume file: None
