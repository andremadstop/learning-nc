---
gsd_state_version: 1.0
milestone: v3.7.0
milestone_name: Efficiency & Compliance
status: active
stopped_at: Roadmap created — ready to start Phase 114
last_updated: "2026-03-30T00:00:00.000Z"
last_activity: 2026-03-30 — v3.7.0 roadmap created (4 phases, 11 requirements)
progress:
  total_phases: 4
  completed_phases: 0
  total_plans: 0
  completed_plans: 0
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

### Pending Todos

- Course 20 duplicate vault chunks (1076 old + 1001 new = 2077) — dedup if RAG quality degrades

### Blockers/Concerns

None at milestone start.

## Session Continuity

Last session: 2026-03-30 (v3.6.0 completion + v3.7.0 roadmap)
Stopped at: Roadmap created — ready to start Phase 114
Resume file: None
