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
- ✅ **v3.6.0 Architect's Ascent** — Phases 110-113 (shipped 2026-03-30)
- ✅ **v3.7.0 Efficiency & Compliance** — Phases 114-118 (shipped 2026-04-02)
- ✅ **v3.8.0 Architecture & Polish** — Phases 119-124 (shipped 2026-04-02)
- ✅ **v3.9.0 Precision Learning** — Phases 125-129 (shipped 2026-04-03)
- ✅ **v4.0.0 Vue 3 Migration** — Phases 130-134 (shipped 2026-04-03)
- ✅ **v4.1.0 Social Learning & Consolidation** — Phases 135-143 (shipped 2026-04-04)
- ✅ **v4.2.0 Lehrplan-Timeline + Admin-Werkzeuge** — Phases 144-148 (shipped 2026-04-08)
- **v4.4.0 Character & Personality** — Phases 149-153 (planned, ships before v4.3.0)
- **v4.3.0 Onboarding & Content Intelligence** — Phases 154-157 (planned, ships after v4.4.0)
- **v5.0.0 Universal Learning Platform** — Phases 158+ (vision)

## v4.4.0 — Character & Personality

> **Ziel:** VirtuProf bekommt ein Gesicht — weg von der futuristischen Box (NovaDock), zurück zu figürlicher Tiefe und Persönlichkeit. User können das Erscheinungsbild anpassen inkl. Archetype-Presets (Theoretiker / Kosmologe / Astrophysik-Popularisierer) und wiederbelebter Prof. Lern Classic.
>
> **Product Decisions:** Archetype-Naming (keine realen Namen, App-Store-sicher), externe Sensitivity-Review vor Phase 152 Freeze (~€300), Zero-Change-Default für Bestandsuser (NOVA bleibt), neue User starten mit Prof. Lern Classic. Keine neuen npm-Dependencies.

### Phases

- [x] **Phase 149: Legal, Art Direction & Copy Guidelines** — LEGAL.md v1.0 (Trademark-Analyse), Style-Guide v2.0 (Comic-Superhero-Pivot), CHANGELOG-Draft Archetype-Labels, Grep-CI Forbidden-Names, Internal-Sensitivity-Sign-off statt extern (3/3 Archetypen ✅ 2026-04-19) — completed 2026-04-24
- [x] **Phase 150: Animation Architecture & A11y Primitive** — Shared CSS `@keyframes` + WAAPI-Helpers mit `prefers-reduced-motion` + named SVG `<g>` + `transform-box: fill-box` + screen-reader-sichere Semantik (gates HIGH #4, #5, #9, #12) (completed 2026-04-25)
- [x] **Phase 151: Skin Picker Framework & Prof. Lern Classic** — SkinRenderer-Dispatcher, SkinPicker-Komponente, Pinia-Store + NC user_config-Persistierung, Git-Restore ProfLernAvatar.vue + Vue 3 Composition API Migration, Meta-Schema-Extension (gates MEDIUM #10, #11) (completed 2026-04-25)
- [x] **Phase 152: Three Archetype Presets** — Theoretiker + Kosmologe + Popularisierer als SVG-Silhouetten parallel, characters.js-Einträge, je ≥3 Animationen (idle/blink, wave, celebrate); Sensitivity-Sign-off vor Freeze (gates HIGH #7, #8, #15) (completed 2026-04-25)
- [x] **Phase 153: Migration, Tests, Deploy & App Store** — Zero-Change-Default-Migration, One-time-Hinweis, Vitest + Playwright (animations disabled), PHPStan, i18n-5-Sprachen-Parität, DevCloud-Test, stale-JS-chunk-Cleanup, signature.json re-sign, App-Store-Push (gates MEDIUM #6, #13, #14, #17) (completed 2026-04-27 — v4.4.0 LIVE on apps.nextcloud.com)

## v4.3.0 — Onboarding & Content Intelligence

| Phase | Titel | Scope | Aufwand |
|-------|-------|-------|---------|
| 154 | Onboarding Redesign (Option B) | 2-Ebenen Fullscreen: Splash→Rolle→Tour→Datenschutz→Profil-Kacheln→Content-Jumpstart→Hook | 2-3 Tage |
| 155 | Material → Pool Generator (3 Modi) | Drei Wege zum Pool: Gemini Cloud, Lokal Ollama, Manuell CSV/JSON. PoolDraftReview.vue | 3-4 Tage |
| 156 | NOVA Sprachausgabe (Gemini TTS) | Vorlese-Button, 30 HD-Stimmen, Browser SpeechSynthesis Fallback | 2-3 Tage |
| 157 | Video/Audio → Pool Generator | YouTube-URL + Audio-Upload, Gemini Video API + Whisper ASR | 2-3 Tage |

## v5.0.0 — Universal Learning Platform

> Ziel: Von IT-Drill-Tool zur Lernplattform fuer ALLE Faecher. Schulen, Unis, Fahrschulen, Sprachkurse, Medizin, Jura, Handwerk.
> Kerngedanke: Der universelle Kern (FSRS, Gamification, VirtuProf, Datensouveraenitaet) funktioniert bereits fachunabhaengig. v5.0 entfernt die IT-Scheuklappen und oeffnet die Plattform.

### Primaer-Richtung: Universelle Bildung

| Feature | Was | Warum |
|---------|-----|-------|
| **Generischer PBQ-Baukasten** | Dozenten laden eigene Bilder hoch (Anatomie, Landkarten, Motoren, Strassenszenen) und setzen Hotspots/Dropdowns darauf | Macht interaktive Aufgaben fuer jedes Fach moeglich — nicht nur Netzwerk-Topologien |
| **Audio-Karteikarten** | Frage/Antwort mit Audio-Aufnahme oder TTS. Hoerverstaendnis, Aussprache, Diktate | Oeffnet Sprachen als groesste Einzelzielgruppe (Englisch, Franzoesisch, Spanisch, DaF) |
| **Federated Content Hub** | Lehrer teilen kuratierte Fragenpools ueber NC-Instanzen (Federated Cloud Sharing) | "Grundwortschatz Franz. B1" oder "Anatomie Semester 1" mit einem Klick abonnieren |
| **Visual Themes** | Umschaltbare Skins: "Clean/Modern" (Default), "Schulkreide", "Labor/Medizin", "Paper & Circuits" (IT) | Neutralisiert IT-Aesthetik, jede Schule waehlt passenden Look |
| **Kampagnen-Templates** | Story-Engine fuer beliebige Faecher: Geschichts-Szenarien, Medizin-Faelle, Sprachreisen, Rechts-Faelle | Gleiche Engine, andere Inhalte — Dozenten erstellen eigene Kampagnen |
| **Starter-Pools breit** | Vorinstallierte Pools: Sprachen (EN/FR/ES Grundwortschatz), Medizin (Anatomie Basics), Jura (BGB Definitionen), Fahrschule (Verkehrsregeln), IT (CompTIA) | Sofort-Nutzen nach Installation, egal welches Fach |

### IT-Vertical (bestehende Staerken behalten)

| Feature | Was |
|---------|-----|
| **Coop-Kampagnen** | 3-5 Studenten loesen IT-Vorfaelle gemeinsam (SysAdmin fixt, Architect plant) |
| **Certification-as-a-Service** | PBQ-Exams als Zertifizierungstool mit manipulationssicherem Audit-Log |
| **IT-Simulatoren** | 9 Simulatoren bleiben als kursweise aktivierbares IT-Modul (via tool_config) |

### Deep Nextcloud Integration

| Feature | Was |
|---------|-----|
| **NC Assistant Provider** | VirtuProf als Provider fuer den NC AI Assistant — "Welche Fragen hab ich falsch?", "Erstelle Pool zu OSI Layer 3", "Wie bereit bin ich fuer die Pruefung?" direkt im NC-Copilot |
| **Unified Search** | Lernkarten + Pools ueber NC globale Suche findbar (aus v4.1 Phase 140) |
| **Deck-Automation** | FSRS erkennt Luecke → erstellt Aufgabe in Nextcloud Deck |
| **Talk-Trigger** | Mehrere Studenten am selben Thema schwach → automatische Lernsession in NC Talk |
| **Predictive Career Intelligence** | KI-Kompetenzanalyse, Arbeitgeber-taugliches Portfolio |

### Was sich aendert gegenueber v4.x

| Bereich | v4.x (IT-fokussiert) | v5.0 (universal) |
|---------|---------------------|-------------------|
| PBQ | Feste IT-Topologien | Eigene Bilder + Hotspots |
| Kampagnen | Nur IT-Incidents | Beliebige Fach-Szenarien |
| Content | Manueller Import + IT-Starter | Federated Hub + Multi-Fach-Starter |
| Audio | Keins | Karteikarten mit Audio |
| Look | "Paper & Circuits" | Waehlbare Themes |
| Simulatoren | Global sichtbar | Kursweise ein/ausblendbar (IT-Vertical) |
| Jargon | CLI, RAG, Curriculum-Scope | Allgemeinverstaendliche Begriffe |

## v4.1.0 — Social Learning & Consolidation

> Ziel: Von Feature-Sammlung zur sozialen Lern-Plattform. 7-Perspektiven-Review als Basis.

| Phase | Titel | Scope | Aufwand | Codex? |
|-------|-------|-------|---------|--------|
| 135 | Klassenbuch / Squad Identity | Opt-in Profile, Kudos, Squad-Grid, Buddy-Matching | Mittel | Ja |
| 136 | Predictive Exam Readiness | FSRS-basierte Bestehenswahrscheinlichkeit im Dashboard | Mittel | Ja |
| 137 | Simulatoren in Kurse einbetten | Tools aus globalem Tab in Kurs-Lernraum verschieben, Dozenten-Gating | Mittel | Ja |
| 138 | Vollstaendiger Datenexport (Art. 20) | Telos + Lernhistorie + Stats als JSON-Export | Klein | Ja |
| 139 | PII-Filter Schwarmgedaechtnis | Regex + Hinweis bei Moderation auf Klarnamen/PII im Freitext | Klein | Ja |
| 140 | Unified Search Integration | Lernkarten + Pools ueber NC Unified Search findbar | Mittel | Ja |
| 141 | Local-First KI (Ollama/NC AI Hub) | VirtuProf + Fragengenerierung mit lokalem LLM als Alternative zu Gemini | Gross | Teilweise |
| 142 | Content-Pools / Community Hub | Vorinstallierte Beispiel-Pools fuer CompTIA, Cisco, AWS | Mittel | Ja |
| 143 | TypeScript Einfuehrung | Schrittweise TS-Migration ab PBQ-Config + FSRS-Logik | Gross | Ja |

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

<details>
<summary>v3.6.0 Architect's Ascent (Phases 110-113) — SHIPPED 2026-03-30</summary>

- [x] **Phase 110: Foundation & Security** - Telos encryption, audit-log moderation, Gemini-outputs merge (privacy-info, PWA, badge l10n) (completed 2026-03-29)
- [x] **Phase 111: Badge-Umbau & Vault-Import** - Legacy badge migration, new triggers, CompTIA vault import pipeline (completed 2026-03-29)
- [x] **Phase 112: Tab-Reduktion** - CourseDetail.vue decomposition from 16 tabs to 5 mega-tabs (completed 2026-03-29)
- [x] **Phase 113: AI & Erklaerbot** - Fullscreen Erklaerbot, dismissal UX, narrative portfolio, Forget-Me-Not ICS feed (completed 2026-03-30)

</details>

### v3.7.0 Efficiency & Compliance (Phases 114-117)

**Milestone Goal:** Simplify the learner UX (mode visibility, smart queue, mode_config), eliminate the redundant true_false pool type, wire DSGVO help links into NC settings, and add an exam countdown dashboard widget.

- [x] **Phase 114: UX-Modus-Steuerung** - Mode visibility per role, Smart Queue as Lernraum entry point, mode_config for instructors (completed 2026-03-30)
- [x] **Phase 115: Wahr/Falsch-Migration** - Unify true_false into single-choice type, DB migration for existing pools (completed 2026-03-30)
- [x] **Phase 116: DSGVO Help-Seite** - NC Help & Privacy link, privacy-info page with 7 categories, Impressum via NC settings (completed 2026-03-30)
- [x] **Phase 117: Dashboard Prüfungstermin** - exam_date field + API, NC Dashboard Widget with countdown (completed 2026-04-02)
- [x] **Phase 118: PBQ-Feedback & Badge-Korrektur** - CLI-Terminal-Feedback in PBQ-Simulatoren, streak_30→streak_14 Migration, Placement SVG Fallback (completed 2026-04-02)

### v3.8.0 Architecture & Polish (Phases 119-124)

**Milestone Goal:** Prepare the codebase for Vue 3 migration (Pinia, Vue Router), eliminate performance bottlenecks (SSE, Redis), add mobile engagement (Push-Notifications), and polish remaining rough edges (Content Audit). After this milestone, all 7 perspectives from the feedback round are satisfied.

- [x] **Phase 119: SSE Real-time Engine** - Replace 500ms short-polling with Server-Sent Events for DuelMode/GameshowMode. Shared sse-client.js with auto-reconnect + polling fallback. (completed 2026-04-02)
- [x] **Phase 120: Event-Bus → Pinia** - Replaced 57x $root.$emit/$on/$off with Pinia 2.3.1 stores (virtuProfStore, courseStore). Zero event-bus remaining. (completed 2026-04-02)
- [x] **Phase 121: Vue Router** - vue-router@3 for top-level navigation + CourseDetail mega-tabs. Deep-linking enabled. (completed 2026-04-02)
- [x] **Phase 122: Redis Cache Option** - ICache usage normalized to createDistributed('learning'). Redis-ready when configured in NC config.php. (completed 2026-04-02)
- [x] **Phase 123: Push-Notifications** - NC Notification API: due cards (09:00), streak warnings (20:00), exam reminders (7d/3d/1d). SendRemindersJob registered. (completed 2026-04-02)
- [x] **Phase 124: Content Audit** - 17 Umlaut fixes, 1230 lines Zeitreise dead code removed (routes, controller, service, CSS). (completed 2026-04-02)

### v3.9.0 Precision Learning (Phases 125-129)

**Milestone Goal:** Replace fixed Leitner intervals with FSRS adaptive algorithm. Each card gets individual stability/difficulty tracking. UX uses Confidence-Based approach (3 buttons: Nochmal/Schwer/Einfach) as default, full stats as opt-in toggle. Includes A11Y polish, dashboard performance, and App Store release.

- [ ] **Phase 125: FSRS Engine** - FSRS algorithm in PHP, DB migration (stability/difficulty/retrievability columns), Box→FSRS backfill for existing users, Smart Queue sorted by retrievability
- [ ] **Phase 126: FSRS UI — Confidence Buttons** - 3 rating buttons (Nochmal/Schwer/Einfach) as default, "Wiederholung in X Tagen" feedback, VirtuProf explains the change, Onboarding slide, Settings toggle for Architect View (4 buttons + full stats)
- [ ] **Phase 127: A11Y & UX Polish** - Timer text indicators beside color (WCAG 1.4.1), focus-trap in dialogs, FSRS buttons accessible
- [ ] **Phase 128: Dashboard & Performance** - Dozenten-Aggregation optimized for FSRS data, DB indexes on badge/stats tables, materialized stats for 100+ users
- [ ] **Phase 129: App Shell & Release** - router-view in App.vue, test-api.sh modernized, App Store v3.9.0 signing + upload

### v4.4.0 Character & Personality (Phases 149-153)

**Milestone Goal:** VirtuProf bekommt ein Gesicht. User können zwischen NOVA (Default für Bestandsuser), Prof. Lern Classic (Default für neue User) und drei Archetype-Presets (Theoretiker / Kosmologe / Popularisierer) wählen. Shared Animation-Engine mit WCAG 2.3.3 und Sensitivity-Review für Darstellung.

- [ ] **Phase 149: Legal, Art Direction & Copy Guidelines** - Archetype-Naming-Entscheidung dokumentiert, Sensitivity-Reviewer beauftragt, Style-Guide für Chibi-vs-Semi-Realistic, CI-Grep-Check gegen verbotene Eigennamen (LEGAL-01..04, MIGR-04)
- [ ] **Phase 150: Animation Architecture & A11y Primitive** - character-animations.css + .js, character-reaction-engine.js (generalisiert aus Nova), SVG `<g>`-Sub-Groups mit `transform-box: fill-box`, `prefers-reduced-motion` von Tag 1 gated, A11y-Semantik (ANIM-01..04, A11Y-01..05)
- [ ] **Phase 151: Skin Picker Framework & Prof. Lern Classic** - SkinRenderer.vue Dispatcher ersetzt NovaDock-Hardcode, SkinPicker.vue in PersonalSettings, Pinia-Store + NC user_config-Persistierung, ProfLernAvatar.vue aus v2.6.1 Git restored + Vue 3 migriert, Meta-Schema-Extension (PICK-01..05, CLASSIC-01..04, META-01..03)
- [x] **Phase 152: Three Archetype Presets** - SVG-Silhouetten für Theoretiker + Kosmologe + Popularisierer parallel, characters.js-Einträge, je ≥3 Animationen (idle/blink, wave, celebrate), Sensitivity-Sign-off vor Freeze, ANIM-05 Animation-Coverage je Skin (SCHOLAR-01..04, ANIM-05) (completed 2026-04-25)
- [ ] **Phase 153: Migration, Tests, Deploy & App Store** - Zero-Change-Default-Migration, One-time-In-App-Hinweis, Vitest (SkinRenderer + resolveReaction + 4 Avatar-Snapshots), Playwright E2E mit `animations: 'disabled'`, manueller A11y-Audit (prefers-reduced-motion + Screen-Reader + RTL + Keyboard), i18n-5-Sprachen-Parität + CI-Key-Check, DevCloud-Test (Kurs 21 + ernesst), stale-JS-chunk-Cleanup, signature.json re-sign, App-Store-Push (MIGR-01..05, TEST-01..06, I18N-01..03)

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
- [x] 110-01: Telos Encryption + Audit-Log Moderation (SEC-01, SEC-02)
- [x] 110-02: Gemini-Outputs Integration (IMPORT-03, IMPORT-04, UX-04)

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
- [x] 111-01-PLAN.md — Add quick_thinker badge + apply is_legacy migration + wire 5 new badge triggers (BADGE-01, BADGE-02)
- [x] 111-02-PLAN.md — Copy 4 CompTIA vaults to container and import as RAG chunks, verify --dry-run (IMPORT-01, IMPORT-02)

### Phase 112: Tab-Reduktion
**Goal**: Instructor course view is simplified from 16 individual tabs to 5 coherent mega-tabs, making CourseDetail.vue maintainable for future features
**Depends on**: Phase 111 (badge display changes settle before UI restructure)
**Requirements**: UX-01
**Success Criteria** (what must be TRUE):
  1. Instructor sees exactly 5 top-level tabs in CourseDetail: Lernraum, Teilnehmer, Wettbewerb, Kommunikation, Verwaltung
  2. All 16 original sub-views are accessible within their respective mega-tab (no functionality lost)
  3. CourseDetail.vue is decomposed into separate tab components (no longer a 3874-line monolith)
**Plans**: 3 plans

Plans:
- [x] 112-01-PLAN.md — Extract Kommunikation + Verwaltung mega-tabs (lowest coupling, ~600-800 LOC reduction)
- [x] 112-02-PLAN.md — Extract Wettbewerb + Teilnehmer mega-tabs (~1200-1500 LOC reduction, shell complete)
- [x] 112-03-PLAN.md — Convert flat tab-selector to 5 mega-tab navigation + fix App.vue view-key gaps

### Phase 113: AI & Erklaerbot
**Goal**: Students have a fullscreen AI learning companion, intuitive dismissal gestures, a personalized course-end reflection, and a calendar feed for post-course spaced repetition
**Depends on**: Phase 112 (clean tab structure for Erklaerbot integration)
**Requirements**: UX-02, UX-03, AI-01, AI-02
**Success Criteria** (what must be TRUE):
  1. Student can open VirtuProf as a fullscreen learning helper via a dedicated top-level tab (not only sidebar)
  2. User can close the Erklaerbot overlay with a single X-button click or swipe gesture (no nested menu navigation required)
  3. At course end, student receives a Gemini-generated reflection summarizing their personal learning journey with a next-step recommendation
  4. After course completion, student can subscribe to a token-based ICS calendar URL that contains individual VEVENTs for each due Leitner repetition date
**Plans**: 2 plans

Plans:
- [x] 113-01-PLAN.md — Erklaerbot Fullscreen + Dismissal UX (UX-02, UX-03)
- [x] 113-02-PLAN.md — Narrative Portfolio + Forget-Me-Not ICS (AI-01, AI-02)

### Phase 114: UX-Modus-Steuerung
**Goal**: Learners see only modes relevant to them, Smart Queue is the prominent entry point into the Lernraum, and instructors can configure which modes are active per course
**Depends on**: Nothing (first phase of v3.7.0)
**Requirements**: UX-01, UX-04, UX-05
**Success Criteria** (what must be TRUE):
  1. Student navigating to Lernraum does not see a Training-Modus tab or any link to it; instructor navigating to the same course sees Training-Modus as before
  2. Smart Queue appears as the primary Lernraum entry point and shows the count of cards due today before the user clicks anything
  3. Instructor can open a course mode_config panel and toggle individual modes (Training, Exam, etc.) on or off; changes persist across page reloads
  4. When an instructor disables a mode, students immediately lose access to that mode's tab/link
**Plans**: 2 plans

Plans:
- [x] 114-01-PLAN.md — Training tab visibility per role + mode_config toggle (UX-01, UX-05)
- [x] 114-02-PLAN.md — Smart Queue hero card in Lernraum student view (UX-04)

### Phase 115: Wahr/Falsch-Migration
**Goal**: The true_false pool type no longer exists; all existing true/false questions behave identically to single-choice questions with exactly two options
**Depends on**: Phase 114 (stable mode config before touching pool types)
**Requirements**: UX-02, UX-03
**Success Criteria** (what must be TRUE):
  1. No pool in the database has question_type = 'true_false' after migration runs; all former true_false pools have question_type = 'single'
  2. A learner answering a migrated Wahr/Falsch question sees exactly two answer options (Wahr, Falsch) rendered as single-choice buttons — no separate UI path
  3. Creating a new question in the editor no longer offers 'Wahr/Falsch' as a distinct type; the instructor uses single-choice with two options instead
  4. Migration is idempotent: running the DB migration a second time produces no errors and no data changes
**Plans**: 2 plans

Plans:
- [ ] 115-01-PLAN.md — Defensive DB migration (Version006300) + ImportController true_false guard (UX-03)
- [ ] 115-02-PLAN.md — Remove swipe WF UI from TrainingMode + CourseTabVerwaltung + VirtuProf cleanup + test fixes (UX-02)

### Phase 116: DSGVO Help-Seite
**Goal**: The Nextcloud instance's Help & Privacy page and legal notice link directly to the app's DSGVO content, satisfying NC platform compliance requirements
**Depends on**: Phase 114 (stable UX baseline before adding settings integrations)
**Requirements**: DSGVO-01, DSGVO-02, DSGVO-03
**Success Criteria** (what must be TRUE):
  1. Navigating to /settings/help in the NC instance shows a working Privacy Policy link that leads to the learning app's Datenschutzerklärung page
  2. The Datenschutzerklärung page displays all 7 categories from privacy-info.json (learning, ai, social, audit, gamification, assessment, external) with their full content
  3. A legal notice (Impressum) link is reachable from NC settings; clicking it shows the Impressum content
**Plans**: 1 plan

Plans:
- [ ] 116-01-PLAN.md — Fix tips-tricks section ID + deploy legal-link.php template + update build output (DSGVO-01, DSGVO-02, DSGVO-03)

### Phase 117: Dashboard Prüfungstermin
**Goal**: Instructors can record exam dates per course and students see a live countdown on the NC Dashboard, creating daily urgency without leaving Nextcloud
**Depends on**: Phase 114 (course config pattern established for exam_date field)
**Requirements**: DASH-01, DASH-02, DASH-03
**Success Criteria** (what must be TRUE):
  1. Instructor can open a course and set or clear an exam_date; the value persists after page reload
  2. A student enrolled in a course with an exam_date sees a countdown widget on the NC Dashboard showing the number of days remaining
  3. When no exam_date is set for any enrolled course, the Dashboard widget is hidden entirely (no empty card visible)
  4. When exam_date is today or in the past, the widget shows a suitable terminal state message rather than a negative countdown
**Plans**: TBD

### Phase 149: Legal, Art Direction & Copy Guidelines
**Goal**: Archetype-Naming is locked in writing, external sensitivity review is commissioned, and automated guardrails ensure no forbidden real-person names leak into code, i18n, or App Store assets
**Depends on**: Nothing (first phase of v4.4.0 — gates all art production)
**Requirements**: LEGAL-01, LEGAL-02, LEGAL-03, LEGAL-04, MIGR-04
**Success Criteria** (what must be TRUE):
  1. `.planning/LEGAL.md` documents the Archetype-Naming decision with rationale (App-Store-Safety, Hawking-Estate-Trademark, Tyson-right-of-publicity, Einstein-Hebrew-University-Trademark) and is committed to git
  2. A grep-based CI check (`scripts/check-forbidden-names.sh` or equivalent) fails the pipeline if "Einstein", "Hawking", "Tyson", "Neil deGrasse", "Cosmos", or "StarTalk" appears anywhere in `app/src/**`, `app/l10n/**`, `CHANGELOG.md`, or `appinfo/info.xml`
  3. At least one external sensitivity reviewer is commissioned in writing (~€300 budget confirmed) with a sign-off deadline before Phase 152 SVG-freeze
  4. CHANGELOG v4.4.0 draft entry uses archetype names ("Der Theoretiker" / "Der Kosmologe" / "Der Astrophysik-Popularisierer") and explicitly does NOT name the historical inspirations
  5. Style-Guide document specifies: Chibi for fictional Prof. Lern Classic only; semi-realistic illustration style for all three scholar archetypes; no animated wheelchair, no racially exaggerated features
**Plans**: TBD

### Phase 150: Animation Architecture & A11y Primitive
**Goal**: A shared animation engine exists that every skin can use; every animation respects `prefers-reduced-motion` from day one; avatars are screen-reader-friendly and free of memory leaks
**Depends on**: Phase 149 (style-guide must be locked before animation primitives are designed)
**Requirements**: ANIM-01, ANIM-02, ANIM-03, ANIM-04, A11Y-01, A11Y-02, A11Y-03, A11Y-04, A11Y-05
**Success Criteria** (what must be TRUE):
  1. `character-animations.css` provides shared `@keyframes` (blink, slight sway) all wrapped in `@media (prefers-reduced-motion: no-preference)`; enabling OS-level reduced-motion emulation in DevTools stops ALL avatar animation immediately with a static pose rendered
  2. `character-animations.js` exposes WAAPI helpers (`playWave`, `playCelebrate`, `playShrug`) each gated by `matchMedia('(prefers-reduced-motion: reduce)')`, returning instantly without side effects when reduced-motion is active
  3. `character-reaction-engine.js` is generalised from `nova-reaction-engine.js` and returns `{animation, emotion, sound, duration}` for each supported event; when a skin does not support the requested state it falls back to `idle` with no error
  4. Every animated SVG `<g>` uses named ids (`head`, `arms`, `body`) with `transform-box: fill-box` inline, verified via a snapshot-test that pivots correctly in Safari pre-16 emulation
  5. Avatar SVG root carries `role="img"` + a static i18n `aria-label` (never per-animation-state); NVDA/VoiceOver manual walkthrough confirms the avatar does not interrupt navigation and does not spam per-frame announcements
  6. PersonalSettings "Ruhige Darstellung"-Toggle overrides OS preference when enabled and takes effect without page reload; Keyboard-Navigation (Tab + Arrow + Enter) reaches every control with a visible `focus-visible` ring
**Plans**: TBD

### Phase 151: Skin Picker Framework & Prof. Lern Classic
**Goal**: Users can select their VirtuProf skin in PersonalSettings and the choice persists in NC user_config; the dispatcher picks the right avatar at runtime; Prof. Lern Classic is restored from git v2.6.1 and migrated to Vue 3 as the simplest proof-case of the picker framework
**Depends on**: Phase 150 (animation primitives must exist before Prof. Lern Classic can reuse them)
**Requirements**: PICK-01, PICK-02, PICK-03, PICK-04, PICK-05, CLASSIC-01, CLASSIC-02, CLASSIC-03, CLASSIC-04, META-01, META-02, META-03
**Success Criteria** (what must be TRUE):
  1. User opens PersonalSettings and sees a dropdown listing Nova + Prof. Lern Classic + three archetype-placeholders, filtered from `characters.js` by `user_selectable === true`; selecting any entry saves the value to NC user_config key `learning.virtuprof_skin` via `VirtuProfController` and persists across a hard reload
  2. VirtuProf.vue no longer hardcodes `<NovaDock>` — `SkinRenderer.vue` dispatches polymorphically (Nova → NovaDock, prof_lern_classic → ProfLernAvatar, else → CharacterAvatar with given id), and an invalid/removed skinId gracefully falls back to Nova without a runtime error
  3. Changing skin from Nova to Prof. Lern Classic mid-session updates the avatar instantly — no page reload — verified by `<SkinRenderer :key="skinPreference">` remount and Pinia `skinStore` as single source of truth (no stale `data()` copies)
  4. `ProfLernAvatar.vue` is restored from git tag v2.6.1, migrated to Vue 3 `<script setup>`, and renders the characteristic features: reading behind a book, gaze follows cursor on hover, arm-wave on click (auto-hides after 1.2s), question mark on body — each respecting `prefers-reduced-motion`
  5. `characters.js` is extended additively with three new fields (`user_selectable`, `category`, `preview_thumbnail_svg`); all 12 existing entries still load and render in Campaign code without any change to their call-sites (non-breaking default mechanism verified by vitest)
**Plans**: TBD

### Phase 152: Three Archetype Presets
**Goal**: The three scholar archetypes (Theoretiker, Kosmologe, Astrophysik-Popularisierer) exist as selectable skins with distinct silhouettes, palettes, and ≥3 animation states each; the internal sensitivity review (per Phase 149 pivot — owner-led 8-point checklist in ART_STYLE_GUIDE Section 5) is signed off post-art before any SVG is frozen
**Depends on**: Phase 151 (picker framework + CharacterAvatar extension + meta-schema must exist to host the new entries)
**Requirements**: SCHOLAR-01, SCHOLAR-02, SCHOLAR-03, SCHOLAR-04, ANIM-05
**Success Criteria** (what must be TRUE):
  1. Three new entries exist in `characters.js` (`theoretiker`, `kosmologe`, `popularisierer`) with complete meta-schema (id, name, role, personality, palette, silhouette key, states list, campaignAppearances, user_selectable=true, category='scholar', preview_thumbnail_svg) — naming uses archetypes exclusively, grep for forbidden real-person names returns zero hits
  2. CharacterAvatar.vue renders distinct SVG silhouettes for each archetype: Theoretiker (wild hair + mustache + cardigan-palette amber/muted), Kosmologe (glasses + understated wheelchair detail + blue-palette, drawn as full person first, mobility aid last, never animated), Popularisierer (goatee + vest + star-glow + magenta-violet-palette)
  3. Each of the four new avatars (Prof. Lern Classic from Phase 151 + three archetypes) supports ≥3 animations: idle/blink, wave, celebrate — verified by 12-case structural vitest matrix (`scholarAnimations.test.js`, 4 skins × 3 states; structural querySelector assertions per RESEARCH Pitfall 5, NO `toMatchSnapshot`)
  4. **Internal sensitivity review** (per Phase 149 pivot — replaces obsolete external Leidmedien.de plan) has appended one final-art sign-off row per archetype to `.planning/sensitivity-review/SIGNOFF.md` confirming the 8-point ART_STYLE_GUIDE Section 5 checklist (archetype-label only, Power-Element positiv konnotiert, palette matched, pose aktiv-heroisch, Universal No-Gos absent, SVG-security `scholarSvgSecurity.test.js` GREEN, reduced-motion fallback verified, aria-label statisch) — sign-off occurs AFTER deploy-prod and BEFORE Phase 152 closure
  5. Switching between all five skins (Nova, Prof. Lern, Theoretiker, Kosmologe, Popularisierer) in PersonalSettings produces correct reactive re-render without regression of previous skins
**Plans**: 5 plans

Plans:
- [ ] 152-02-PLAN.md — Add 3 scholar entries to characters.js (flips Codex Wave-0 RED tests GREEN)
- [ ] 152-03-PLAN.md — Theoretiker silhouette case + shared `<g id="powerEffect">` infrastructure
- [ ] 152-04-PLAN.md — Kosmologe silhouette (Power-First Drawing Order, seated body, thruster CSS keyframe with reduced-motion fallback)
- [ ] 152-05-PLAN.md — Popularisierer silhouette (kinnbart + vest + Kosmos-Projektion radialGradient)
- [ ] 152-06-PLAN.md — scholarAnimations.test.js (12-case matrix) + scholarSvgSecurity.test.js (zero-deps svgo replacement) + SIGNOFF.md final-art update + deploy + manual checkpoint (autonomous: false)

> **Wave structure:** Wave 0 already shipped via Codex (commits `7c726d7` + `c2aae01` — RED tests + CHARACTER_BIBLE scholar entries). Wave 1 = 152-02 (parallel-safe). Waves 2/3/4 = 152-03/04/05 SEQUENTIAL (file-conflict on CharacterAvatar.vue silhouette switch). Wave 5 = 152-06 closer (autonomous: false — manual sensitivity-review checkpoint).

### Phase 153: Migration, Tests, Deploy & App Store
**Goal**: Existing users remain on NOVA (zero-change default); new users get Prof. Lern Classic; all quality gates pass; v4.4.0 ships to the App Store with stale JS chunks cleaned and signature.json re-signed
**Depends on**: Phase 152 (all visual assets must be final and sensitivity-approved before signing)
**Requirements**: MIGR-01, MIGR-02, MIGR-03, MIGR-05, TEST-01, TEST-02, TEST-03, TEST-04, TEST-05, TEST-06, I18N-01, I18N-02, I18N-03
**Success Criteria** (what must be TRUE):
  1. Post-deploy verification on DevCloud Kurs 21 (15 students) confirms every existing user still sees NOVA (no silent migration) and a new test-account registered after deploy sees Prof. Lern Classic as default; a one-time in-app hint "Neue Skins verfügbar in den Einstellungen" appears and auto-dismisses after 7 days or user-click
  2. Vitest suite green: unit tests for `SkinRenderer` dispatch + fallback, unit tests for `resolveReaction()` fallback on unsupported state, snapshot tests for all four new avatars in every supported state (≥3 states × 4 skins = ≥12 snapshots); `CharacterAvatar.spec.ts` has ≤3 presentational smoke tests (no over-testing of Vue reactivity)
  3. Playwright E2E green with `animations: 'disabled'` flag: open PersonalSettings → select skin → VirtuProf updates without reload → log out and back in → selection persists; test runs 10 consecutive times in CI without flake
  4. Manual A11y audit documented and signed off: `prefers-reduced-motion` emulation stops all animations; NVDA + VoiceOver navigate without avatar interruption; Arabic RTL screenshot confirms avatar is NOT mirrored; keyboard-only navigation reaches every picker control
  5. i18n parity green across all five languages (DE/EN/FR/RU/AR): CI key-parity check passes for the new avatar-picker keys; Arabic RTL renders picker labels right-aligned with avatar unmirrored; tested with DevCloud external user ernesst (French) and Kurs 21 ADHS-user before App-Store-push
  6. Deploy script deletes old `js/*.js` and `js/*.css` chunks in container before rsync so hard-refresh of an older session does not 404; signature.json is re-signed AFTER final SVG assets land; App Store listing uses archetype names in all screenshots + description; CHANGELOG.md + info.xml version bumped to v4.4.0
**Plans**: 7 plans

Plans:
- [x] 153-01-PLAN.md — Wave 0: i18n parity script + pre-push/CI wireups + OQ4 grep verdict + Pitfall 3/7 audit greps (I18N-02) ✓ 2026-04-26
- [x] 153-02-PLAN.md — Wave 0: 5 test scaffolds (Vitest + PHPUnit + Playwright) + A11y audit doc + DevCloud creds verification (TEST-02, TEST-04, TEST-05, TEST-06, MIGR-03, MIGR-05) ✓ 2026-04-26
- [x] 153-03-PLAN.md — Wave 1: VirtuProfController.getSkin() rewrite with Pattern 1 first-touch-coercion + un-skip 3 PHPUnit tests + augment SkinRenderer.test.js +1 case (MIGR-01, MIGR-02, TEST-01, TEST-03) ✓ 2026-04-26
- [x] 153-04-PLAN.md — Wave 1: PersonalSettings.vue NcNoteCard hint + ~20 i18n keys in 5 langs lockstep (MIGR-03, I18N-01) ✓ 2026-04-26
- [x] 153-05-PLAN.md — Wave 2: un-skip Playwright spec + visual baseline + info.xml version bump + REQUIREMENTS.md cosmetic close-outs (TEST-04, TEST-05) ✓ 2026-04-26
- [x] 153-06-PLAN.md — Wave 3: A11y audit + RTL + MIGR-05 (scope-pivot to structural coverage + 4-account API smoke, user-approved; CP2 screen-reader deferred to post-merge spot-check) (TEST-06, I18N-03, MIGR-05) ✓ 2026-04-27
- [x] 153-07-PLAN.md — Wave 4: release ritual (CHANGELOG date lock + sign + signature.json commit + tag v4.4.0 + tarball + GitHub release + App Store API push HTTP 201 + state updates) ✓ 2026-04-27

## Progress Table

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1-89 | v2.3-v12.1 | - | Complete | 2026-03-17 — 2026-03-27 |
| 90-95 | v13.0 | - | Complete | 2026-03-28 |
| 96-100 | v3.4.0 | - | Complete | 2026-03-28 |
| 101-109 | v3.5.0 | - | Complete | 2026-03-29 |
| 110. Foundation & Security | v3.6.0 | 2/2 | Complete | 2026-03-29 |
| 111. Badge-Umbau & Vault-Import | v3.6.0 | 2/2 | Complete | 2026-03-29 |
| 112. Tab-Reduktion | v3.6.0 | 3/3 | Complete | 2026-03-30 |
| 113. AI & Erklaerbot | v3.6.0 | 2/2 | Complete | 2026-03-30 |
| 114. UX-Modus-Steuerung | v3.7.0 | 2/2 | Complete | 2026-03-30 |
| 115. Wahr/Falsch-Migration | v3.7.0 | 2/2 | Complete | 2026-03-30 |
| 116. DSGVO Help-Seite | v3.7.0 | 1/1 | Complete | 2026-03-30 |
| 117. Dashboard Prüfungstermin | v3.7.0 | 1/1 | Complete | 2026-04-02 |
| 118. PBQ-Feedback & Badges | v3.7.0 | 1/1 | Complete | 2026-04-02 |
| 119. SSE Real-time Engine | v3.8.0 | 1/1 | Complete | 2026-04-02 |
| 120. Event-Bus → Pinia | v3.8.0 | 1/1 | Complete | 2026-04-02 |
| 121. Vue Router | v3.8.0 | 1/1 | Complete | 2026-04-02 |
| 122. Redis Cache Option | v3.8.0 | 1/1 | Complete | 2026-04-02 |
| 123. Push-Notifications | v3.8.0 | 1/1 | Complete | 2026-04-02 |
| 124. Content Audit | v3.8.0 | 2/2 | Complete | 2026-04-02 |
| 149. Legal, Art Direction & Copy | v4.4.0 | 5/5 | Complete | 2026-04-24 |
| 150. Animation Architecture & A11y | v4.4.0 | Complete    | 2026-04-25 | — |
| 151. Skin Picker & Prof. Lern Classic | v4.4.0 | Complete    | 2026-04-25 | — |
| 152. Three Archetype Presets | v4.4.0 | Complete    | 2026-04-25 | 2026-04-25 |
| 153. Migration, Tests, Deploy & App Store | v4.4.0 | 5/7 | Wave 2 complete | — |
