# Roadmap: Learning-NC

## Milestones

- ✅ **v2.3 PBQ OnVUE-Niveau Upgrade** — Phases 1-6 (shipped 2026-03-17)
- ✅ **v2.6 Live-Duell** — Phase 7 (shipped 2026-03-18)
- ✅ **v3.0 Gameshow-Modi** — Phases 8-13 (shipped 2026-03-20)
- ✅ **v3.1 UX-Konsolidierung** — Phases 14-16 (shipped 2026-03-21)
- 📋 **v3.2 VirtuProf KI-Assistent** — Phases 17-21 (planned)

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

<details>
<summary>✅ v3.1 UX-Konsolidierung (Phases 14-16) - SHIPPED 2026-03-21</summary>

### Phase 14: Training-Merge
**Goal**: User lernt Multiple-Choice und Wahr/Falsch in einem einzigen Trainingsmodus mit optionalen Swipe-Animationen
**Plans**: 2 plans

Plans:
- [x] 14-01-PLAN.md — TrainingMode: wfMode-Toggle, Swipe-Animationen, True/False-Buttons
- [x] 14-02-PLAN.md — SwipeMode.vue loeschen, App.vue + CourseDetail + PoolList umbiegen

### Phase 15: Arena
**Goal**: User findet Duell, Sprint und Elimination unter einem einzigen Menuepunkt und waehlt dort den gewuenschten Wettkampfmodus
**Plans**: 1 plan

Plans:
- [x] 15-01-PLAN.md — ArenaSelector.vue + CourseDetail Tab-Merge + App.vue anpassen

### Phase 16: Session-Robustheit
**Goal**: Multiplayer-Sessions (Duell, Sprint, Elimination) sind resilient gegen Abbruch, Disconnect und verwaiste Sessions
**Plans**: 2 plans

Plans:
- [x] 16-01-PLAN.md — Frontend: Abbrechen-Button, Disconnect-Overlay, localStorage Recovery (DuelMode + GameshowMode)
- [x] 16-02-PLAN.md — Backend: Stale-Session-Cleanup nach 5 Minuten (DuelService + GameshowService)

</details>

### 📋 v3.2 VirtuProf KI-Assistent (Planned)

**Milestone Goal:** VirtuProf wird vom Script-basierten FAQ-Bot zum echten KI-Assistenten mit Gemini Flash API, RAG-Context, mehrsprachigen Antworten, Prompt-Injection-Schutz und DSGVO-Compliance.

- [x] **Phase 17: Gemini Backend + Security** - GeminiService mit 5-Layer Prompt-Injection-Schutz und Admin-Settings (completed 2026-03-21)
- [ ] **Phase 18: RAG-Context** - Context-Builder laedt Pool-, Kurs- und User-Daten fuer LLM-Kontext
- [ ] **Phase 19: Chat-UI** - Freitext-Chat in VirtuProfBubble mit Typing-Indikator und Session-Verlauf
- [ ] **Phase 20: Ticket-Triage** - Automatische Klassifizierung und Beantwortung von Support-Tickets
- [ ] **Phase 21: Datenschutz & Compliance** - Opt-in Flow, Admin-Toggle, Privacy-Dokumentation

## Phase Details

### Phase 17: Gemini Backend + Security
**Goal**: GeminiService kapselt Gemini Flash API mit vollstaendigem 5-Layer Prompt-Injection-Schutz — sicher genug zum Einschalten, noch bevor User-Daten fliessen
**Depends on**: Phase 16 (v3.1 shipped)
**Requirements**: GEM-01, GEM-02, GEM-03, GEM-04, SEC-01, SEC-02, SEC-03, SEC-04, SEC-05
**Success Criteria** (what must be TRUE):
  1. Admin kann einen Gemini API-Key in den Nextcloud-Einstellungen eintragen, und VirtuProf antwortet auf eine einfache Frage auf Englisch
  2. Eine Anfrage mit 501+ Zeichen, HTML-Tags oder Script-Injections im User-Input wird abgelehnt (HTTP 400) bevor sie die API erreicht
  3. VirtuProf antwortet in der content_language des Users (DE/EN/RU/AR) ohne manuellen Sprachwechsel
  4. Bei API-Timeout oder -Fehler erscheint keine Fehlermeldung — VirtuProf gibt stattdessen eine passende FAQ-Antwort zurueck
  5. Jede KI-Anfrage erzeugt einen Audit-Log-Eintrag mit Input, Output, Timestamp und UserId
**Plans**: 2 plans

Plans:
- [ ] 17-01-PLAN.md — GeminiService.php + 5-layer security stack + Admin Settings API key wiring
- [ ] 17-02-PLAN.md — VirtuProfController::chat() endpoint + route registration + deploy

### Phase 18: RAG-Context
**Goal**: VirtuProf kennt den Lernkontext des Users — welcher Pool, welcher Kurs, welche Fragen er zuletzt falsch beantwortet hat
**Depends on**: Phase 17 (GeminiService muss existieren)
**Requirements**: RAG-01, RAG-02, RAG-03, RAG-04
**Success Criteria** (what must be TRUE):
  1. Wenn ein User VirtuProf in einem aktiven Pool fragt, enthaelt die Antwort Bezug zu Fragen oder Themen aus diesem Pool
  2. Fragt der User "Warum habe ich diese Frage falsch?", nennt VirtuProf die konkrete falsche Antwort und erklaert die korrekte
  3. VirtuProf nennt auf Anfrage den Leitner-Box-Status des Users (z.B. "Du hast 12 Karten in Box 1")
  4. Bei sehr langen Pools (>100 Fragen) wird der Kontext automatisch auf die relevantesten Inhalte begrenzt — kein API-Fehler wegen Token-Overflow
**Plans**: TBD

### Phase 19: Chat-UI
**Goal**: User kann mit VirtuProf per Freitext chatten — innerhalb der bestehenden VirtuProfBubble, ohne separate Chat-Seite
**Depends on**: Phase 18 (RAG-Context muss fuer qualitaetsvolle Antworten bereitstehen)
**Requirements**: CHAT-01, CHAT-02, CHAT-03, CHAT-04, CHAT-05
**Success Criteria** (what must be TRUE):
  1. In der VirtuProfBubble erscheint unterhalb der FAQ-Buttons ein Textfeld mit Send-Button, in das der User eine freie Frage eintippen kann
  2. Nach dem Absenden erscheint die Antwort als Chat-Bubble — User-Frage links/grau, VirtuProf-Antwort rechts/farbig
  3. Waehrend VirtuProf antwortet sieht der User die talk-Animation des Avatars und drei pulsierende Punkte im Chat
  4. Der gesamte Gespraechsverlauf der aktuellen Session (bis 20 Nachrichten) bleibt sichtbar, wenn der User durch die Bubble scrollt
  5. Bei einer falschen Antwort in einem Lernmodus erscheint direkt ein "Erklaere diese Frage"-Button, der VirtuProf mit vorausgefuelltem Kontext oeffnet
**Plans**: TBD

### Phase 20: Ticket-Triage
**Goal**: Support-Tickets werden automatisch klassifiziert und einfache Fragen automatisch beantwortet — Admin-Postfach entlastet
**Depends on**: Phase 17 (GeminiService), Phase 18 (RAG-Context fuer bessere FAQ-Matches)
**Requirements**: TRIAGE-01, TRIAGE-02, TRIAGE-03, TRIAGE-04
**Success Criteria** (what must be TRUE):
  1. Jedes neue Support-Ticket zeigt in der Admin-Inbox ein automatisch gesetztes Label: FAQ, Bug, Feature oder Unclear
  2. Tickets mit Label FAQ haben eine automatisch generierte Antwort als Draft — Admin kann sie mit einem Klick absenden
  3. Tickets mit Label Bug oder Feature sind als "needs_review" markiert und erscheinen oben in der Inbox-Sortierung
  4. Wenn die KI-Klassifizierung unsicher ist (Confidence < 0.7), wird dem User eine Rueckfrage gestellt statt einer Auto-Antwort
**Plans**: TBD

### Phase 21: Datenschutz & Compliance
**Goal**: KI-Feature ist DSGVO-konform ausgeliefert: Opt-in vor erster Nutzung, Admin-Kontrolle, Privacy-Dokumentation vollstaendig
**Depends on**: Phase 19 (Chat-UI muss stehen fuer Opt-in-Dialog), Phase 20 (alle KI-Features komplett)
**Requirements**: PRIV-01, PRIV-02, PRIV-03, PRIV-04, PRIV-05
**Success Criteria** (what must be TRUE):
  1. Beim ersten Klick auf das Freitext-Feld erscheint ein Hinweis-Dialog ("Deine Frage wird an Google Gemini gesendet") — erst nach "Zustimmen" wird die Anfrage abgeschickt
  2. Ein Admin kann das KI-Feature global deaktivieren, sodass das Freitext-Feld bei allen Usern verschwindet
  3. In info.xml und README.md ist ein Privacy-Abschnitt vorhanden, der erklaert welche Daten an Gemini gehen
  4. Eine Analyse des LLM-Kontexts zeigt: kein Username, keine E-Mail-Adresse, keine User-ID, kein Passwort-Hash im Context-Payload
  5. In den Admin-Settings steht ein Hinweis auf das Google DPA mit Link zur Google-Dokumentation
**Plans**: TBD

## Progress

**Execution Order:**
Phases execute sequentially: 17 → 18 → 19 → 20 → 21. Phase 18 depends on Phase 17. Phase 19 depends on Phase 18. Phase 20 depends on Phases 17 and 18. Phase 21 depends on Phases 19 and 20.

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
| 14. Training-Merge | v3.1 | 2/2 | Complete | 2026-03-21 |
| 15. Arena | v3.1 | 1/1 | Complete | 2026-03-21 |
| 16. Session-Robustheit | v3.1 | 2/2 | Complete | 2026-03-21 |
| 17. Gemini Backend + Security | 2/2 | Complete   | 2026-03-21 | - |
| 18. RAG-Context | v3.2 | 0/TBD | Not started | - |
| 19. Chat-UI | v3.2 | 0/TBD | Not started | - |
| 20. Ticket-Triage | v3.2 | 0/TBD | Not started | - |
| 21. Datenschutz & Compliance | v3.2 | 0/TBD | Not started | - |
