---
gsd_state_version: 1.0
milestone: v2.3
milestone_name: milestone
status: Not started
stopped_at: Completed 115-01-PLAN.md
last_updated: "2026-03-30T09:13:30.914Z"
last_activity: 2026-03-30 — v3.7.0 roadmap created
progress:
  total_phases: 8
  completed_phases: 5
  total_plans: 13
  completed_plans: 12
  percent: 0
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-30)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** Phase 114 — UX-Modus-Steuerung (not yet started)

## Current Position

Phase: 114 (1 of 4 in v3.7.0) (UX-Modus-Steuerung)
Plan: 0 of ? in current phase
Status: Not started
Last activity: 2026-03-30 — v3.7.0 roadmap created

Progress: [__________] 0% (0/? plans)

## Performance Metrics

**Velocity (v3.7.0):**
- Total plans completed: 0
- Reference from v3.6.0: ~4.5min avg per plan, 9 plans total

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 114. UX-Modus-Steuerung | 0/? | - | - |
| 115. Wahr/Falsch-Migration | 0/? | - | - |
| 116. DSGVO Help-Seite | 0/? | - | - |
| 117. Dashboard Prüfungstermin | 0/? | - | - |
| Phase 114 P01 | 5 | 3 tasks | 4 files |
| Phase 114-ux-modus-steuerung P02 | 6 | 2 tasks | 2 files |
| Phase 114-ux-modus-steuerung P02 | 10 | 3 tasks | 2 files |
| Phase 115-wahr-falsch-migration P01 | 8 | 2 tasks | 2 files |

## Accumulated Context

### Decisions

- [v3.6.0] Telos Encryption: only encrypt bio/telos_json, NOT help_offer/help_wanted (buddy matching needs SQL)
- [v3.6.0] ICS feed: individual VEVENTs (no RRULE), use sabre/vobject library
- [v3.6.0] Mega-tabs: visibleTabs returns 5 mega-tabs (instructor) or 4 (student); leaf IDs preserved internally
- [v3.6.0] Narrative cached in snapshot blob via UPDATE (not new row) — one snapshot per course/user
- [v3.7.0] Mode visibility: UX-01 hides Training-Modus tab/link for students, instructor keeps access
- [v3.7.0] true_false → single migration must be idempotent (safe to run twice)
- [v3.7.0] DSGVO: privacy URL set in config.php; privacy-info.json is the source of truth for 7 categories
- [v3.7.0] Dashboard widget hidden entirely when no exam_date set (DASH-03)
- [Phase 114]: defaultSubTab fallback changed to '' when training disabled — student sees no active sub-tab
- [Phase 114-02]: Hero card placement: above pool list, v-if=!selectedLearningPool, disappears on pool open
- [Phase 114-02]: fetchQueueCount non-fatal: count stays 0 on error, cross-course label 'fällig — alle Kurse'
- [Phase 115]: Migration Version006300 uses QueryBuilder instead of raw SQL for NC API consistency; data-only (changeSchema returns null)
- [Phase 115]: ImportController normalizeJsonItem guard placed immediately after $type assignment — normalized at import boundary

### Pending Todos

- Course 20 duplicate vault chunks (1076 old + 1001 new = 2077) — dedup if RAG quality degrades

### Blockers/Concerns

None at milestone start.

## Session Continuity

Last session: 2026-03-30T09:13:30.907Z
Stopped at: Completed 115-01-PLAN.md
Resume file: None
