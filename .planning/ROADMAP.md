# Roadmap: Learning-NC

## Milestones

- 📋 **v2.3 PBQ OnVUE-Niveau Upgrade** — Phases 1–6 (planned)

## Phases

### 📋 v2.3 PBQ OnVUE-Niveau Upgrade (Planned)

**Milestone Goal:** PBQ-Simulationen auf OnVUE-Niveau bringen — CLI State Machine, SVG-Topologie, Instructor Notes. Killerfeature für Dozenten und Schüler.

#### Phase 1: CLI State Machine

**Goal**: PbqCli unterstützt Cisco IOS Modi (exec/config/config-if), zeigt Fehlermeldungen für unbekannte Befehle und verarbeitet command_outputs mit Feedback-Text.
**Depends on**: Nothing
**Requirements**: CLI-01, CLI-02, CLI-03, CLI-04, CLI-05
**Success Criteria** (what must be TRUE):
  1. `conf t` wechselt von `Router>` zu `Router(config)#` Prompt
  2. `interface FastEthernet0/0` wechselt zu `Router(config-if)#`
  3. `exit` wechselt zurück zum vorherigen Modus
  4. Unbekannte Befehle zeigen `% Invalid command` Fehlermeldung
  5. Definierte Befehle in `command_outputs` zeigen konfigurierten Feedback-Text
  6. domain-Feld (`cisco_ios`/`linux`/`windows`/`sql`/`generic`) bestimmt Prompt-Schema
**Plans**: 2 plans

Plans:
- [ ] 01-01-PLAN.md — cliStateMachine.js utility (DOMAIN_SCHEMAS, evaluateCommand, getPrompt)
- [ ] 01-02-PLAN.md — PbqCli.vue state machine integration (mode transitions, output rendering, deploy + verify)

#### Phase 2: SVG Topology Renderer

**Goal**: NetworkTopologySvg.vue rendert Netzwerktopologien aus JSON node-link Schema mit Icon-Bibliothek (8 Gerätetypen). PbqPlacement nutzt neue Komponente.
**Depends on**: Nothing (parallel zu Phase 1)
**Requirements**: SVG-01, SVG-02, SVG-03, SVG-04
**Success Criteria** (what must be TRUE):
  1. JSON node-link Schema (`nodes[{id,type,label,x,y}]`, `links[{from,to}]`) wird als SVG gerendert
  2. Alle 8 Icon-Typen (router/switch/firewall/server/cloud/workstation/ap/wre) werden korrekt dargestellt
  3. Klickbare Hotspots auf Nodes positioniert via `getScreenCTM()` nach viewBox-Skalierung
  4. PbqPlacement kann SVG-Topologie statt Bild nutzen
  5. Keine `v-html` Nutzung (NC CSP-konform)
**Plans**: 2 plans

Plans:
- [ ] 02-01-PLAN.md — networkTopologyIcons.js + NetworkTopologySvg.vue Komponente + Unit Tests
- [ ] 02-02-PLAN.md — PbqPlacement Integration + PbqRenderer Wiring + Deploy + Verify

#### Phase 3: Inline-Dropdown auf Diagramm

**Goal**: Dropdown-Auswahl direkt auf SVG-Topologie-Nodes positioniert, mit scoring_mode (strict/partial).
**Depends on**: Phase 2
**Requirements**: DROP-01, DROP-02, DROP-03
**Success Criteria** (what must be TRUE):
  1. Dropdown erscheint an Position des geklickten Nodes
  2. scoring_mode=strict: nur exakte Treffer geben Punkte
  3. scoring_mode=partial: Teilpunkte bei Teiltreffern
  4. Auswahl wird korrekt gespeichert und gewertet
**Plans**: 1 plan

Plans:
- [ ] 03-01-PLAN.md — PbqPlacement inline picker + pbqScoringMode utility + browser verify

#### Phase 4: Multi-Panel Layout

**Goal**: Split-View zeigt CLI-Terminal und SVG-Topologie gleichzeitig nebeneinander (config-Flag multi_panel).
**Depends on**: Phase 1, Phase 2
**Requirements**: PANEL-01, PANEL-02
**Success Criteria** (what must be TRUE):
  1. multi_panel=true zeigt CLI links, Topologie rechts nebeneinander
  2. Responsive: auf kleinen Screens untereinander
  3. Beide Panels funktional und interaktiv gleichzeitig
**Plans**: 1 plan

Plans:
- [ ] 04-01-PLAN.md — PbqMultiPanel.vue + PbqRenderer Erweiterung + responsive layout + browser verify

#### Phase 5: PBQ Author Tool

**Goal**: Visueller Editor in der App zum Erstellen von PBQ-Fragen-Configs (JSON) ohne manuelle JSON-Eingabe.
**Depends on**: Phase 1, Phase 2, Phase 3, Phase 4
**Requirements**: AUTHOR-01, AUTHOR-02, AUTHOR-03
**Success Criteria** (what must be TRUE):
  1. Dozent kann PBQ-Typ auswählen (cli/placement/dropdown/multi_panel)
  2. Formularfelder erzeugen gültiges PBQ-JSON
  3. Live-Vorschau der resultierenden Simulation
  4. Generiertes JSON kann in QuestionForm eingefügt werden
**Plans**: 2 plans

Plans:
- [ ] 05-01-PLAN.md — PbqAuthorTool.vue: subtype selector, per-subtype form fields, generatedConfig/generatedJson computed, unit tests
- [ ] 05-02-PLAN.md — Live preview via PbqRenderer + QuestionForm PBQ integration (subtype select, config textarea, NcDialog)

#### Phase 6: Instructor Notes

**Goal**: Pro Frage ein Kommentarfeld für Dozenten, mit Sichtbarkeits-Toggle für Schüler. In allen Lernmodi angezeigt wenn sichtbar.
**Depends on**: Nothing (unabhängig von PBQ-Phasen)
**Requirements**: NOTE-01, NOTE-02, NOTE-03, NOTE-04
**Success Criteria** (what must be TRUE):
  1. Dozent kann Notiz pro Frage erstellen/bearbeiten (QuestionForm)
  2. Toggle schaltet Sichtbarkeit für Schüler ein/aus
  3. Schüler sehen Notiz in TrainingMode, ExamMode, LeitnerMode, SmartQueue (wenn sichtbar)
  4. DB-Migration läuft ohne Datenverlust durch
**Plans**: TBD

Plans:
- [ ] 06-01: DB Migration + Backend (instructor_note + note_visible)
- [ ] 06-02: Frontend — QuestionForm Editor + Anzeige in allen Modi

## Progress

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. CLI State Machine | 2/2 | Complete   | 2026-03-16 |
| 2. SVG Topology Renderer | 2/2 | Complete   | 2026-03-16 |
| 3. Inline-Dropdown | 1/1 | Complete   | 2026-03-17 |
| 4. Multi-Panel Layout | 1/1 | Complete   | 2026-03-17 |
| 5. PBQ Author Tool | 2/2 | Complete   | 2026-03-17 |
| 6. Instructor Notes | 0/2 | Not started | - |
