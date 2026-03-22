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
- 🚧 **v6.2 Visual Identity + Charakter-Cast** — Phases 44-47 (in progress)

## Phases

<details>
<summary>✅ v2.3 PBQ OnVUE-Niveau Upgrade (Phases 1-6) - SHIPPED 2026-03-17</summary>

### Phase 1: CLI State Machine
**Goal**: PbqCli unterstuetzt Cisco IOS Modi (exec/config/config-if), zeigt Fehlermeldungen fuer unbekannte Befehle und verarbeitet command_outputs mit Feedback-Text.
**Plans**: 2 plans

Plans:
- [x] 01-01-PLAN.md — cliStateMachine.js utility
- [x] 01-02-PLAN.md — PbqCli.vue state machine integration

### Phase 2: SVG Topology Renderer
**Goal**: NetworkTopologySvg.vue rendert Netzwerktopologien aus JSON node-link Schema mit Icon-Bibliothek.
**Plans**: 2 plans

Plans:
- [x] 02-01-PLAN.md — networkTopologyIcons.js + NetworkTopologySvg.vue
- [x] 02-02-PLAN.md — PbqPlacement Integration + PbqRenderer Wiring

### Phase 3: Inline-Dropdown auf Diagramm
**Goal**: Dropdown-Auswahl direkt auf SVG-Topologie-Nodes positioniert.
**Plans**: 1 plan

Plans:
- [x] 03-01-PLAN.md — PbqPlacement inline picker + pbqScoringMode utility

### Phase 4: Multi-Panel Layout
**Goal**: Split-View zeigt CLI-Terminal und SVG-Topologie gleichzeitig nebeneinander.
**Plans**: 1 plan

Plans:
- [x] 04-01-PLAN.md — PbqMultiPanel.vue + responsive layout

### Phase 5: PBQ Author Tool
**Goal**: Dozenten koennen PBQ-Fragen mit Topologie und CLI-Aufgaben im Frontend erstellen.
**Plans**: 2 plans

Plans:
- [x] 05-01-PLAN.md — PbqAuthorTool.vue + Clipboard-as-bridge
- [x] 05-02-PLAN.md — QuestionForm PBQ integration + validation

### Phase 6: Instructor Notes
**Goal**: Dozenten koennen pro Frage eine Note hinterlegen die Studenten nach der Antwort sehen.
**Plans**: 1 plan

Plans:
- [x] 06-01-PLAN.md — note_visible column + NcNoteCard in learning modes

</details>

<details>
<summary>✅ v2.6 Live-Duell (Phase 7) - SHIPPED 2026-03-18</summary>

### Phase 7: Live-Duell
**Goal**: Zwei Spieler koennen in Echtzeit gegeneinander antreten.
**Plans**: 1 plan

Plans:
- [x] 07-01-PLAN.md — DuelMode.vue + DuelService.php + DuelController.php

</details>

<details>
<summary>✅ v3.0 Gameshow-Modi (Phases 8-13) - SHIPPED 2026-03-20</summary>

### Phase 8-13: Gameshow-Modi
**Plans**: 6 plans (collapsed)

</details>

<details>
<summary>✅ v3.1 UX-Konsolidierung (Phases 14-16) - SHIPPED 2026-03-21</summary>

### Phase 14-16: UX-Konsolidierung
**Plans**: 3 plans (collapsed)

</details>

<details>
<summary>✅ v3.2 VirtuProf KI-Assistent (Phases 17-21) - SHIPPED 2026-03-21</summary>

### Phase 17-21: VirtuProf KI-Assistent
**Plans**: 5 plans (collapsed)

</details>

<details>
<summary>✅ v4.0 Persoenlicher Lernbot (Phases 22-27) - SHIPPED 2026-03-21</summary>

### Phase 22-27: Persoenlicher Lernbot
**Plans**: 6 plans (collapsed)

</details>

<details>
<summary>✅ v5.0 Oldschool (Brettspiel-Modi) (Phases 28-31) - SHIPPED 2026-03-21</summary>

### Phase 28-31: Brettspiel-Modi
**Plans**: 4 plans (collapsed)

</details>

<details>
<summary>✅ v6.0 Abenteuer (Story-RPG) (Phases 32-35) - SHIPPED 2026-03-22</summary>

### Phase 32-35: Story-RPG
**Plans**: 4 plans (collapsed)

</details>

<details>
<summary>✅ v4.1 RAG Stufe 2 (Phases 36-39) - SHIPPED 2026-03-22</summary>

### Phase 36-39: RAG Stufe 2
**Plans**: 5 plans (collapsed)

</details>

<details>
<summary>✅ v6.1 KI-Erzaehler + Security-Kampagnen (Phases 40-43) - SHIPPED 2026-03-22</summary>

### Phase 40: KI-Erzaehler Engine
**Goal**: Gemini uebernimmt die Rolle des Erzaehlers in allen Kampagnen
**Plans**: 2 plans

Plans:
- [x] 40-01-PLAN.md — StoryEngineService global narrator, role-based prompts, freetext advancement
- [x] 40-02-PLAN.md — Campaign JSON activation + AbenteuerMode.vue freetext UI

### Phase 41: Security-Kampagnen Teil 1
**Goal**: SolarWinds, WannaCry, Log4Shell Kampagnen
**Plans**: 2 plans

Plans:
- [x] 41-01-PLAN.md — SolarWinds + WannaCry campaigns
- [x] 41-02-PLAN.md — Log4Shell campaign

### Phase 42: Security-Kampagnen Teil 2 + Kurs-Kampagnen
**Goal**: Colonial Pipeline, Equifax, A+, Linux+, CySA+ Kampagnen
**Plans**: 2 plans

Plans:
- [x] 42-01-PLAN.md — Colonial Pipeline + Equifax campaigns
- [x] 42-02-PLAN.md — A+ "Der erste Tag" + Linux+ "Server Down" + CySA+ "Zero Day"

### Phase 43: AI Security Content
**Goal**: Prompt Injection Pool + Meta-Kampagne "Der KI-Fluesterer"
**Plans**: 2 plans

Plans:
- [x] 43-01-PLAN.md — Prompt Injection question pool
- [x] 43-02-PLAN.md — "Der KI-Fluesterer" meta campaign

</details>

### 🚧 v6.2 Visual Identity + Charakter-Cast (In Progress)

**Milestone Goal:** Hybrid-CI aus Codex (Token-System, Komponenten) + Gemini (Atmosphaere, Emotionen) mit erweitertem Charakter-Cast aus realistischen IT-Workplace-Figuren. Die App bekommt ein Gesicht.

- [ ] **Phase 44: Design-Token-System + Narrative-Skin** - CSS-Token-Layer, Dark/Light, Motion-Utilities, Paper & Circuits Skin
- [ ] **Phase 45: Charakter-System** - 13 Figuren definieren, CharacterAvatar.vue Komponente
- [ ] **Phase 46: UI-Komponenten** - CampaignCard, DialogueStage, ModeIdentityBanner
- [ ] **Phase 47: Kampagnen-Integration** - Intros, NPC-Portraits, Workplace-Figuren in bestehende Kampagnen

## Phase Details

### Phase 44: Design-Token-System + Narrative-Skin
**Goal**: Die App verfuegt ueber ein konsistentes CSS-Token-System mit Farbpaletten, Dark/Light-Umschaltung, Motion-Utilities und einem narrativen Skin fuer den Abenteuer-Modus
**Depends on**: Phase 43 (v6.1 shipped)
**Requirements**: DS-01, DS-02, DS-03, DS-04
**Success Criteria** (what must be TRUE):
  1. Alle Farben der App (Primary, Ink, Cyan, Amber, Magenta, Danger, Green) sind als CSS-Custom-Properties definiert und werden in mindestens einer bestehenden Komponente genutzt statt hartcodierter Hex-Werte
  2. Der Abenteuer-Modus rendert im Dark-Theme und der Trainings-Modus im Light-Theme — der Wechsel erfolgt automatisch beim Moduswechsel ohne manuellen Toggle
  3. Animationen (fade, snap-in, pulse) funktionieren in allen Modi und werden bei aktiviertem `prefers-reduced-motion` durch sofortige Zustandswechsel ohne Bewegung ersetzt
  4. Der Abenteuer-Modus zeigt den "Paper & Circuits" Skin — sichtbar durch veraenderte Hintergruende, Borders und Typografie gegenueber dem Standard-Training-Look
**Plans**: 2 plans

Plans:
- [ ] 44-01: CSS-Token-Layer + Dark/Light + Motion-Utilities
- [ ] 44-02: Narrative-Skin "Paper & Circuits" fuer AbenteuerMode

### Phase 45: Charakter-System
**Goal**: 13 Charaktere mit definierten Persoenlichkeiten existieren als strukturierte Daten und werden durch eine wiederverwendbare SVG-Avatar-Komponente mit State-Machine visuell dargestellt
**Depends on**: Phase 44 (Token-System muss Farb-Paletten fuer Charakter-Zuweisung bereitstellen)
**Requirements**: CHAR-01, CHAR-02, CHAR-03, CHAR-04
**Success Criteria** (what must be TRUE):
  1. CharacterAvatar.vue rendert einen Charakter als SVG-Silhouette und wechselt sichtbar zwischen mindestens 3 States (idle, thinking, celebrate) — der Unterschied ist visuell erkennbar
  2. Die Character-Registry JSON enthaelt alle 13 Figuren mit ID, Name, Rolle, Farb-Palette, verfuegbaren States und Silhouetten-Referenz — ein fehlender Eintrag fuehrt zu einem Fallback-Avatar statt einem Fehler
  3. Die 7 Helden (NOVA, Architekt, Security-Agentin, Sysadmin, Helpdesk-Rookie, CHRONOS, Ghostline) sind visuell unterscheidbar durch verschiedene Silhouetten und Farbpaletten
  4. Die 6 Workplace-Figuren (DAU, Chef, DSGVO-Beauftragte, Uschi, Azubi, Externer Berater) haben jeweils einen erkennbaren visuellen Stil der zu ihrer Rolle passt
  5. CharacterAvatar.vue respektiert `prefers-reduced-motion` — State-Wechsel erfolgen ohne Animation wenn aktiviert
**Plans**: TBD

Plans:
- [ ] 45-01: Character-Registry JSON + CharacterAvatar.vue Komponente
- [ ] 45-02: 13 SVG-Silhouetten + State-Definitionen

### Phase 46: UI-Komponenten
**Goal**: Drei neue Vue-Komponenten liefern ein einheitliches visuelles Erlebnis fuer Kampagnen-Auswahl, NPC-Dialoge und Modus-Erkennung
**Depends on**: Phase 45 (CharacterAvatar wird in allen drei Komponenten verwendet)
**Requirements**: UI-01, UI-02, UI-03
**Success Criteria** (what must be TRUE):
  1. CampaignCard.vue zeigt pro Kampagne einen Dark-Gradient-Hintergrund, das Portrait des Hauptcharakters (via CharacterAvatar) und ein Difficulty-Badge — der User erkennt auf einen Blick Thema und Schwierigkeit
  2. DialogueStage.vue zeigt links ein Charakter-Portrait mit Emotions-Tag und rechts ein Sprechfeld — bei einem Sprecherwechsel aendert sich das Portrait und der Emotions-Tag passend zum neuen Sprecher
  3. ModeIdentityBanner.vue zeigt am oberen Rand jedes Lernmodus den Modus-Namen, den zugeordneten Mentor-Charakter und das aktuelle Lernziel — der User weiss jederzeit in welchem Modus er sich befindet
**Plans**: TBD

Plans:
- [ ] 46-01: CampaignCard.vue + DialogueStage.vue
- [ ] 46-02: ModeIdentityBanner.vue + Integration in Lernmodi

### Phase 47: Kampagnen-Integration
**Goal**: Alle bestehenden Kampagnen nutzen das neue visuelle System — mit Intro-Animationen, NPC-Portraits in Dialogen und Workplace-Figuren als wiederkehrende NPCs
**Depends on**: Phase 46 (UI-Komponenten muessen fertig sein)
**Requirements**: KI-01, KI-02, KI-03, KI-04
**Success Criteria** (what must be TRUE):
  1. Jede Kampagne startet mit einer 3-5 Sekunden CSS/SVG Intro-Animation (<100KB) die Thema und Atmosphaere etabliert — bei `prefers-reduced-motion` wird stattdessen ein statisches Titelbild gezeigt
  2. NPC-Dialoge in allen Kampagnen zeigen ein CharacterAvatar-Portrait neben der Sprechblase — der Avatar wechselt den State passend zum Dialog-Inhalt (z.B. alert bei Warnung, explain bei Erklaerung)
  3. Die 6 Workplace-Figuren erscheinen als NPCs in den zugewiesenen Kampagnen: Chef in Colonial Pipeline, DSGVO in Equifax, DAU in WannaCry, Uschi in A+ "Der erste Tag", Azubi in Log4Shell, Berater in SolarWinds
  4. Skill-Check Ergebnisse loesen eine sichtbare Charakter-Reaktion aus: Erfolg zeigt celebrate-State, Misserfolg zeigt alert-State des beteiligten NPCs
**Plans**: TBD

Plans:
- [ ] 47-01: Kampagnen-Intro Animationen (CSS/SVG)
- [ ] 47-02: NPC-Portraits + Workplace-Figuren in Kampagnen + Skill-Check Reaktionen

## Progress

**Execution Order:**
Phases execute in numeric order: 44 → 45 → 46 → 47

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 44. Design-Token-System + Narrative-Skin | v6.2 | 0/2 | Not started | - |
| 45. Charakter-System | v6.2 | 0/2 | Not started | - |
| 46. UI-Komponenten | v6.2 | 0/2 | Not started | - |
| 47. Kampagnen-Integration | v6.2 | 0/2 | Not started | - |
