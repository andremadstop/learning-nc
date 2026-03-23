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
- 🚧 **v7.0 Hacker-Zeitreise "Hack Through Time"** — Phases 48-51 (in progress)

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

<details>
<summary>✅ v6.2 Visual Identity + Charakter-Cast (Phases 44-47) - SHIPPED 2026-03-23</summary>

### Phase 44: Design-Token-System + Narrative-Skin
**Goal**: CSS-Token-Layer, Dark/Light, Motion-Utilities, Paper & Circuits Skin
**Plans**: 2 plans

Plans:
- [x] 44-01: CSS-Token-Layer + Dark/Light + Motion-Utilities
- [x] 44-02: Narrative-Skin "Paper & Circuits" fuer AbenteuerMode

### Phase 45: Charakter-System
**Goal**: 13 Charaktere mit SVG-Avatar-Komponente und State-Machine
**Plans**: 1 plan

Plans:
- [x] 45-01-PLAN.md — Character-Registry + CharacterAvatar.vue mit 13 SVG-Silhouetten

### Phase 46: UI-Komponenten
**Goal**: CampaignCard, DialogueStage, ModeIdentityBanner
**Plans**: 2 plans

Plans:
- [x] 46-01: CampaignCard.vue + DialogueStage.vue
- [x] 46-02: ModeIdentityBanner.vue + Integration in Lernmodi

### Phase 47: Kampagnen-Integration
**Goal**: Intros, NPC-Portraits, Workplace-Figuren in bestehende Kampagnen
**Plans**: 2 plans

Plans:
- [x] 47-01-PLAN.md — CampaignIntro.vue + Workplace NPC assignments in campaign JSONs
- [x] 47-02-PLAN.md — AbenteuerMode integration: intro phase, DialogueStage NPC dialogs, skill-check reactions

</details>

### v7.0 Hacker-Zeitreise "Hack Through Time" (In Progress)

**Milestone Goal:** Eigenes Spielformat — Zeitreise durch 7 IT-Security-Epochen mit epochen-spezifischen CSS-Themes, CHRONOS als KI-Guide, 4 Charakter-Klassen und 25 Szenen basierend auf echten Hacks.

- [x] **Phase 48: Engine + Charakter-Klassen** - HackThroughTime.vue, Epochen-Fortschritt, Museum, Skill-Checks, 4 Klassen mit Epochen-Affinitaet (completed 2026-03-23)
- [ ] **Phase 49: Epochen-Themes** - 7 CSS-Themes (Terminal, DOS, Netscape, XP, Dark Modern, Cloud, Hologramm)
- [ ] **Phase 50: Kampagnen Retro** - Blue Box, WarGames, The Worm, Bobby Tables (1960er-2000er)
- [ ] **Phase 51: Kampagnen Modern** - Shadow Brokers, Supply Chain, Quantum Dawn (2010er-Zukunft)

## Phase Details

### Phase 48: Engine + Charakter-Klassen
**Goal**: Spieler koennen eine Zeitreise durch IT-Security-Epochen starten, ihren Fortschritt verfolgen, zwischen Szenen historische Fakten im Museum lesen und am Ende jeder Epoche ihr Wissen in Skill-Checks pruefen — mit klassenspezifischen Vorteilen
**Depends on**: Phase 47 (v6.2 shipped)
**Requirements**: ENG-01, ENG-02, ENG-03, ENG-04, ENG-05, CHAR-01, CHAR-02
**Success Criteria** (what must be TRUE):
  1. HackThroughTime.vue zeigt eine Epochen-Navigation mit 7 Epochen (1960er bis Zukunft) und CHRONOS als sichtbaren Guide — der Spieler erkennt sofort die Zeitreise-Struktur
  2. Das data-epoch Attribut wechselt beim Epochen-Uebergang und aktiviert epochen-spezifische CSS-Variablen (--epoch-* Tokens) — visuell sichtbarer Theme-Wechsel
  3. Der Spieler sieht nach Abschluss einer Epoche seinen Gesamt-Score und welche Epochen bereits abgeschlossen sind — der Fortschritt bleibt nach Seiten-Reload erhalten
  4. Zwischen Szenen erscheint eine Museum-Zwischensequenz mit historischen Fakten zum gerade erlebten Hack — mindestens Datum, beteiligte Personen und Auswirkung
  5. Am Ende jeder Epoche gibt es einen Pool-basierten Skill-Check, wobei die gewaehlte Charakter-Klasse (Phreaker, Script-Kiddie/Ethical Hacker, Red Teamer, Quantum Defender) die Schwierigkeit in "ihrer" Epoche reduziert
**Plans**: 2 plans

Plans:
- [x] 48-01-PLAN.md — Backend: Epoch data, character classes, museum facts, DB migration, HackThroughTimeService + Controller + Routes
- [ ] 48-02-PLAN.md — Frontend: HackThroughTime.vue + epoch-tokens.css + App.vue wiring + human verification

### Phase 49: Epochen-Themes
**Goal**: Jede der 7 Epochen hat ein visuell unverwechselbares CSS-Theme das die Aera authentisch repraesentiert — vom gruenen Terminal der 60er bis zum Hologramm-Look der Zukunft
**Depends on**: Phase 48 (EpochTheme-System mit --epoch-* Tokens muss existieren)
**Requirements**: THEME-01, THEME-02, THEME-03, THEME-04, THEME-05, THEME-06, THEME-07
**Success Criteria** (what must be TRUE):
  1. Die 1960er-Epoche zeigt gruen-auf-schwarz Monospace-Text mit Scanline-Effekt — sofort als Retro-Terminal erkennbar
  2. Die 1980er-Epoche zeigt blau-weissen DOS-Prompt mit ASCII-Art Rahmen und blinkendem Cursor — WarGames-Atmosphaere
  3. Die 1990er-Epoche zeigt Netscape-graue Oberflaeche mit Times New Roman, 3D-Buttons und Statusbar — fruehes Web
  4. Die 2000er-Epoche zeigt XP-Luna blau-gruen mit Tahoma-Schrift und Startmenue-Ecken — Windows-XP-Aera
  5. Die 2010er/2020er/Zukunft-Themes sind jeweils visuell unterscheidbar: Dark-Terminal mit Matrix-Regen, Cloud-Dashboard mit Cards/Metriken, Hologramm mit Glow-Effekten
**Plans**: TBD

### Phase 50: Kampagnen Retro
**Goal**: Vier Kampagnen (1960er-2000er) mit insgesamt 14 Szenen erzaehlen die Geschichte des Hackings von Phone Phreaking bis SQL Injection — basierend auf echten historischen Ereignissen
**Depends on**: Phase 49 (Retro-Themes 01-04 muessen funktionieren)
**Requirements**: CAMP-01, CAMP-02, CAMP-03, CAMP-04
**Success Criteria** (what must be TRUE):
  1. "Blue Box" (1960er) hat 3 spielbare Szenen ueber Captain Crunch, Phone Phreaking und den 2600-Hz-Ton — der Spieler versteht wie Phreaking funktionierte
  2. "Shall We Play a Game?" (1980er) hat 4 spielbare Szenen ueber WarGames, Kevin Mitnick Social Engineering und BBS-Kultur — inkl. mindestens einem Easter Egg (Hacker-Quote)
  3. "The Worm" (1990er) hat 3 spielbare Szenen ueber den Morris Worm, Buffer Overflows und den Finger-Daemon — der Internet-Kollaps von 1988 wird nachvollziehbar
  4. "Bobby Tables" (2000er) hat 4 spielbare Szenen ueber SQL Injection, Code Red, MySpace Worm und OWASP-Gruendung — der Spieler versteht die Geburt moderner Web-Security
**Plans**: TBD

### Phase 51: Kampagnen Modern
**Goal**: Drei Kampagnen (2010er-Zukunft) mit insgesamt 11 Szenen fuehren von staatlichen Cyberwaffen ueber Supply-Chain-Angriffe bis zur Quantenbedrohung — der Spieler erlebt die Eskalation moderner IT-Security
**Depends on**: Phase 49 (Themes 05-07 muessen funktionieren)
**Requirements**: CAMP-05, CAMP-06, CAMP-07
**Success Criteria** (what must be TRUE):
  1. "The Shadow Brokers" (2010er) hat 4 spielbare Szenen ueber Stuxnet, APT1, Snowden und EternalBlue — die Eskalation von Cyberwarfare wird greifbar
  2. "Supply Chain" (2020er) hat 4 spielbare Szenen ueber SolarWinds, Log4Shell, Prompt Injection und Deepfakes — aktuelle Bedrohungen werden erlebbar
  3. "Quantum Dawn" (Zukunft) hat 3 spielbare Szenen ueber Post-Quantum Crypto, QKD und Shors Algorithmus — der Spieler versteht warum Quantencomputer die Kryptografie bedrohen

## Progress

**Execution Order:**
Phases execute in numeric order: 48 -> 49 -> 50 -> 51

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 48. Engine + Charakter-Klassen | 2/2 | Complete   | 2026-03-23 | - |
| 49. Epochen-Themes | v7.0 | 0/TBD | Not started | - |
| 50. Kampagnen Retro | v7.0 | 0/TBD | Not started | - |
| 51. Kampagnen Modern | v7.0 | 0/TBD | Not started | - |
