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
- 🔄 **v3.4.0 UX-Konsolidierung & Simulator-Upgrade** — Phases 96-100 (active)

## Phases

<details>
<summary>✅ v2.3 — v12.1 (Phases 1-89) — SHIPPED</summary>

Phases 1-89 shipped across milestones v2.3 through v12.1. See git history for details.

</details>

<details>
<summary>✅ v13.0 Feature Expansion (Phases 90-95) — SHIPPED 2026-03-28</summary>

- [x] Phase 90: NOVA Character Bible (1/1 plans) — completed 2026-03-27
- [x] Phase 91: NOVA Visual Implementation (4/4 plans) — completed 2026-03-27
- [x] Phase 92: Ghostline Quest (2/2 plans) — completed 2026-03-27
- [x] Phase 93: Vue 3 Migration Evaluation (1/1 plan) — completed 2026-03-28
- [x] Phase 94: Kurs-Feed (2/2 plans) — completed 2026-03-28
- [x] Phase 95: Skill-Map (2/2 plans) — completed 2026-03-28

</details>

### v3.4.0 UX-Konsolidierung & Simulator-Upgrade (Phases 96-100)

- [x] **Phase 96: UX-Navigation Struktur** — Dozent-Tabs gruppieren, Abenteuer aus Arena loesen, Kursregeln-Gating verdrahten, Oldschool-Karte fixen (completed 2026-03-28)
- [x] **Phase 97: Code-Hygiene & Settings** — Settings aufsplitten, Zeitreise-Dead-Code entfernen, DE/EN Label-Mix bereinigen (completed 2026-03-28)
- [x] **Phase 98: Simulator-Praxis-Sessions** — Gefuehrte Schritt-fuer-Schritt-Sessions mit realen Szenarien und sichtbarem Fortschritt (completed 2026-03-28)
- [x] **Phase 99: Student-Dashboard** — Heute-Startscreen mit SmartQueue, globaler Feed aus allen Kursen, direkte Pool-Navigation (completed 2026-03-28)
- [ ] **Phase 100: DevCloud-Integration & Leitner** — Talk-Shortcut, Kursmaterialien-Tab, Buddy-Matching, Tool-Einschraenkungen, Sprint-Intervalle

## Phase Details

### Phase 96: UX-Navigation Struktur
**Goal**: Dozenten und Studenten erleben eine klare, logisch gegliederte Navigation ohne Tab-Chaos oder tote Enden
**Depends on**: Nothing (first phase of milestone)
**Requirements**: NAV-01, NAV-02, NAV-03, NAV-04
**Success Criteria** (what must be TRUE):
  1. Dozent sieht in CourseDetail Tabs in sinnvollen Gruppen (Lernraum / Teilnehmer / Kommunikation / Wettbewerb) statt 16 ungrouped Tabs
  2. Abenteuer erscheint als eigenstaendiger Lernmodus in der Navigation, nicht als Unterpunkt von Arena
  3. Ein Kurs mit deaktivierten Arena-Submodes zeigt Studenten diese Tabs nicht an (Kursregeln steuern Sichtbarkeit)
  4. Die Oldschool-Karte fuehrt zu einem funktionalen Screen oder der Einstiegspunkt ist entfernt
**Plans:** 2/2 plans complete
Plans:
- [x] 96-01-PLAN.md — Tab-Gruppierung Dozent + Abenteuer als eigenstaendiger Tab
- [x] 96-02-PLAN.md — Arena-Submode-Gating per Kursregeln + Oldschool-Pfad-Verifikation

### Phase 97: Code-Hygiene & Settings
**Goal**: Die App hat keine totem Code-Pfade und alle UI-Texte sind konsistent deutsch
**Depends on**: Phase 96
**Requirements**: NAV-05, NAV-06, NAV-07
**Success Criteria** (what must be TRUE):
  1. Dozenten sehen PersonalSettings (eigene Lerneinstellungen) UND AdminSettings (Kurs-Verwaltung) — beide erreichbar, nicht vermischt
  2. Der Zeitreise-Modus ist entweder vollstaendig spielbar oder alle Zeitreise-Einstiegspunkte sind aus der UI entfernt
  3. Alle sichtbaren UI-Labels sind auf Deutsch via t() — kein englischer Rohtext mehr im Interface
**Plans:** 2/2 plans complete
Plans:
- [ ] 97-01-PLAN.md — Settings Sub-Tabs fuer Dozenten + Zeitreise-Frontend komplett entfernen
- [ ] 97-02-PLAN.md — DE/EN Label-Mix bereinigen, alle sichtbaren Labels via t()

### Phase 98: Simulator-Praxis-Sessions
**Goal**: Simulatoren fuehren Lernende durch reale Szenarien statt ungeleitetes Klick-Training anzubieten
**Depends on**: Phase 97
**Requirements**: SIM-01, SIM-02, SIM-03
**Success Criteria** (what must be TRUE):
  1. Jeder der 7 Simulatoren bietet mindestens eine auswaehlbare Praxis-Session mit einem konkreten Szenario-Titel
  2. Eine laufende Session zeigt Schritt-fuer-Schritt-Anweisungen mit Erklaerungen — Lernende wissen was sie tun sollen und warum
  3. Eine Session zeigt einen Fortschrittsindikator (z.B. "Schritt 3 von 7") der sich beim Abarbeiten aktualisiert
**Plans:** 2/2 plans complete
Plans:
- [ ] 98-01-PLAN.md — Practicum Engine (State Machine + localStorage) + Session-Daten fuer alle 7 Simulatoren
- [ ] 98-02-PLAN.md — PracticumRunner UI-Komponente + "Praxis"-Tab in alle 7 Simulatoren integrieren

### Phase 99: Student-Dashboard
**Goal**: Studenten koennen ihren Lerntag mit einem einzigen Einstiegspunkt starten und alle relevanten Infos auf einen Blick sehen
**Depends on**: Phase 97
**Requirements**: DASH-01, DASH-02, DASH-03
**Success Criteria** (what must be TRUE):
  1. Ein Student der sich einloggt sieht sofort einen "Heute"-Screen mit faelligen Karten (SmartQueue), der Daily Challenge und dem aktuellen Streak
  2. Der globale Feed zeigt Ankuendigungen aus allen Kursen in denen der Student eingeschrieben ist — chronologisch, ohne Kurs wechseln zu muessen
  3. Pool-Liste ist direkt ueber die Hauptnavigation erreichbar (ein Klick vom Dashboard) — kein indirekter Umweg noetig
**Plans:** 2/2 plans complete
Plans:
- [ ] 99-01-PLAN.md — StudentDashboard + DailyChallengeCard + Navigation mit Dashboard/Pools
- [ ] 99-02-PLAN.md — GlobalFeed Integration + Human Verification

### Phase 100: DevCloud-Integration & Leitner
**Goal**: Die App ist eng mit den DevCloud-Werkzeugen verzahnt und Dozenten koennen Lernrhythmen an Kursdauer anpassen
**Depends on**: Phase 96, Phase 99
**Requirements**: DVCL-01, DVCL-02, DVCL-03, DVCL-04, LEIT-01
**Success Criteria** (what must be TRUE):
  1. Im Kurs-Header ist ein klickbarer Talk-Raum-Link sichtbar der den zugehoerigen NC Talk-Raum oeffnet
  2. Studenten sehen in CourseDetail einen eigenen "Materialien"-Tab mit read-only Kurs-Dokumenten
  3. Ein Buddy-Matching-Bereich zeigt wer im Kurs Hilfe anbietet und wer Hilfe sucht (basierend auf Telos help_offer/help_wanted)
  4. Im Werkzeuge-Tab werden nur die Simulatoren angezeigt die der aktive Kurs erlaubt — gesperrte Tools sind ausgeblendet oder als gesperrt markiert
  5. Dozent kann pro Kurs Sprint-Intervalle aktivieren (4h/12h/1d/2d) — bei aktivierten Sprint-Intervallen sehen Studenten das angepasste Wiederholungs-Timing
**Plans:** 2/3 plans executed
Plans:
- [ ] 100-01-PLAN.md — Backend: DB-Migration, Course Entity, LeitnerService Sprint-Intervalle, Buddy-Matching API
- [ ] 100-02-PLAN.md — Frontend: Talk-Link, Materialien-Tab Student, Werkzeuge-Filterung, Sprint-Toggle
- [ ] 100-03-PLAN.md — BuddyMatching.vue Komponente + CourseDetail-Integration

## Progress Table

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 96. UX-Navigation Struktur | 2/2 | Complete    | 2026-03-28 |
| 97. Code-Hygiene & Settings | 2/2 | Complete    | 2026-03-28 |
| 98. Simulator-Praxis-Sessions | 2/2 | Complete    | 2026-03-28 |
| 99. Student-Dashboard | 2/2 | Complete    | 2026-03-28 |
| 100. DevCloud-Integration & Leitner | 2/3 | In Progress|  |
