---
gsd_state_version: 1.0
milestone: v2.3
milestone_name: milestone
status: active
stopped_at: Completed Phase 103
last_updated: "2026-03-29T10:30:00Z"
last_activity: 2026-03-29 — Phase 103 complete (Schwarm-Consent + Loeschkonzept)
progress:
  total_phases: 7
  completed_phases: 7
  total_plans: 13
  completed_plans: 13
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-28)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v3.4.0 UX-Konsolidierung & Simulator-Upgrade

## Current Position

Phase: 104 — Summary-Backend
Plan: 01 complete (1/1 plans)
Status: Complete
Last activity: 2026-03-29 - Completed Phase 104: Summary-Backend

Progress: [██████████] 100% (13/13 plans)

## Phase Overview

| Phase | Name | Requirements | Status |
|-------|------|--------------|--------|
| 96 | UX-Navigation Struktur | NAV-01, NAV-02, NAV-03, NAV-04 | In progress (1/? plans) |
| 97 | Code-Hygiene & Settings | NAV-05, NAV-06, NAV-07 | Not started |
| 98 | Simulator-Praxis-Sessions | SIM-01, SIM-02, SIM-03 | Complete (2/2 plans) |
| 99 | Student-Dashboard | DASH-01, DASH-02, DASH-03 | Complete (2/2 plans) |
| 100 | DevCloud-Integration & Leitner | DVCL-01, DVCL-02, DVCL-03, DVCL-04, LEIT-01 | Complete (3/3 plans) |
| 101 | In-App Datenschutz-Seite | DSE-01 | Complete (1/1 plans) |
| 102 | AI Consent erweitern | DSE-02 | Complete (1/1 plans) |
| 103 | Schwarm-Consent + Loeschkonzept | DSE-03, DSE-04 | Complete (1/1 plans) |
| 104 | Summary-Backend | KE-01 | Complete (1/1 plans) |

## Accumulated Context

### Decisions

- Dual-Audit (Codex + Gemini) completed 2026-03-28 — 17 independent findings
- Versionierung synchronisiert: GSD-Milestones = App Store Version (v3.4.0 nach v3.3.0)
- v13.0 Git-Tag geloescht (war interne GSD-Nummer, nicht App-Version)
- Simulator-Uebungen komplett ersetzen: gefuehrte Praxis-Sessions statt Random
- Codex-exklusive Findings: Pool-Ebene versteckt, Oldschool Dead End, Kursregeln != UI-Gating, Settings-Split, Werkzeug-Regeln
- Gemini-exklusiv: Leitner Sprint-Intervalle fuer Kurzkurse
- Beide: Abenteuer aus Arena, DE/EN Mix, Dozent 16 Tabs, Talk/Deck nicht integriert
- Phase 96+97 gruppieren nach Komplexitaet: Struktur-Aenderungen (96) getrennt von Hygiene-Aenderungen (97)
- Phase 100 haengt auch von Phase 96 ab (CourseDetail-Tab-Struktur muss vor Materialien-Tab stehen)
- [Phase 96]: Tab groups: Lernraum > Teilnehmer > Kommunikation > Wettbewerb > Verwaltung; student tabs stay flat
- [Phase 96]: Sprint+Elimination share gameshow config key; visibleTabs watcher added as tab-fallback safety
- [Phase 97]: Settings sub-tabs use course-sub-nav pattern for visual consistency
- [Phase 97]: epoch-tokens.css import kept, backend Zeitreise cleanup deferred
- [Phase 97]: MODE_MAP labels stay as const keys, this.t() applied in modeConfig computed
- [Phase 98]: Engine uses lnc-practicum- localStorage prefix for session persistence
- [Phase 98]: Session steps reference existing scenarioIds from *_scenarios.json (zero duplication)
- [Phase 98]: PracticumRunner uses SimulatorShell for embedded rendering per step
- [Phase 98]: Context panel collapsible, v-else changed to v-else-if in all 7 simulators
- [Phase 99]: DailyChallengeCard self-contained (no props) — loads own data like PoolList did
- [Phase 99]: Student default view = dashboard (set after fetchRole), Pools = separate nav tab
- [Phase 99]: Dashboard two-column layout 65/35 with widget-card pattern from InstructorDashboard
- [Phase 99]: Course name enrichment in FeedController (lazy-cached per request) rather than SQL JOIN
- [Phase 99]: Global feed 10-item limit (compact, secondary to learning widgets)
- [Phase 100]: isSprintPool() uses course_pools join rather than CourseMapper injection
- [Phase 100]: Buddy matching filters private-visibility telos, sorted by topic overlap count
- [Phase 100]: Sprint intervals 0/4h/12h/1d/2d for bootcamp courses
- [Phase 100]: visibleToolsTabs hides disabled tools entirely per CONTEXT.md (not greyed out)
- [Phase 100]: Materials tab gated on material_folder presence, not mode_config toggle
- [Phase 100]: Talk link opens in new tab via /apps/spreed/#/call/{token}
- [Phase 100]: Lernpartner tab after Feed before Leaderboard in student tabs
- [Phase 100]: Instructors see buddy matches in Klassen-Profil (no separate tab)
- [Phase 101]: Privacy JSON content not t()-wrapped — instance-specific, maintained in JSON by operator
- [Phase 101]: Existing data-transparency details section kept alongside full PrivacyInfo disclosure
- [Phase 102]: Consent version in user_telos VARCHAR(20), re-consent via string inequality, v-html for static bundled JSON
- [Phase 103]: UserDeletedListener (Codex) registered in Application.php, covers 20+ tables with cascading delete + RAG anonymization
- [Phase 103]: Schwarm-Consent uses localStorage key learning_swarm_consent_v1, info dialog in StudentKnowledgeContribute.vue
- [Phase 104]: CourseSummaryService aggregiert 8 Datenkategorien (mastery, sessions, xp, badges, streak, swarm, duels, trouble_spots)
- [Phase 104]: Snapshot-Tabelle oc_learning_course_snapshots (JSON-Blob) fuer dauerhafte Zeugnisse
- [Phase 104]: SummaryController mit 3 Routes: GET summary, POST/GET snapshot. Student=eigene Daten, Instructor=Klasse

### Pending Todos

None yet.

### Blockers/Concerns

- Vue 2.7 Constraint bleibt — alle Aenderungen Vue 2 kompatibel
- App Store Release erst nach UX-Konsolidierung
- Phase 100 braucht Talk-Raum-IDs pro Kurs aus der DB — klaeren ob diese schon existieren

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 1 | CompTIA A+ Vault als RAG-Quelle importieren via OCC-Command | 2026-03-28 | 4f10f91 | [1-comptia-a-vault-als-rag-quelle-importier](./quick/1-comptia-a-vault-als-rag-quelle-importier/) |
| 2 | RAG-Transparenz: Quellen unter VirtuProf-Antworten anzeigen | 2026-03-28 | 148494b | [01-rag-transparenz](./quick/01-rag-transparenz/) |
| 3 | VirtuProf Sprach-Toggles: Dead Code entfernen | 2026-03-28 | a564c4a | [02-virtuprof-sprach-toggles](./quick/02-virtuprof-sprach-toggles/) |
| 4 | VirtuProf Guide-Texte: Deutsche Uebersetzungen | 2026-03-28 | 3aa4e13 | [03-virtuprof-guide-texte-l10n](./quick/03-virtuprof-guide-texte-l10n/) |
| 5 | Dozenten-UI Wissens-Import | 2026-03-28 | 206646d | [05-dozenten-ui-wissens-import](./quick/05-dozenten-ui-wissens-import/) |

## Performance Metrics

- v13.0: 6 phases, 12 plans, 19 feature commits
- Test coverage: 615 Vitest (40 suites), PHPStan Level 5, 67 Playwright Checks

## Session Continuity

Last session: 2026-03-29T08:30:00Z
Stopped at: Completed 0102-01-PLAN.md
Resume file: None
Next action: Phase 105 (Kursende-Frontend) — CourseSummary.vue bauen.
