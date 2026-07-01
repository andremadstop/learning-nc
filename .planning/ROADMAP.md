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
- ✅ **v4.4.0 Character & Personality** — Phases 149-153 (shipped 2026-04-27)
- ✅ **v5.0.0 Certification-as-a-Service** — Phases 154-157 (shipped 2026-06-28, tag v5.0.0) — [archive](milestones/v5.0.0-ROADMAP.md)
- 🚧 **v5.1.0 Ghostline** — Phases 158-159 (interaktives Story-Universum; Akt 1 LPIC-101 / K3-Vertical-Slice, Prüfung 03.07.)
- 🚧 **v5.2.0 Pflichtschulung** — Phases 160-164 (AWO-Readiness: Audit-Hash-Chain, Video-Gating, Teamleiter-RBAC, Re-Zertifizierung)
- 📋 **v4.3.0 Onboarding & Content Intelligence** — Phases TBD (deferred — after v5.0.0)
- 📋 **v6.0.0 Universal Learning Platform (Vision)** — Phases TBD

<details>
<summary>✅ <strong>v4.4.0 Character & Personality</strong> — Phases 149-153 — SHIPPED 2026-04-27 (App Store live)</summary>

> **Ziel:** VirtuProf bekommt ein Gesicht — Skin-Picker mit 5 wählbaren Skins (NOVA / Prof. Lern Classic / Theoretiker / Kosmologe / Astrophysik-Popularisierer), Zero-Change-Default für Bestandsuser, 0 neue npm-Dependencies, 5-Sprachen i18n-Parität.

### Phases

- [x] **Phase 149: Legal, Art Direction & Copy Guidelines** — LEGAL.md (Trademark-Analyse), ART_STYLE_GUIDE, forbidden-names CI, Internal-Sensitivity-Sign-off (5/5 Plans, 2026-04-24)
- [x] **Phase 150: Animation Architecture & A11y Primitive** — character-animations.css/js + character-reaction-engine + 3-layer reduced-motion gate + screen-reader-sichere Semantik (6/6 Plans, 2026-04-25)
- [x] **Phase 151: Skin Picker Framework & Prof. Lern Classic** — SkinRenderer-Dispatcher + Pinia skinStore + NC user_config-Persistierung + ProfLernAvatar Vue 3 Restore + Meta-Schema additiv (7/7 Plans, 2026-04-25)
- [x] **Phase 152: Three Archetype Presets** — Theoretiker + Kosmologe (Power-First Drawing Order) + Astrophysik-Popularisierer SVG-Silhouetten + Sensitivity-SIGNOFF (5/5 Plans, 2026-04-25)
- [x] **Phase 153: Migration, Tests, Deploy & App Store** — Pattern-1 first-touch-coercion + 19 i18n-Keys × 5 Langs + Playwright E2E + i18n-parity-CI + signed Tarball + App Store API Push HTTP 201 (7/7 Plans, 2026-04-27)

Archive: [v4.4.0-ROADMAP.md](milestones/v4.4.0-ROADMAP.md) · [v4.4.0-REQUIREMENTS.md](milestones/v4.4.0-REQUIREMENTS.md) · [v4.4.0-MILESTONE-AUDIT.md](milestones/v4.4.0-MILESTONE-AUDIT.md)

</details>

## v5.1.0 — Ghostline (Active)

> Verbindendes interaktives Story-Universum auf der vorhandenen Kampagnen-Engine. v1 = **Akt 1
> (LPIC-101)**, deadline-priorisiert (Prüfung 03.07.). K3/Topic-103 als Vertical Slice zuerst.
> Spec: `docs/superpowers/specs/2026-06-30-ghostline-interactive-course-design.md`. Research: `.planning/research/`.

**2 Phasen** | **16 v1-Requirements** | alle gemappt ✓

| # | Phase | Goal | Requirements |
|---|-------|------|--------------|
| 158 | Akt 1 — K3 Core (Authoring & Korrektheit) | Spielbarer, content-korrekter K3-Mini-Arc auf der Engine (unfeatured) | STORY-01..04, K3-01..04, TERM-01/02, CONT-01/02 |
| 159 | Akt 1 — Retention, Material & Go-Live | FSRS-Pool-Brücke + Material-Link + staged FEATURED-Go-Live nach Andrés Durchspielen | RET-01, MAT-01, DEPLOY-01/02 |

### Phase 158: Akt 1 — K3 Core (Authoring & Korrektheit)
**Depends on**: Nichts (erste Phase v5.1.0). Vorhandene Engine (Spec §3+9).
**Goal**: Eine solo spielbare, prüfungswirksame K3-Mini-Story (`ghostline_act1.json`) — content-korrekt, gegen Durchspielen-ohne-Lernen gesichert, lokal/devcloud unfeatured lauffähig.
**Requirements**: STORY-01, STORY-02, STORY-03, STORY-04, K3-01, K3-02, K3-03, K3-04, TERM-01, TERM-02, CONT-01, CONT-02
**Success criteria:**
1. `ghostline_act1.json` lädt fehlerfrei im Abenteuer-Modus (3-Node-Smoke grün → voller K3 ≈8 Nodes)
2. K3 solo end-to-end spielbar: Story-Intro → Terminal → Auflösung → Inline-Quiz → 2. Terminal (faded) → History-Vignette → Ende; `claimed_ghost_box` am Ende gesetzt
3. ≥2 Terminal-Challenges aus echten Dozenten-grep/sed-Aufgaben; je ≥3 Quote-Varianten + Hint; Outputs auf echter Shell erzeugt (nicht erfunden)
4. ≥1 Inline-Quiz mit `explanation`, Inhalt LPIC-verifiziert; prüfungskritische 103-Fallen eingebaut (umask, BRE/ERE, Redirect-Reihenfolge …)
5. Jede Kapitel-Abschluss-Kante hat `conditions.requires_flag` — kein Durchspielen mit Fehleingaben

**Plans:** 5 plans

Plans:
- [ ] 158-01-PLAN.md — Graph Validator + 3-Node Smoke (Wave 1: structural validator + Pattern B smoke)
- [ ] 158-02-PLAN.md — Full K3 Arc Authoring (Wave 2: 9-node story arc, quiz, history vignette, ending)
- [ ] 158-03-PLAN.md — Terminal Shell Verification (Wave 3: real grep/sed outputs from fixture)
- [ ] 158-04-PLAN.md — LPIC Content Check (Wave 4: factual cross-check + 6 exam traps, after 158-03)
- [ ] 158-05-PLAN.md — devcloud Deploy + Playthrough Checkpoint (Wave 5: human K3-01/K3-04 verification)

### Phase 159: Akt 1 — Retention, Material & Go-Live
**Depends on**: Phase 158 (spielbarer K3-Arc muss existieren).
**Goal**: Lerneffekt verankern (FSRS-Pool-Brücke), Material verknüpfen, sicher live schalten.
**Requirements**: RET-01, MAT-01, DEPLOY-01, DEPLOY-02
**Success criteria:**
1. K3-Befehle existieren parallel als Pool-Cards (Pool 65 erweitert oder neuer Ghostline-CLI-Pool) → FSRS greift nach dem Durchspielen
2. NotebookLM-Lernfilm/-Audio als Kurs-Material verlinkt und erreichbar
3. Staged Deploy: JSON-only unfeatured auf devcloud getestet; FEATURED-Schaltung (AbenteuerMode.vue) erst nach Andrés Durchspielen
4. Scope-Sentinel eingehalten — kein PHP/Vue-Edit außer FEATURED-Zeile; Gate 1 (ESLint/Vitest) grün beim JS-Edit

## v5.2.0 — Pflichtschulung (Active)

> Video-/Material-Gating, Teamleiter-RBAC-Reports, Re-Zertifizierung mit manipulationssicherem Audit-Trail.
> AWO-Sachsen-Lead Jan Knizek (Issue #20). Research: `.planning/research/`.
> THREE Red Foundations: hash-chained audit trail, first-class assignment objects, learning_oversight schema.
> Migration sequence starts at Version009300 (live = Version009200 v5.0.0).

**5 Phasen** | **41 v1-Requirements** | alle gemappt ✓

| # | Phase | Goal | Requirements |
|---|-------|------|--------------|
| 160 | Foundation — Audit Hash-Chain + Assignment Schemas | Manipulationssichere Audit-Basis + Zuweisungsinfrastruktur | AUDIT-01..03, ASSIGN-01..05, USER-01/02, DSGVO-01, RBAC-01 |
| 161 | Audit Hardening — Checkpoints + Anchor + Export + Liveness | Unabhängig verifizierbare Ed25519-Checkpoints + Auditor-Export | AUDIT-04..09 |
| 162 | Video-/Material-Gating + DSGVO Art.13 | Server-seitiges Gating; kein Quiz ohne echtes Durchsehen | VIDEO-01..09, DSGVO-04 |
| 163 | Teamleiter-RBAC-Reports + DSGVO Art.20 | Gruppengefilterter Report + Datenschutz-Export | RBAC-02..04, DSGVO-02 |
| 164 | Re-Zertifizierung + Retention + i18n Parity | Kompletter Cert-Lifecycle: Erinnerung, Period-Close, Re-Enrollment | RECERT-01..07, DSGVO-03, DSGVO-05 |

## v5.0.0 — Certification-as-a-Service (Shipped)

**Milestone Goal:** Die App bringt verifizierbare Zertifizierung als natives NC-Feature — Kurs-Owner aktiviert "Zertifikat bei Bestehen", definiert das Pass-Kriterium, und die App stellt beim Bestehen ein standardkonformes, signiertes Zertifikat aus. Aussteller = die jeweilige NC-Instanz (kein SaaS). Wallet interop deferred to v6+.

### Phases

- [x] **Phase 154: Pass-Definition** — Hard pass criterion per course, lucky-guess exclusion, student status, audit event
- [x] **Phase 155: Certificate-Artifact & Issuer** — Ed25519 keypair + did:web + OB3/VC issuance + print/QR/download/LinkedIn _(7/7 plans; issuer provisioned live on devcloud, 10/13 CERT reqs live-verified end-to-end; CERT-07/08/13 implemented + Vitest/build-proven, visual eyeball deferred to demo course — user option A, non-blocking)_
- [x] **Phase 156: Compliance-Report** — Instructor CSV export, date/expiry filters, DSGVO-safe (runs parallel with 157) _(2/2 plans; backend 156-01 + UI 156-02; all 4 REPORT reqs Complete; live credentialed Gate 2 + visual check ride the demo-course pass)_
- [x] **Phase 157: Public-Verify** — Public @PublicPage verify route, signature + revocation check, rate-limit (runs parallel with 156) _(5/5 plans, 4 waves; gsd-verifier CLOSE 6/6. VERIFY-01/06 live-proven, VERIFY-02/03/04 backend-proven, VERIFY-05 implemented (live revoke needs occ upgrade — Version009200 dormant). Live-activation + visual/credentialed/429 smokes ride the demo-course provisioning pass, user option A)_

## v6.0.0 — Universal Learning Platform (Vision)

> Ziel: Von IT-Drill-Tool zur Lernplattform fuer ALLE Faecher. Schulen, Unis, Fahrschulen, Sprachkurse, Medizin, Jura, Handwerk.
> Kerngedanke: Der universelle Kern (FSRS, Gamification, VirtuProf, Datensouveraenitaet) funktioniert bereits fachunabhaengig. v6.0 entfernt die IT-Scheuklappen und oeffnet die Plattform.

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
| **IT-Simulatoren** | 9 Simulatoren bleiben als kursweise aktivierbares IT-Modul (via tool_config) |

### Deep Nextcloud Integration

| Feature | Was |
|---------|-----|
| **NC Assistant Provider** | VirtuProf als Provider fuer den NC AI Assistant direkt im NC-Copilot |
| **Unified Search** | Lernkarten + Pools ueber NC globale Suche findbar (aus v4.1 Phase 140) |
| **Deck-Automation** | FSRS erkennt Luecke → erstellt Aufgabe in Nextcloud Deck |
| **Talk-Trigger** | Mehrere Studenten am selben Thema schwach → automatische Lernsession in NC Talk |
| **Predictive Career Intelligence** | KI-Kompetenzanalyse, Arbeitgeber-taugliches Portfolio |

## v4.3.0 — Onboarding & Content Intelligence (Deferred)

> Geplant nach v5.0.0. Phase-Nummern TBD.

| Feature | Scope |
|---------|-------|
| Onboarding Redesign (Option B) | 2-Ebenen Fullscreen: Splash→Rolle→Tour→Datenschutz→Profil-Kacheln→Content-Jumpstart→Hook |
| Material → Pool Generator (3 Modi) | Gemini Cloud, Lokal Ollama, Manuell CSV/JSON. PoolDraftReview.vue |
| NOVA Sprachausgabe (Gemini TTS) | Vorlese-Button, 30 HD-Stimmen, Browser SpeechSynthesis Fallback |
| Video/Audio → Pool Generator | YouTube-URL + Audio-Upload, Gemini Video API + Whisper ASR |

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
- [x] **Phase 152: Three Archetype Presets** - SVG-Silhouetten für Theoretiker + Kosmologe (Power-First Drawing Order) + Astrophysik-Popularisierer + Sensitivity-Sign-off vor Freeze, ANIM-05 Animation-Coverage je Skin (SCHOLAR-01..04, ANIM-05) (completed 2026-04-25)
- [x] **Phase 153: Migration, Tests, Deploy & App Store** - Zero-Change-Default-Migration, One-time-In-App-Hinweis, Vitest (SkinRenderer + resolveReaction + 4 Avatar-Snapshots), Playwright E2E mit `animations: 'disabled'`, A11y-Audit (structural-pivot), i18n-5-Sprachen-Parität + CI-Key-Check, DevCloud-Test (4-account API smoke), stale-JS-chunk-Cleanup, signature.json re-sign, App-Store-Push (MIGR-01..05, TEST-01..06, I18N-01..03) ✓ 2026-04-27

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

### Phase 154: Pass-Definition
**Goal**: Instructors can configure a hard pass criterion per course; the system evaluates pass as a binary result from discrete assessments (excluding guessed answers), records it immutably, and students see their pass status
**Depends on**: Nothing (first phase of v5.0.0)
**Requirements**: PASS-01, PASS-02, PASS-03, PASS-04, PASS-05, PASS-06, PASS-07
**Success Criteria** (what must be TRUE):
  1. Instructor can enable certification for a course, set a score threshold (%), designate mandatory pools, and set a validity duration; all settings persist across reload
  2. A student who meets the threshold on discrete assessment results (with guessed answers excluded per `is_guessed`) sees "Bestanden" status on their course page
  3. A student below the threshold sees "Noch nicht bestanden" with their current assessment score; FSRS readiness has no bearing on the pass result
  4. When a student first meets pass criteria, an immutable pass event appears in the audit log with user_id, course_id, score, threshold, and timestamp
**Notes**:
- PassCriteriaService MUST NOT call ReadinessService (ReadinessService throws without exam_date; orthogonal concepts; coupling here would silently break pass evaluation)
- Lucky-guess exclusion (`is_guessed`) and the FSRS-vs-pass separation are LOCKED here as the foundation gate before any crypto work (Pitfall 3 prevention)
- Migration Version009000: typed cert-config columns on `learning_courses` (NOT JSON in mode_config; PHPStan-checkable; follows Version003500 pattern)
- Scope guardrail: NO multi-tenant platform; issuer = NC instance did:web; wallet interop deferred to v6+
**Plans**: 5 plans
Plans:
- [x] 154-01-PLAN.md — Wave 1: Test stubs + PassResult/PassCriteriaService skeletons (suite stays green)
- [x] 154-02-PLAN.md — Wave 2: DB migration Version009000 + Course.php cert field extension
- [x] 154-03-PLAN.md — Wave 3: Service layer (getExamScore + two-gate PassCriteriaService + PHPUnit GREEN)
- [x] 154-04-PLAN.md — Wave 4: Controller endpoints (cert-config PATCH + pass-status GET) + JS + i18n
- [x] 154-05-PLAN.md — Wave 5: Vue UI (instructor config block + student Zeugnisstatus card) + human verify

### Phase 155: Certificate-Artifact & Issuer
**Goal**: The NC instance has a cryptographic identity (did:web, Ed25519), issues a signed OB3/VC credential automatically on pass, and students can view, print, download, and share it; key rotation preserves all past certificates
**Depends on**: Phase 154 (pass event must exist before a credential can be issued)
**Requirements**: CERT-01, CERT-02, CERT-03, CERT-04, CERT-05, CERT-06, CERT-07, CERT-08, CERT-09, CERT-10, CERT-11, CERT-12, CERT-13
**Success Criteria** (what must be TRUE):
  1. Admin runs `occ learning:cert:init-issuer` and the instance generates an Ed25519 keypair; the private key is stored only as ICrypto-encrypted ciphertext (`_enc` suffix) — never plaintext, never in exports, snapshots, or app packages
  2. An unauthenticated HTTP GET to `<nc_base>/apps/learning/did.json` returns a valid DID document with a verificationMethod resolving the instance's public key
  3. When a student meets the pass criterion, a signed Open Badges 3.0 / W3C VC credential is automatically issued and stored in `learning_certificates`; the student receives an NC notification
  4. Student can view their certificate (course, score, threshold, issuer branding from NC theming, issue/expiry dates), print it via `window.print()`, scan the QR code to the verify URL, download the credential as OB3 JSON-LD, and copy a pre-filled LinkedIn "Add to Profile" URL; content renders in DE and EN via existing i18n
  5. Rotating the issuer key (new row in `oc_learning_cert_keys`) does not invalidate previously issued certificates — each credential references its signing key-id
**Notes**:
- CRITICAL ENTRY GATE: Signing-format ADR (VC-JWT + EdDSA vs eddsa-jcs-2022 Data Integrity) MUST be recorded as the FIRST task — no signing code before ADR is decided. ADR resolves both signing format and did.json key type simultaneously (VC-JWT → publicKeyJwk; jcs-2022 → publicKeyMultibase)
- CRITICAL PITFALL 1 (sign-the-wrong-bytes): eddsa-rdfc-2022 needs RDFC-1.0; no conformant PHP lib. If jcs-2022 chosen, verify JCS lib against RFC 8785 test vectors before committing. VC-JWT = zero canonicalization risk
- CRITICAL PITFALL 2 (private key placement): AppConfig `sensitive:true` hides but does NOT encrypt; key in `app/data/` ships in App Store tarball. Prevention: generate in memory → ICrypto::encrypt() immediately → store only ciphertext. Audit ALL export paths before phase closes
- did.json + issuer key MUST ship in this phase (NOT deferred to Phase 157); verificationMethod must resolve at issuance time
- Migration Version009100: creates `learning_certificates` (UUID `verification_id`, `credential_json` TEXT, `revoked` flag) + `oc_learning_cert_keys` (key rotation in scope per owner decision)
- Cross-DB gate: PG16 + MariaDB 11.4 utf8mb4 migration test = go/no-go before phase closes
- Scope guardrail: NO multi-tenant platform; wallet interop (Apple/Google/EUDI/Europass) OUT for v5.0.0; deferred to v6+
**Plans**: 7 plans (waves 1-7, sequential crypto chain)

Plans:
- [x] 155-01-PLAN.md — ADR anchor + Migration Version009100 (2 tables) + CertKey/Certificate entities + mappers (data layer) (CERT-03, CERT-04, CERT-06) ✓ 2026-06-27
- [x] 155-02-PLAN.md — KeyService (sodium keygen + ICrypto encrypt + rotation) + InitIssuerCommand (occ) + DidController (public did.json) ✓ 2026-06-27
- [x] 155-03-PLAN.md — SigningService VC-JWT EdDSA (TDD round-trip + tamper + independent verifier) — ADR follow-ups #1/#2 (CERT-06 mechanism; Pending until 155-04/07) ✓ 2026-06-27
- [x] 155-04-PLAN.md — IssuanceService (pass hook → OB3 → sign → persist → notify) + Notifier certificate_issued + i18n ✓ 2026-06-27
- [x] 155-05-PLAN.md — CertificateController (list/show/download, ownership-checked; OB3 JSON-LD EnvelopedVerifiableCredential per CERT-09) + CertificateService.js ✓ 2026-06-27
- [x] 155-06-PLAN.md — Certificate.vue (Options API: render/print/QR/download/LinkedIn) + vendored MIT QR + webroot-safe verify URL + CourseSummary entry + i18n 5 langs (commits 316bd1b, d4ff9e3, 3ed5376, c714ee3, 8cbfd9d) — CERT-09/10/11 live-verified; CERT-07/08/13 implemented, visual eyeball deferred to demo course (user option A) ✓ 2026-06-27
- [x] 155-07-PLAN.md — Phase-close gates + live provisioning: leakage audit (Rule 18, 39 assertions) + cross-DB go/no-go GREEN (PG16 + MariaDB 11.4 utf8mb4) + kid↔did.json + independent-verifier gate (commits 20f3666, be014ab, 93c4d6a, 00cfeca, 77e1159, f6e3268). LIVE on devcloud: Version009100 applied PG16, Ed25519 issuer key UI3V-D_j…, did.json HTTP 200; synthetic e2e smoke minted + independently verified a REAL cert + proved idempotency, then cleaned up. CERT-01/02/03/04/06 live-verified (CERT-05/12 closed live) ✓ 2026-06-27


### Phase 156: Compliance-Report
**Goal**: Instructors can see who passed which certifying course, filter by date/expiry, and export a DSGVO-safe CSV
**Depends on**: Phase 155 (learning_certificates table must exist with issued credentials); runs parallel with Phase 157
**Requirements**: REPORT-01, REPORT-02, REPORT-03, REPORT-04
**Success Criteria** (what must be TRUE):
  1. Instructor opens a certifying course and sees a compliance table showing: display name, passed date, score, expiry date, verification UUID — no email addresses or user_id values shown
  2. Instructor can filter the report by passed-date range (from/to) and by expiry window (expiring within N days)
  3. Instructor clicks "Export CSV" and receives a downloaded file containing the filtered report with display name only (no email, no user_id)
**Notes**:
- Implementation pattern: direct copy of SummaryController::exportCsv(); no new architectural patterns needed
- DSGVO: expose display name only; no plaintext email; no user_id in CSV export (REPORT-04 is non-negotiable)
- Scope-drift check: export-only feature, NOT a dashboard or analytics product
- Scope guardrail: NO multi-tenant platform; wallet interop deferred to v6+
**Plans**: 2 plans (waves 1-2)

Plans:
- [x] 156-01-PLAN.md — Wave 1: CertificateReportService (owner-scoped read + JWT decode for score+frozen name + 5-field DSGVO DTO, no user_id) + CertificateMapper::findByCourseId + CourseService::assertInstructorOfCourse gate + CertificateReportController (JSON + injection-safe CSV) + 2 routes + load-bearing no-leak/IDOR/filter PHPUnit (REPORT-04 Complete; REPORT-01/02/03 backend-complete → UI in 156-02) — **DONE 2026-06-27** (7 tests/23 assertions green, PHPStan L5 clean, routes live)
- [x] 156-02-PLAN.md — Wave 2: instructor compliance section in CourseTabTeilnehmer.vue (filter inputs + table from the clean DTO endpoint + Export CSV button) + pure cert-report util + Vitest + i18n 5 langs; visual render/download folds into the deferred demo-course check (REPORT-01, REPORT-02, REPORT-03) — **DONE 2026-06-27** (15 Vitest green, ESLint 0, i18n parity green, deployed via --js-only; REPORT-01/02/03 Complete)

### Phase 157: Public-Verify
**Goal**: Anyone can verify a certificate via its verification-id at a public URL; the response is DSGVO-safe for unauthenticated callers; revoked certificates return a tombstone (not 404); the route is hardened against enumeration and abuse
**Depends on**: Phase 155 (credential schema, verification-id, did.json, and issuer key must exist); runs parallel with Phase 156
**Requirements**: VERIFY-01, VERIFY-02, VERIFY-03, VERIFY-04, VERIFY-05, VERIFY-06
**Success Criteria** (what must be TRUE):
  1. An unauthenticated visitor to `<nc_base>/apps/learning/verify/{verificationId}` sees a human-readable page with: valid/invalid/revoked/expired status, issuer name, course title, issue and expiry dates — the recipient's name and user identity are never exposed to unauthenticated callers
  2. Verification cryptographically checks the Ed25519 signature against the published public key AND checks revocation/expiry status — a valid signature alone is insufficient to return "valid"
  3. When an instructor revokes a certificate, subsequent verification returns "withdrawn" with the revocation date (tombstone, not 404)
  4. Submitting a malformed verification-id returns a validation error; repeated requests trigger rate limiting via NC IThrottlingService and #[BruteForceProtection]
**Notes**:
- Route must be #[PublicPage] #[NoCSRFRequired] #[BruteForceProtection] — follow ExportController / IcsController pattern for @PublicPage
- PII leak prevention: unauthenticated response shape = {valid, revoked, expired, issued_at, course_title} only — no recipient name, no user_id (Pitfall 5 / DSGVO gate)
- Input validation: UUID format check before any DB query (anti-enumeration / IDOR prevention, VERIFY-06)
- Playwright: logged-out browser confirms no recipient name leaks before phase closes
- Scope guardrail: External verify portal for non-NC supervisors deferred beyond v5.0.0; wallet interop deferred to v6+
**Plans**: 5 plans (waves 1-4)

Plans:
- [x] 157-01-PLAN.md — Wave 1: `revoked_at` migration (Version009200, dormant) + Certificate entity field + cross-DB check. info.xml bump DEFERRED (PROD-SAFETY: would force needsDbUpgrade on a --php-only deploy → live maintenance page; bump + occ upgrade ride the 157-05 provisioning pass). VERIFY-05 foundation. _(16404e0 + 3196785; PHPStan L5 clean, cross-DB GO, jsonSerialize untouched)_
- [x] 157-02-PLAN.md — Wave 2: CertificateVerifyService (resolve→key-by-id+status→sig→claim-binding→status precedence→DSGVO DTO) + 10-case TDD (VERIFY-02, VERIFY-03, VERIFY-04, VERIFY-05) — **DONE 2026-06-27** (4638e69 RED + 85b6321 GREEN; 10/10 PHPUnit, PHPStan L5 clean, leak grep clean; Codex #1 revoked-key + #2 claim-binding baked in. VERIFY-02..05 NOT flipped — backend-proven, flip at 157 close after live Playwright)
- [x] 157-03-PLAN.md — Wave 2 (∥ 157-02): revoke write (owner-gated, idempotent, active_idem_key=NULL) + instructor Widerrufen button in CourseTabTeilnehmer compliance table + i18n (VERIFY-05) — **DONE 2026-06-27** (f51c3b3 endpoint+route+REAL-gate test + 37e5e23 button+i18n; CertificateController::revoke() @NoAdminRequired, uniform 404, atomic revoked+revoked_at-first+active_idem_key=NULL; 13/13 PHPUnit incl. update->never() gate-before-write, PHPStan L5 clean, ESLint 0, i18n parity, Vitest 26. VERIFY-05 NOT flipped — live credentialed revoke smoke rides demo-course pass)
- [x] 157-04-PLAN.md — Wave 3: PublicVerifyController (@PublicPage PHPDoc — NOT the #[PublicPage] attribute, which 401'd logged-out — UUID precheck, throttle-on-unknown, #[AnonRateLimit]) + pure server-rendered verify.php template (4 status banners + DSGVO missing-name explainer) + page route + i18n 5 langs (VERIFY-01, VERIFY-02, VERIFY-06) — **DONE 2026-06-27** (70c9695 RED + b463bb1 GREEN + d05d593 fix-public-route + 94f0166 template+i18n; 5/5 PHPUnit, PHPStan L5 clean, ESLint 0, i18n parity 2260×5, leak grep clean. LIVE logged-out HTTP 200 + malformed==not-found no-oracle; valid-render proven via non-mutating in-container render smoke — synthetic cert was cleaned up, no prod mint. VERIFY-01/02/06 NOT flipped — flip at 157 close after 157-05 provisioning)
- [x] 157-05-PLAN.md — Wave 4: Playwright logged-out reachability + DOM no-leak (vs LIVE cert recipient) + cross-DB GO + leak-grep phase gate (VERIFY-01, VERIFY-03) — **DONE 2026-06-27, CLOSED 2026-06-28** (639b2bc spec + public-verify project; live re-run 2026-06-28: VERIFY-01 + VERIFY-06 GREEN, VERIFY-03 DOM gate self-skips until a real cert is provisioned; PHPUnit 22/22, cross-DB GO incl. revoked_at, leak-grep clean, PHPStan L5, ESLint 0)

> **Wave structure:** W1 = 157-01 (migration foundation). W2 = 157-02 ‖ 157-03 (parallel — verify service vs revoke controller, no shared files). W3 = 157-04 (depends 157-02 service + 157-03 shared routes.php/i18n). W4 = 157-05 (e2e + phase gate). Live `occ upgrade` apply + credentialed/visual smokes deferred to the authorized demo-course provisioning pass (user option A).

### Phase 160: Foundation — Audit Hash-Chain + Assignment Schemas
**Goal**: Tamper-evident audit foundation and assignment infrastructure are in place; all callers produce chain-linked compliance events; first-class assignment objects exist in the DB; email-null callers are safe
**Depends on**: Phase 157 (v5.0.0 complete; AuditService, Certificate, cert chain already exist)
**Requirements**: AUDIT-01, AUDIT-02, AUDIT-03, ASSIGN-01, ASSIGN-02, ASSIGN-03, ASSIGN-04, ASSIGN-05, USER-01, USER-02, DSGVO-01, RBAC-01
**Success Criteria** (what must be TRUE):
  1. `occ learning:audit:verify` reports a clean unbroken hash chain since deploy; manually altering a row in learning_audit_events changes its chain_hash and the next run reports a mismatch at that row
  2. Instructor assigns a course to a NC group with a due date via the API; a learning_assignments row is created with correct course_id, subject_type='group', subject_id, due_date; LDAP/AD group members are resolved via IGroupManager without any extra config
  3. A user with no email address completes a course and receives a cert-issued NC notification; no IMailer null-pointer crash; all three migrated callers (PassCriteriaService, IssuanceService, CertificateVerifyService) are email-null safe
  4. `occ learning:import-users <csv> --group=<nc-group>` creates NC users + assigns them to the group; a 2000-row CSV completes as a background job without HTTP timeout (IJobList-dispatched)
  5. NC user deletion triggers Art.17 anonymization on audit rows: chain_hash fields are preserved (chain integrity unbroken), PII fields pseudonymized; `occ learning:audit:verify` still reports clean; both learning_assignments and learning_oversight migrations pass on PG16 and MariaDB 11.4
**Notes**:
- RED-1: logComplianceEvent() MUST NOT swallow exceptions (unlike logEvent()) — a silently dropped write creates a detectable chain gap
- Hash formula: chain_hash = sha256(canonical_json(event_type, user_id, course_id, created_at) || prev_hash) — PII excluded from canonical fields
- RED-3: learning_assignments uses PLAIN composite index (course_id, subject_type, subject_id) — NOT UNIQUE; re-cert creates new rows. active_period_key has UNIQUE index.
- RBAC-01 (learning_oversight schema) belongs here alongside RED-3 schema work — Phase 163 uses it
- Migration sequence starts at Version009300
**Plans**: 6/6 — ✓ **COMPLETE 2026-07-01** (verified 12/12 reqs; PHPStan L5 clean, PHPUnit 183/0/0; migrations 009300/009400/009301 applied on PG16 + MariaDB 11.4 cross-checked; Codex security review commit 18973dc — 7/8 findings fixed, #1 signed-checkpoint/anchor deferred to Phase 161)
Plans:
- [x] 160-01-PLAN.md — Track A W1: Version009300 migration (audit hash-chain schema + genesis seed) + AuditServiceTest stubs
- [x] 160-02-PLAN.md — Track A W2: ComplianceEventTypes + logComplianceEvent() CAS + DSGVO-01 UserDeletedListener
- [x] 160-03-PLAN.md — Track A W3: Migrate 3 callers to logComplianceEvent (PassCriteria, Issuance, CertController)
- [x] 160-04-PLAN.md — Track B W1: Version009400 migration (learning_assignments + learning_oversight) + Track B test stubs
- [x] 160-05-PLAN.md — Track B W2: AssignmentService skeleton (5 methods) + USER-01 ClassbookController null-safe email
- [x] 160-06-PLAN.md — Track B W3: occ learning:import-users command + ImportUsersJob + Application.php registration
- [x] 160-sec (Codex hardening): atomicity/fail-closed, payload_hash, pepper-from-secret, CAS id=1, Version009301 unique seq_num, delete guard (commit 18973dc)

### Phase 161: Audit Hardening — Checkpoints + Anchor + Export + Liveness
**Goal**: The audit trail is independently verifiable (Ed25519-signed weekly checkpoints), operable by the Datenschutzbeauftragter (export), and monitored by admins (liveness widget); Forgejo anchor scaffolded for fork-detection
**Depends on**: Phase 160 (hash-chain core must exist before checkpoints can be built on top)
**Requirements**: AUDIT-04, AUDIT-05, AUDIT-06, AUDIT-07, AUDIT-08, AUDIT-09
**Success Criteria** (what must be TRUE):
  1. A weekly Ed25519 checkpoint is verifiable by an external party using only the did.json public key and sodium_crypto_sign_verify_detached — no app trust or DB access needed; AuditCheckpointService uses sodium_crypto_sign_detached directly (NOT SigningService::sign(), whose typ:vc+jwt header is frozen by 155-ADR-ANCHOR)
  2. Datenschutzbeauftragter (not shell admin) generates a signed auditor export via the app UI: selects date range + course → downloads PDF summary + JSONL event log + detached Ed25519 sig file; export does not require admin role
  3. Admin-settings widget shows: last checkpoint date, event count since last checkpoint, anchor status (anchored/unanchored/overdue); warning indicator if checkpoint has not run within configured interval
  4. `occ learning:audit:verify` validates chain integrity + checkpoint signatures + anchor_url consistency; fork-runbook URL in output; Forgejo anchor (AUDIT-05) scaffolded with config flag + anchor_url column on learning_audit_checkpoints (off by default; single HTTP PUT to Forgejo contents API when enabled)
**Notes**:
- AuditCheckpointService MUST use sodium_crypto_sign_detached directly — NOT SigningService (155-ADR-ANCHOR frozen to typ:vc+jwt)
- Forgejo anchor defeats the admin-holds-both-key-and-DB threat model; design+scaffold now, enable via config
- anchor_url column on learning_audit_checkpoints is nullable until anchored
- ⚠ FORWARD-DEP from Phase 160: the shipped chain canonical is **6 fields** (seq, event_key, user_ref, course_id, created_at, **payload_hash**=sha256(context_json), added by Codex FIX-4). `occ learning:audit:verify` MUST reconstruct this exact 6-field ksort'd canonical + `sha256(canonical . '|' . prev_hash)`, else it will report every compliance event as tampered. user_ref = hash_hmac('sha256','learning:audit_user_ref', instance-secret). CAS state row pinned to id=1.
**Plans**: 6/6 — ✓ **COMPLETE 2026-07-01** (verified 6/6 must-haves automated; PHPStan L5 clean, PHPUnit 222/768; migration 009302 applied on PG16; live occ audit:verify exit 0; export gate 403→200; getAdmin 5 audit_* keys HTTP 200; grumpy-Codex security review 7/7 findings fixed [F1 prev_hash BLOCKER, F2 checkpoint field-binding, F3 memzero, F4 anchor raw-url, F5 PII-strip, F6 SSRF-https, F7 pubkey-length], commits 730261f..c3c75cd. 3 visual/live-data items → human run-through: live checkpoint mint on non-empty chain, overdue-banner DOM, export UI/print eye-check.)

Plans:
- [x] 161-01-PLAN.md — Wave 1: Version009302 migration (learning_audit_checkpoints) + AuditCheckpointService (Ed25519 signing) + AuditCheckpointJob (weekly TimedJob) (AUDIT-04)
- [x] 161-02-PLAN.md — Wave 2: Forgejo anchor scaffold in AuditCheckpointService (off by default, soft-fail, anchor_url) (AUDIT-05)
- [x] 161-03-PLAN.md — Wave 2: occ learning:audit:verify (6-field canonical reconstruct + checkpoint sig verify + fork-runbook URL) (AUDIT-06)
- [x] 161-04-PLAN.md — Wave 2: AuditExportController (@NoAdminRequired, group-gated) + JSONL + sig + HTML-print + auditor page (AUDIT-07)
- [x] 161-05-PLAN.md — Wave 3: SettingsController.getAdmin() + AdminSettings.vue liveness widget (AUDIT-08)
- [x] 161-06-PLAN.md — Wave 3: docs/audit-fork-runbook.md fork-resolution runbook (AUDIT-09)

### Phase 162: Video-/Material-Gating + DSGVO Art.13
**Goal**: Students cannot advance past a locked gate without genuinely completing the required video or document; third-party embeds load only after DSGVO Art.13 consent; all enforcement is server-side
**Depends on**: Phase 160 (VideoProgressService emits compliance events; course.video.completed event type defined in Phase 160 AUDIT-03; assignment schema exists for gated-course concept)
**Requirements**: VIDEO-01, VIDEO-02, VIDEO-03, VIDEO-04, VIDEO-05, VIDEO-06, VIDEO-07, VIDEO-08, VIDEO-09, DSGVO-04
**Success Criteria** (what must be TRUE):
  1. A student who has watched < 95% of a NC-MP4 finds the quiz locked (403 from TrainingService::startSession()); the quiz unlocks immediately after genuine 95% completion — client-side flags are never trusted
  2. Rapid heartbeat pings (< 5 seconds apart) do not advance covered_pct; the server discards implausible pings silently and VideoProgressService merges intervals server-side
  3. Clicking the "Gelesen" button on a document material records the read event and unlocks the quiz tab; the gate is enforced at the server (not only in the UI)
  4. Vimeo and YouTube embeds show a DSGVO Art.13 consent overlay on first view; embeds load only after user accepts; youtube-nocookie.com + dnt=1 parameter used; Vimeo dnt=1; transient interval data in learning_video_progress.intervals_json is deleted at the completed_at write (only user_id, content_id, completed_at persisted)
  5. Video player is keyboard-navigable (Space=play/pause, arrow=seek); a WebVTT subtitle track is available (empty if no captions provided); WCAG AA contrast passes on controls; screen reader receives play/pause state via aria-live
**Notes**:
- Server-side enforcement in TrainingService::startSession() — NEVER trust client flags (critical pitfall)
- VideoStreamController must use IRootFolder->getUserFolder($instructorId)->fopen() — NOT NC_SHARE_URL; IShareManager not recommended
- Zero new npm/composer deps: Vimeo + YouTube SDKs loaded lazily from CDN at runtime; native `<video>` for NC-MP4
- Heartbeat plausibility: server discards pings < 5s apart
- DSGVO transient segments: intervals_json deleted at completed_at write
- INotificationManager as primary reminder channel (email-null safe); IMailer additive only where email is non-null
**Plans**: TBD

### Phase 163: Teamleiter-RBAC-Reports + DSGVO Art.20
**Goal**: Team leads can view group-scoped compliance status, send reminders, and individuals can export their own data; no cross-group data leaks at any layer
**Depends on**: Phase 160 (learning_oversight schema must exist), Phase 162 (assignment status with video-gated completions drives the report)
**Requirements**: RBAC-02, RBAC-03, RBAC-04, DSGVO-02
**Success Criteria** (what must be TRUE):
  1. A team lead's report shows only members of their assigned group; manipulating the URL to reference another group_id returns 403; the DB query uses WHERE user_id IN (group members) enforced by assertTeamLeadForGroup() before any data access
  2. Team lead dashboard shows: members with incomplete/overdue assignments, upcoming cert expirations, and an "Erinnerung senden" button that dispatches an NC notification to the selected member (INotificationManager, email-null safe)
  3. A user can export their own certificates and learning history as machine-readable JSON (DSGVO Art.20); the export contains only that user's data; requesting another user's data returns 403
**Notes**:
- IDOR protection: assertTeamLeadForGroup() as first line of CertificateReportService::getGroupReport(); group filter at DB level
- INotificationManager primary; IMailer additive (email-null safe per Phase 160 USER-01 fix)
**Plans**: TBD

### Phase 164: Re-Zertifizierung + Retention + i18n Parity
**Goal**: The cert validity lifecycle is complete — reminders fire at configured thresholds, old periods close gracefully, students re-enroll and receive new certs, data ages out safely, and all v5.2.0 strings are multilingual
**Depends on**: Phase 160 (assignment schema with active_period_key), Phase 163 (Teamleiter sees expiry in their dashboard; reminder loop must not storm)
**Requirements**: RECERT-01, RECERT-02, RECERT-03, RECERT-04, RECERT-05, RECERT-06, RECERT-07, DSGVO-03, DSGVO-05
**Success Criteria** (what must be TRUE):
  1. NC notifications fire at T-30 and T-7 per cert per configured threshold; each threshold-cert pair fires exactly once (idempotent reminder tracking — no storms even if job runs multiple times)
  2. After the grace period expires, RecertPeriodCloseJob sets revoked_at, NULLs active_period_key, and creates a new learning_assignments row; the student can re-enroll and receive a new cert; the old verify URL permanently returns "expired"
  3. Period-close idempotency: running RecertPeriodCloseJob multiple times creates exactly one new assignment row per period (UNIQUE active_period_key constraint enforced)
  4. Cert validity is configurable per course (default 12 months) with per-assignment override; retention auto-anonymizes certs and audit rows after configured years; date math uses DateTimeImmutable::modify('+1 year') — NOT +365*86400; unit test covers DST crossing (2026-03-29 → 2027-03-29 = correct)
  5. i18n parity CI check passes for all 5 languages (DE/EN/FR/RU/AR) across all cert/notification/re-enrollment strings added in v5.2.0; no new string key exists in DE without an entry in all 4 other language files
**Notes**:
- RECERT-05 (guard redesign in PassCriteriaService): MANDATORY Codex security review before implementation — checks "active assignment with active_period_key IS NOT NULL AND status != passed" instead of "ever passed"; this is open-heart surgery on the cert flow
- DST-safe: DateTimeImmutable::modify('+1 year') only; unit test REQUIRED for 2026-03-29 → 2027-03-29
- RecertPeriodCloseJob is a daily TimedJob
- AWO Betriebsvereinbarung note: client must be informed that a works-council agreement (BetrVG §87 Abs.1 Nr.6) is required before production rollout — transient segment design (Phase 162) is the technical mitigation
**Plans**: TBD


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
| 153. Migration, Tests, Deploy & App Store | v4.4.0 | 7/7 | Complete | 2026-04-27 |
| 154. Pass-Definition | v5.0.0 | 5/5 | Complete | 2026-06-26 |
| 155. Certificate-Artifact & Issuer | v5.0.0 | 7/7 | Complete (CERT-07/08/13 visual verify deferred to demo course) | 2026-06-27 |
| 156. Compliance-Report | v5.0.0 | 2/2 | Complete (156-01 backend + 156-02 UI; all 4 REPORT reqs done) | - |
| 157. Public-Verify | v5.0.0 | 5/5 | ✅ Complete (4 waves; gsd-verifier CLOSE 6/6; live-activation rides demo-course provisioning pass) | 157-VERIFICATION.md |
| 160. Foundation — Audit Hash-Chain + Assignment Schemas | v5.2.0 | 6/6 | ✅ Complete (PHPStan L5 clean, PHPUnit 183/0/0, migrations 009300/009400/009301 applied) | 2026-07-01 |
| 161. Audit Hardening — Checkpoints + Anchor + Export + Liveness | v5.2.0 | 6/6 | ✓ Complete | 2026-07-01 |
| 162. Video-/Material-Gating + DSGVO Art.13 | v5.2.0 | 0/TBD | Not started | - |
| 163. Teamleiter-RBAC-Reports + DSGVO Art.20 | v5.2.0 | 0/TBD | Not started | - |
| 164. Re-Zertifizierung + Retention + i18n Parity | v5.2.0 | 0/TBD | Not started | - |
