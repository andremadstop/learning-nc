# Roadmap: Learning-NC

## Milestones

- ✅ **v2.3 PBQ OnVUE-Niveau Upgrade** — Phases 1-6 (shipped 2026-03-17)
- ✅ **v2.6 Live-Duell** — Phase 7 (shipped 2026-03-18)
- ✅ **v3.0 Gameshow-Modi** — Phases 8-13 (shipped 2026-03-20)
- ✅ **v3.1 UX-Konsolidierung** — Phases 14-16 (shipped 2026-03-21)
- ✅ **v3.2 VirtuProf KI-Assistent** — Phases 17-21 (shipped 2026-03-21)
- ✅ **v4.0 Persoenlicher Lernbot** — Phases 22-27 (shipped 2026-03-21)
- ✅ **v5.0 Oldschool (Brettspiel-Modi)** — Phases 28-31 (shipped 2026-03-21)
- ✅ **v6.0 Abenteuer (Story-RPG)** — Phases 32-35 (shipped 2026-03-22)
- ✅ **v4.1 RAG Stufe 2** — Phases 36-39 (shipped 2026-03-22)
- ✅ **v6.1 KI-Erzaehler + Security-Kampagnen** — Phases 40-43 (shipped 2026-03-22)
- ✅ **v6.2 Visual Identity + Charakter-Cast** — Phases 44-47 (shipped 2026-03-23)
- ✅ **v7.0 Hacker-Zeitreise "Hack Through Time"** — Phases 48-51 (shipped 2026-03-23)
- ✅ **v4.0 Housekeeping + Content-Rollout** — Phases 52-55 (shipped 2026-03-24)
- ✅ **v7.2 Subnetzrechner Pro** — Phases 56-60 (shipped 2026-03-24)
- ✅ **v8.0 VirtuProf v2** — Phases 61-63 (shipped 2026-03-24)
- ✅ **v9.0 Simulator-Werkzeuge** — Phases 64-70 (shipped 2026-03-24)
- ✅ **v10.0 Campaign Engine v2** — Phases 71-74 (shipped 2026-03-24)
- ✅ **v11.0 Telos-Onboarding + VirtuProf Guide** — Phases 75-79 (shipped 2026-03-25)
- ✅ **v12.0 Campaign Engine — Interaktives Kampagnen-RPG** — Phases 80-85 (shipped 2026-03-26)
- ✅ **v12.1 DevCloud Optimierung** — Phases 86-89 (shipped 2026-03-27)
- ✅ **v13.0 Feature Expansion** — Phases 90-95 (shipped 2026-03-28)
- ✅ **v3.4.0 UX-Konsolidierung & Simulator-Upgrade** — Phases 96-100 (shipped 2026-03-28)
- ✅ **v3.5.0 Transparenz & Kursabschluss** — Phases 101-109 (shipped 2026-03-29)
- **v3.6.0 Architect's Ascent** — Phases 110-113 (in progress)

## Phases

<details>
<summary>v2.3 — v12.1 (Phases 1-89) — SHIPPED</summary>

Phases 1-89 shipped across milestones v2.3 through v12.1. See git history for details.

</details>

<details>
<summary>v13.0 Feature Expansion (Phases 90-95) — SHIPPED 2026-03-28</summary>

- [x] Phase 90: NOVA Character Bible (1/1 plans) — completed 2026-03-27
- [x] Phase 91: NOVA Visual Implementation (4/4 plans) — completed 2026-03-27
- [x] Phase 92: Ghostline Quest (2/2 plans) — completed 2026-03-27
- [x] Phase 93: Vue 3 Migration Evaluation (1/1 plan) — completed 2026-03-28
- [x] Phase 94: Kurs-Feed (2/2 plans) — completed 2026-03-28
- [x] Phase 95: Skill-Map (2/2 plans) — completed 2026-03-28

</details>

<details>
<summary>v3.4.0 UX-Konsolidierung & Simulator-Upgrade (Phases 96-100) — SHIPPED 2026-03-28</summary>

- [x] Phase 96: UX-Navigation Struktur — completed 2026-03-28
- [x] Phase 97: Code-Hygiene & Settings — completed 2026-03-28
- [x] Phase 98: Simulator-Praxis-Sessions — completed 2026-03-28
- [x] Phase 99: Student-Dashboard — completed 2026-03-28
- [x] Phase 100: DevCloud-Integration & Leitner — completed 2026-03-28

</details>

<details>
<summary>v3.5.0 Transparenz & Kursabschluss (Phases 101-109) — SHIPPED 2026-03-29</summary>

- [x] Phase 101: In-App Datenschutz-Seite — completed 2026-03-29
- [x] Phase 102: AI Consent erweitern — completed 2026-03-29
- [x] Phase 103: Schwarm-Consent + Loeschkonzept — completed 2026-03-29
- [x] Phase 104: Summary-Backend — completed 2026-03-29
- [x] Phase 105: Kursende-Frontend — completed 2026-03-29
- [x] Phase 106: Export — completed 2026-03-29
- [x] Phase 107: Dozenten-Abschlussreport — completed 2026-03-29
- [x] Phase 108: Klassenbuch Opt-in — completed 2026-03-29
- [x] Phase 109: Kontakt-Features — completed 2026-03-29

</details>

### v3.6.0 Architect's Ascent (In Progress)

**Milestone Goal:** Security hardening (encryption at rest, audit trail), badge system overhaul, content pipeline (vault import), UX simplification (tab reduction), and AI-powered post-course experience (narrative portfolio, spaced repetition calendar).

- [x] **Phase 110: Foundation & Security** - Telos encryption, audit-log moderation, Gemini-outputs merge (privacy-info, PWA, badge l10n) (completed 2026-03-29)
- [ ] **Phase 111: Badge-Umbau & Vault-Import** - Legacy badge migration, new triggers, CompTIA vault import pipeline
- [ ] **Phase 112: Tab-Reduktion** - CourseDetail.vue decomposition from 16 tabs to 5 mega-tabs
- [ ] **Phase 113: AI & Erklaerbot** - Fullscreen Erklaerbot, dismissal UX, narrative portfolio, Forget-Me-Not ICS feed

## Phase Details

### Phase 110: Foundation & Security
**Goal**: Sensitive user data is encrypted at rest, moderation actions are auditable, and Gemini-generated content (privacy info, PWA guide, badge l10n) is integrated
**Depends on**: Nothing (first phase of v3.6.0)
**Requirements**: SEC-01, SEC-02, IMPORT-03, IMPORT-04, UX-04
**Success Criteria** (what must be TRUE):
  1. User's bio and telos_json fields are stored encrypted in the database (help_offer/help_wanted remain plaintext for buddy-matching SQL queries)
  2. When an instructor approves or rejects a swarm contribution, the action appears in audit_events with user_id, timestamp, and action type
  3. Privacy info page renders all 7 data categories from privacy-info.json (learning, ai, social, audit, gamification, assessment, external)
  4. PWA installation guide for iOS and Android is accessible as a course material document in DevCloud
  5. All 9 new badges display correct German and English names and descriptions
**Plans**: 2 plans

Plans:
- [ ] 110-01: Telos Encryption + Audit-Log Moderation (SEC-01, SEC-02)
- [ ] 110-02: Gemini-Outputs Integration (IMPORT-03, IMPORT-04, UX-04)

### Phase 111: Badge-Umbau & Vault-Import
**Goal**: Badge system is modernized (17 legacy badges archived, 5 new triggers active) and 4 CompTIA course vaults are importable as RAG sources
**Depends on**: Phase 110 (badge l10n strings from UX-04)
**Requirements**: BADGE-01, BADGE-02, IMPORT-01, IMPORT-02
**Success Criteria** (what must be TRUE):
  1. Database has is_legacy column; 17 old badges are flagged as legacy (not deleted, not awarded going forward)
  2. Five new badge triggers fire correctly: weekend learner, swarm contributor, simulator user, trouble fixer, quick thinker
  3. Running `occ learning:import-vault` imports Network+, Security+, Linux+, and CySA+ content as RAG sources
  4. Running the import command with --dry-run flag shows a preview of what would be imported without writing to the database
**Plans**: 2 plans

Plans:
- [ ] 111-01-PLAN.md — Add quick_thinker badge + apply is_legacy migration + wire 5 new badge triggers (BADGE-01, BADGE-02)
- [ ] 111-02-PLAN.md — Copy 4 CompTIA vaults to container and import as RAG chunks, verify --dry-run (IMPORT-01, IMPORT-02)

### Phase 112: Tab-Reduktion
**Goal**: Instructor course view is simplified from 16 individual tabs to 5 coherent mega-tabs, making CourseDetail.vue maintainable for future features
**Depends on**: Phase 111 (badge display changes settle before UI restructure)
**Requirements**: UX-01
**Success Criteria** (what must be TRUE):
  1. Instructor sees exactly 5 top-level tabs in CourseDetail: Lernraum, Teilnehmer, Wettbewerb, Kommunikation, Verwaltung
  2. All 16 original sub-views are accessible within their respective mega-tab (no functionality lost)
  3. CourseDetail.vue is decomposed into separate tab components (no longer a 1500+ line monolith)
**Plans**: TBD

Plans:
- [ ] 112-01: CourseDetail.vue Decomposition + Mega-Tab Structure (UX-01)

### Phase 113: AI & Erklaerbot
**Goal**: Students have a fullscreen AI learning companion, intuitive dismissal gestures, a personalized course-end reflection, and a calendar feed for post-course spaced repetition
**Depends on**: Phase 112 (clean tab structure for Erklaerbot integration)
**Requirements**: UX-02, UX-03, AI-01, AI-02
**Success Criteria** (what must be TRUE):
  1. Student can open VirtuProf as a fullscreen learning helper via a dedicated top-level tab (not only sidebar)
  2. User can close the Erklaerbot overlay with a single X-button click or swipe gesture (no nested menu navigation required)
  3. At course end, student receives a Gemini-generated reflection summarizing their personal learning journey with a next-step recommendation
  4. After course completion, student can subscribe to a token-based ICS calendar URL that contains individual VEVENTs for each due Leitner repetition date
**Plans**: TBD

Plans:
- [ ] 113-01: Erklaerbot Fullscreen + Dismissal UX (UX-02, UX-03)
- [ ] 113-02: Narrative Portfolio + Forget-Me-Not ICS (AI-01, AI-02)

## Progress Table

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1-89 | v2.3-v12.1 | - | Complete | 2026-03-17 — 2026-03-27 |
| 90-95 | v13.0 | - | Complete | 2026-03-28 |
| 96-100 | v3.4.0 | - | Complete | 2026-03-28 |
| 101-109 | v3.5.0 | - | Complete | 2026-03-29 |
| 110. Foundation & Security | 2/2 | Complete    | 2026-03-29 | - |
| 111. Badge-Umbau & Vault-Import | v3.6.0 | 0/2 | Not started | - |
| 112. Tab-Reduktion | v3.6.0 | 0/1 | Not started | - |
| 113. AI & Erklaerbot | v3.6.0 | 0/2 | Not started | - |
