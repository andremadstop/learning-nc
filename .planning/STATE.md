---
gsd_state_version: 1.0
milestone: v4.0
milestone_name: Housekeeping + Content-Rollout
status: executing
stopped_at: Completed 80-02-PLAN.md (SimulatorShell wrapper)
last_updated: "2026-03-25T10:32:27.280Z"
last_activity: 2026-03-25 — Completed 80-02 SimulatorShell wrapper
progress:
  total_phases: 53
  completed_phases: 31
  total_plans: 53
  completed_plans: 51
  percent: 50
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-25)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v12.0 Campaign Engine — Interaktives Kampagnen-RPG

## Current Position

Phase: 80 (in progress)
Plan: 02 of 4 complete
Status: Executing Phase 80 plans
Last activity: 2026-03-25 — Completed 80-02 SimulatorShell wrapper

Progress: [█████░░░░░] 50%

```
Phase 80: SimulatorShell + Wiring     [~] In progress (2/4 plans)
Phase 81: Quest-Map                   [ ] Not started
Phase 82: HUD + Timer + DauBot-UI     [ ] Not started
Phase 83: Kampagnen-Content           [ ] Not started
Phase 84: Coop-Backend                [ ] Not started
Phase 85: Coop-Frontend               [ ] Not started
```

## Accumulated Context

### Decisions

- Extract Vue component logic into pure JS utils for Vitest testing (no vue plugin configured)
- normalizeResult returns structured object for pure-function testing instead of direct $emit
- Quest-Map RPG + Escape-Room Challenges (beides mischen)
- 1 polierte Vorzeige-Kampagne ("Der grosse Ausfall")
- Basis-Coop (2-4 Spieler, Abstimmung ueber Entscheidungen)
- Viel Humor, stark ueberzeichnete Charaktere, Real-World-Probleme
- Singleplayer zuerst, Coop als eigene Phase (84-85)
- D3.js Submodule (d3-force, d3-selection, d3-zoom) fuer Quest-Map — Vue 2 kompatibel
- Coop via Polling (3s Intervall) — kein WebSocket, NC-Infrastruktur hat keinen WS-Server
- Coop tie-breaking: NOCH OFFEN — muss vor Phase 84 entschieden werden (random vs host wins)
- [Phase 80]: Extract Vue component logic into pure JS utils for Vitest testing

### Phase-Reihenfolge Rationale

- **Phase 80 zuerst**: SimulatorShell ist die Basis des gesamten RPG-Konzepts — ohne Simulator-Wiring laeuft keine Challenge
- **Phase 81 nach 80**: Quest-Map braucht befuellten stateBag aus echten Traversierungen fuer node-state-Berechnung
- **Phase 82 nach 80**: HUD und Timer brauchen echte stateBag-Daten (Items, Reputation, Timers)
- **Phase 83 nach 82**: Campaign-Content ist der Integration-Test fuer Phases 80-82 — erst wenn alles steht Content schreiben
- **Phase 84 parallel zu 83 moeglich**: Coop-Backend hat keine Abhaengigkeit von Phase 82/83
- **Phase 85 nach 84**: Coop-Frontend braucht die Backend-Endpoints

### Critical Pitfalls (aus Research)

- **C1 KRITISCH**: D3/Vue 2 DOM-Konflikt in Phase 81 — D3 bekommt eigenes `<svg ref="questMap">`, nie in `data()` speichern
- **C2 KRITISCH**: Coop Poll Double-Advance Race Condition in Phase 85 — `coopAdvancing = true` VOR jedem await setzen, `clearInterval` als ERSTE Zeile
- **C3 KRITISCH**: Simulator beforeDestroy-Hooks in Phase 80 — alle 7 Simulatoren pruefen und nachrueesten
- **C4 KRITISCH**: Campaign JSON Schema-Brechung in Phase 83 — version-Feld von Tag 1, Node-IDs unveraenderlich nach Publish
- **H1 HOCH**: Vue 2 nested stateBag Mutations in Phase 80 — immer `this.stateBag = { ...response.stateBag }` statt Object.assign

### Existing Architecture (v10.0 Backend 85% fertig)

- CampaignGraphService: Graph-Traversal, State-Bag, Effects, Conditions, Timers, Roles
- StoryEngineService: Campaign Loading, Graph vs Linear, 4 Graph-Endpoints
- DauBotService: 5 Fehlerkategorien, deterministische Generierung
- StoryController: 13 API-Endpoints
- CampaignState: userId, campaignId, graphPosition, stateBag (JSON), characterClass, score
- AbenteuerMode.vue: Phase-System (campaign-select -> intro -> character-select -> scene), 1303 Zeilen
- 7 Simulatoren: DnsResolver, FirewallBuilder, PortScanner, RoutingTable, NatTable, WiresharkLite, AuthFlowSimulator
- test_graph_campaign.json: Referenz Graph-Format

### Pending Todos

- Coop tie-breaking Regel festlegen vor Phase 84 (random vs host wins vs first vote wins)
- anime.js v4 Import-Pfad unter Webpack 5 verifizieren bei Phase 81 Start (`import anime from 'animejs'` vs `import { animate } from 'animejs'`)
- D3-Bundle-Groesse nach Installation pruefen: `npx webpack --analyze`, Ziel < 50 KB

### Blockers/Concerns

- Coop tie-breaking: unentschieden, muss vor Phase 84 als explizite Entscheidung dokumentiert sein
- AbenteuerMode.vue Komplexitaet: wird bei 1303 Zeilen noch groesser — SimulatorShell, QuestMap, HUD als separate Komponenten extrahieren (nicht inline hinzufuegen)

## Session Continuity

Last session: 2026-03-25T10:32:26.610Z
Stopped at: Completed 80-02-PLAN.md (SimulatorShell wrapper)
Resume file: None
Next action: Execute 80-03-PLAN.md (AbenteuerMode graph-mode wiring)
