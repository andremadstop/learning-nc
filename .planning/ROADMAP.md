# Roadmap: Learning-NC

## Milestones

- ✅ **v2.3 PBQ OnVUE-Niveau Upgrade** — Phases 1-6 (shipped 2026-03-17)
- ✅ **v2.6 Live-Duell** — Phase 7 (shipped 2026-03-18)
- ✅ **v3.0 Gameshow-Modi** — Phases 8-13 (shipped 2026-03-20)
- 🚧 **v3.1 UX-Konsolidierung** — Phases 14-16 (in progress)

## Phases

<details>
<summary>✅ v2.3 PBQ OnVUE-Niveau Upgrade (Phases 1-6) - SHIPPED 2026-03-17</summary>

### Phase 1: CLI State Machine
**Goal**: PbqCli unterstützt Cisco IOS Modi (exec/config/config-if), zeigt Fehlermeldungen für unbekannte Befehle und verarbeitet command_outputs mit Feedback-Text.
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
**Goal**: Visueller Editor zum Erstellen von PBQ-Fragen-Configs.
**Plans**: 2 plans

Plans:
- [x] 05-01-PLAN.md — PbqAuthorTool.vue
- [x] 05-02-PLAN.md — Live preview + QuestionForm integration

### Phase 6: Instructor Notes
**Goal**: Pro Frage ein Kommentarfeld für Dozenten, mit Sichtbarkeits-Toggle.
**Plans**: 2 plans

Plans:
- [x] 06-01-PLAN.md — DB Migration + Backend
- [x] 06-02-PLAN.md — Frontend: QuestionForm Editor + note display

</details>

<details>
<summary>✅ v2.6 Live-Duell (Phase 7) - SHIPPED 2026-03-18</summary>

### Phase 7: Live-Duell
**Goal**: Echtzeit-Duell-Modus im Wahr/Falsch-Stil für zwei Spieler.
**Plans**: 3 plans

Plans:
- [x] 07-01-PLAN.md — Backend: DB-Schema, API-Endpoints, Short Polling
- [x] 07-02-PLAN.md — Frontend: DuelMode.vue Komponente
- [x] 07-03-PLAN.md — Integration: App.vue Routing, Rematch-Flow, Deploy

</details>

<details>
<summary>✅ v3.0 Gameshow-Modi (Phases 8-13) - SHIPPED 2026-03-20</summary>

### Phase 8: N-Player Session Backend
**Goal**: Players can create, join, and synchronize gameshow sessions for 2-5 players
**Plans**: 2 plans

Plans:
- [x] 08-01-PLAN.md — DB Migration + Entities + Mappers (3 tables, 6 ORM classes)
- [x] 08-02-PLAN.md — GameshowService + GameshowController + Routes (full API)

### Phase 9: Sprint Mode
**Goal**: Players compete in a speed-based quiz where the fastest correct answer scores highest
**Plans**: 2 plans

Plans:
- [x] 09-01-PLAN.md — Sprint scoring backend + GameshowMode.vue (lobby, question, feedback)
- [x] 09-02-PLAN.md — Animated leaderboard, final scoreboard, crown icon, CourseDetail wiring

### Phase 10: Elimination Mode
**Goal**: Players survive a last-one-standing quiz where wrong answers cost lives
**Plans**: 3 plans

Plans:
- [x] 10-01-PLAN.md — Elimination scoring + lives system
- [x] 10-02-PLAN.md — Sudden death logic + elimination animations
- [x] 10-03-PLAN.md — Integration + testing

### Phase 11: Spectacle Animations
**Goal**: Both game modes feel like a TV gameshow with dramatic visual effects
**Plans**: 1 plan

Plans:
- [x] 11-01-PLAN.md — CSS keyframes + JS class toggles for all spectacle animations

### Phase 12: VirtuProf Showmaster
**Goal**: VirtuProf acts as a live gameshow host, commenting on the action as it unfolds
**Plans**: 1 plan

Plans:
- [x] 12-01-PLAN.md — Gameshow trigger scripts + GameshowMode.vue VirtuProf wiring

### Phase 13: XP Integration & Polish
**Goal**: Gameshow modes are fully integrated into the Learning-NC ecosystem as first-class learning activities
**Plans**: 2 plans

Plans:
- [x] 13-01-PLAN.md — Backend: XP award on finish + results history API
- [x] 13-02-PLAN.md — Frontend: Standalone pool access + history display

</details>

### 🚧 v3.1 UX-Konsolidierung (In Progress)

**Milestone Goal:** Lernmodi zusammenfuehren und die Multiplayer-Session-UX robuster machen. Weniger Modi-Verwirrung, stabilere Sessions.

- [ ] **Phase 14: Training-Merge** - SwipeMode wird in TrainingMode integriert, ein Modus fuer MC und Wahr/Falsch
- [ ] **Phase 15: Arena** - Duell und Gameshow unter einem gemeinsamen Menuepunkt zusammenfassen
- [ ] **Phase 16: Session-Robustheit** - Abbruch, Reconnect, Timeout-Handling und Session-Cleanup

## Phase Details

### Phase 14: Training-Merge
**Goal**: User lernt Multiple-Choice und Wahr/Falsch in einem einzigen Trainingsmodus mit optionalen Swipe-Animationen
**Depends on**: Nothing (independent of other v3.1 phases)
**Requirements**: TMERGE-01, TMERGE-02, TMERGE-03, TMERGE-04
**Success Criteria** (what must be TRUE):
  1. User kann innerhalb von TrainingMode zwischen Multiple-Choice und Wahr/Falsch umschalten, ohne den Modus zu verlassen
  2. Im Wahr/Falsch-Modus funktionieren Swipe-Animationen (Karte wegfliegen, Farbfeedback) genauso wie im ehemaligen SwipeMode
  3. SwipeMode.vue existiert nicht mehr, und alle Einstiegspunkte (CourseDetail Tabs, App.vue Routing, Standalone-Links) fuehren zum TrainingMode
  4. Wahr/Falsch-Fragen koennen per Wisch-Geste ODER per Button beantwortet werden
**Plans**: 2 plans

Plans:
- [ ] 14-01-PLAN.md — TrainingMode: wfMode-Toggle, Swipe-Animationen, True/False-Buttons
- [ ] 14-02-PLAN.md — SwipeMode.vue loeschen, App.vue + CourseDetail + PoolList umbiegen

### Phase 15: Arena
**Goal**: User findet Duell, Sprint und Elimination unter einem einzigen Menuepunkt und waehlt dort den gewuenschten Wettkampfmodus
**Depends on**: Nothing (independent of Phase 14, but should execute after it for cleaner diffs)
**Requirements**: ARENA-01, ARENA-02, ARENA-03, ARENA-04
**Success Criteria** (what must be TRUE):
  1. In CourseDetail erscheint ein einzelner Tab (statt separater Duell- und Gameshow-Tabs) mit einem passenden Namen
  2. Innerhalb des Arena-Bereichs sieht der User drei Karten/Buttons: Duell (1v1), Sprint (2-5), Elimination (2-5), jeweils mit Icon und Kurzbeschreibung
  3. Die Auswahl eines Modus fuehrt direkt in den jeweiligen Lobby/Start-Flow — kein zusaetzlicher Navigationsschritt
**Plans**: TBD

Plans:
- [ ] 15-01: TBD

### Phase 16: Session-Robustheit
**Goal**: Multiplayer-Sessions (Duell, Sprint, Elimination) sind resilient gegen Abbruch, Disconnect und verwaiste Sessions
**Depends on**: Phase 15 (Arena-Routing muss stehen, damit Abbruch-/Zurueck-Buttons korrekt navigieren)
**Requirements**: ROBUST-01, ROBUST-02, ROBUST-03, ROBUST-04, ROBUST-05
**Success Criteria** (what must be TRUE):
  1. Waehrend eines laufenden Spiels ist jederzeit ein Abbrechen-Button sichtbar, der das Spiel beendet und zurueck zur Arena navigiert
  2. Nach Spielende zeigt der Ergebnis-Screen sowohl einen "Neues Spiel"-Button als auch einen "Zurueck"-Button
  3. Wenn alle Gegner die Verbindung verlieren (Polling-Timeout), erscheint innerhalb von 30 Sekunden eine klare Meldung statt Endlos-Warten
  4. Ein Spieler der die Seite verlaesst und zurueckkehrt, wird automatisch in seine laufende Session zurueckgefuehrt
  5. Sessions ohne Aktivitaet von allen Spielern werden nach 5 Minuten automatisch auf "expired" gesetzt
**Plans**: TBD

Plans:
- [ ] 16-01: TBD
- [ ] 16-02: TBD

## Progress

**Execution Order:**
Phases execute sequentially: 14 -> 15 -> 16. Phase 16 depends on Phase 15. Phases 14 and 15 are independent but execute in order for cleaner diffs.

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1. CLI State Machine | v2.3 | 2/2 | Complete | 2026-03-16 |
| 2. SVG Topology Renderer | v2.3 | 2/2 | Complete | 2026-03-16 |
| 3. Inline-Dropdown | v2.3 | 1/1 | Complete | 2026-03-17 |
| 4. Multi-Panel Layout | v2.3 | 1/1 | Complete | 2026-03-17 |
| 5. PBQ Author Tool | v2.3 | 2/2 | Complete | 2026-03-17 |
| 6. Instructor Notes | v2.3 | 2/2 | Complete | 2026-03-17 |
| 7. Live-Duell | v2.6 | 3/3 | Complete | 2026-03-18 |
| 8. N-Player Session Backend | v3.0 | 2/2 | Complete | 2026-03-20 |
| 9. Sprint Mode | v3.0 | 2/2 | Complete | 2026-03-20 |
| 10. Elimination Mode | v3.0 | 3/3 | Complete | 2026-03-20 |
| 11. Spectacle Animations | v3.0 | 1/1 | Complete | 2026-03-20 |
| 12. VirtuProf Showmaster | v3.0 | 1/1 | Complete | 2026-03-20 |
| 13. XP Integration & Polish | v3.0 | 2/2 | Complete | 2026-03-20 |
| 14. Training-Merge | v3.1 | 0/2 | Not started | - |
| 15. Arena | v3.1 | 0/1 | Not started | - |
| 16. Session-Robustheit | v3.1 | 0/2 | Not started | - |
