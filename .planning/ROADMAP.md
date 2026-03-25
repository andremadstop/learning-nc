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
- 🚧 **v4.0 Housekeeping + Content-Rollout** — Phases 52-55 (in progress)
- 📋 **v7.2 Subnetzrechner Pro** — Phases 56-60 (planned)
- 📋 **v8.0 VirtuProf v2** — Phases 61-63 (planned)
- 📋 **v9.0 Simulator-Werkzeuge** — Phases 64-70 (planned)
- 📋 **v10.0 Campaign Engine v2** — Phases 71-74 (planned)
- 📋 **v11.0 Telos-Onboarding + VirtuProf Guide** — Phases 75-78 (planned)
- 📋 **v12.0 Campaign Engine — Interaktives Kampagnen-RPG** — Phases 80-85 (planned)

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

<details>
<summary>✅ v7.0 Hacker-Zeitreise "Hack Through Time" (Phases 48-51) - SHIPPED 2026-03-23</summary>

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
- [x] 48-02-PLAN.md — Frontend: HackThroughTime.vue + epoch-tokens.css + App.vue wiring + human verification

### Phase 49: Epochen-Themes
**Goal**: Jede der 7 Epochen hat ein visuell unverwechselbares CSS-Theme das die Aera authentisch repraesentiert — vom gruenen Terminal der 60er bis zum Hologramm-Look der Zukunft
**Depends on**: Phase 48 (EpochTheme-System mit --epoch-* Tokens muss existieren)
**Requirements**: THEME-01, THEME-02, THEME-03, THEME-04, THEME-05, THEME-06, THEME-07
**Plans**: 1 plan

Plans:
- [x] 49-01-PLAN.md — 7 authentic epoch CSS themes with period-specific visual effects

### Phase 50: Kampagnen Retro
**Goal**: Vier Kampagnen (1960er-2000er) mit insgesamt 14 Szenen erzaehlen die Geschichte des Hackings von Phone Phreaking bis SQL Injection — basierend auf echten historischen Ereignissen
**Depends on**: Phase 49 (Retro-Themes 01-04 muessen funktionieren)
**Requirements**: CAMP-01, CAMP-02, CAMP-03, CAMP-04
**Plans**: 1 plan

Plans:
- [x] 50-01-PLAN.md — 4 epoch campaign JSONs (Blue Box, WarGames, The Worm, Bobby Tables) with 14 scenes total

### Phase 51: Kampagnen Modern
**Goal**: Drei Kampagnen (2010er-Zukunft) mit insgesamt 11 Szenen fuehren von staatlichen Cyberwaffen ueber Supply-Chain-Angriffe bis zur Quantenbedrohung — der Spieler erlebt die Eskalation moderner IT-Security
**Depends on**: Phase 49 (Themes 05-07 muessen funktionieren)
**Requirements**: CAMP-05, CAMP-06, CAMP-07
**Plans**: 1 plan

Plans:
- [x] 51-01-PLAN.md — Shadow Brokers + Supply Chain + Quantum Dawn epoch campaigns (11 scenes)

</details>

### v4.0 Housekeeping + Content-Rollout (Phases 52-55)

**Milestone Goal:** Offene Bugfixes deployen, App Store aktualisieren, Netzwerk-Lernmaterial bereinigen und zentral an alle User verteilen, DevCloud aufraeumen.

- [x] **Phase 52: Bugfix & Release** - Binary Tab Fix deployen, App Store Token erneuern + Release hochladen (completed 2026-03-24)
- [x] **Phase 53: Content-Bereinigung** - Wireshark/Nmap/Network+ Guides von persoenlichen Referenzen bereinigen (completed 2026-03-24)
- [x] **Phase 54: Content-Verteilung** - Shared Folder einrichten, Guides ablegen, RAG-Quellen registrieren (completed 2026-03-24)
- [x] **Phase 55: DevCloud-Hygiene** - Ordner-Redundanzcheck, Aufraeumen, OSSU-Evaluation (completed 2026-03-24)

## Phase Details

### Phase 52: Bugfix & Release
**Goal**: Der Subnetzrechner funktioniert fehlerfrei im Browser und die aktuelle App-Version ist im Nextcloud App Store verfuegbar
**Depends on**: Phase 51 (v7.0 shipped)
**Requirements**: FIX-01, FIX-02
**Success Criteria** (what must be TRUE):
  1. Der Binary Tab im Subnetzrechner zeigt korrekt die 32-Bit Darstellung einer eingegebenen IP-Adresse an — keine leeren Felder oder Render-Fehler nach Seitenwechsel
  2. Der App Store Token ist erneuert und ein signierter Release-Tarball ist erfolgreich im Nextcloud App Store hochgeladen — die App-Seite zeigt die aktuelle Version
**Plans**: 1 plan

Plans:
- [ ] 52-01-PLAN.md — Deploy Binary Tab fix + Build/Sign/Upload App Store release

### Phase 53: Content-Bereinigung
**Goal**: Alle Netzwerk-Guides (Wireshark, Nmap, Network+) sind frei von persoenlichen Homelab-Referenzen und als teilbare Kopien vorbereitet
**Depends on**: Phase 52
**Requirements**: CONT-01, CONT-02, CONT-03, CONT-04
**Success Criteria** (what must be TRUE):
  1. Die Wireshark-Anleitung enthaelt keine privaten IPs (192.168.178.x, 10.x.x.x Homelab), keine SSH-Aliases (cockpit, proxmox, learning-dev) und keine persoenlichen Hostnamen — stattdessen generische Beispiele (z.B. 10.0.0.0/24, server-01)
  2. Die Nmap-Anleitung ist analog bereinigt — alle Scan-Beispiele verwenden generische Netzwerke und Hostnamen
  3. Die Network+ Wissensbasis, der Lehrplan und der Grossevents-Guide sind geprueft und enthalten keine persoenlichen Referenzen
  4. Fuer jeden bereinigten Guide existiert eine geteilte Kopie (z.B. im STAS-Vault oder Staging-Ordner) — die Originale im Personal Vault sind unveraendert
**Plans**: 1 plan

Plans:
- [ ] 53-01-PLAN.md — Clean all 5 Network+ guides from personal references, write to app/data/kurs-materialien/

### Phase 54: Content-Verteilung
**Goal**: Alle bereinigten Guides sind zentral in einem NC Shared Folder verfuegbar und VirtuProf kann inhaltliche Fragen dazu beantworten
**Depends on**: Phase 53 (bereinigte Guides muessen vorliegen)
**Requirements**: DIST-01, DIST-02, DIST-03
**Success Criteria** (what must be TRUE):
  1. Ein Shared Folder "Kurs-Materialien" (oder aequivalent) existiert auf der Nextcloud-Instanz und ist fuer alle Kurs-User sichtbar — ein eingeloggter Student kann den Ordner in seiner Dateiansicht sehen
  2. Die bereinigten Network+ Guides (Wireshark, Nmap, Wissensbasis etc.) liegen im Shared Folder und sind lesbar — kein 404, keine Berechtigungsfehler
  3. VirtuProf kann eine inhaltliche Frage zu den Guides korrekt beantworten (z.B. "Wie starte ich einen Nmap SYN-Scan?") — die RAG-Quellen sind registriert und der Kontext fliesst in die Antwort ein
**Plans**: 1 plan

Plans:
- [ ] 54-01-PLAN.md — NC Shared Folder + guide distribution + RAG indexing + VirtuProf verification

### Phase 55: DevCloud-Hygiene
**Goal**: Die DevCloud-Umgebung ist aufgeraeumt, redundante Daten sind entfernt und OSSU als Kursstruktur ist bewertet
**Depends on**: Phase 54
**Requirements**: HYGN-01, HYGN-02, HYGN-03
**Success Criteria** (what must be TRUE):
  1. Ein dokumentierter Redundanzcheck aller User-Home-Ordner auf learning-dev liegt vor — mit Auflistung welche Ordner redundant/veraltet sind und wieviel Speicher sie belegen
  2. Identifizierte redundante/doppelte Ordner sind geloescht oder archiviert — der belegte Speicher ist messbar reduziert
  3. Eine dokumentierte Evaluation des OSSU Curriculum als Kursstruktur-Template liegt vor — mit Fazit ob und wie es als Kursstruktur in Learning-NC importiert werden kann
**Plans**: 2 plans

Plans:
- [ ] 55-01-PLAN.md — DevCloud Redundanzcheck + OSSU Curriculum Evaluation
- [ ] 55-02-PLAN.md — Cleanup ausfuehren (checkpoint: User-Genehmigung)

---

### v7.2 Subnetzrechner Pro (Phases 56-60)

**Milestone Goal:** Subnetzrechner zum interaktiven Lernwerkzeug ausbauen — Toggle-Spalten fuer schrittweises Lernen, Uebungsmodus mit realistischen CompTIA-Szenarien, VLAN-Visualisierung und IPv6-Support. Nur Frontend (app/src/, app/js/).

- [x] **Phase 56: Toggle-Spalten** - Ergebnis-Zeilen ein/ausblenden mit Lern-Presets und Session-Persistenz (completed 2026-03-24)
- [x] **Phase 57: IPv6 Math + UI** - subnetMath.js um IPv6 erweitern, neuer Tab/Bereich mit 128-Bit Binaer-Display (completed 2026-03-24)
- [x] **Phase 57.5: Rechenweg / Erklaer-Modus** - Schritt-fuer-Schritt Berechnung wie der Dozent, Warum-Toggles, Kompakt/Erklaer-Umschalter (completed 2026-03-24)
- [x] **Phase 58: Uebungsmodus Engine** - Aufgaben-Framework mit Zufallsauswahl, Eingabefelder, Prueflogik, Fortschritt (completed 2026-03-24)
- [ ] **Phase 59: Szenarien-Content** - 25+ Aufgaben (Subnetting, VLSM, Praxis, IPv6) mit Schwierigkeitsgraden
- [ ] **Phase 60: VLAN-Tab** - VLAN-ID Zuordnung, Access/Trunk Visualisierung, Inter-VLAN Routing

### Phase 56: Toggle-Spalten
**Goal**: User koennen im Subnetzrechner gezielt Ergebnis-Zeilen ein/ausblenden um schrittweise zu lernen — von Anfaenger-Presets bis zur vollen Anzeige
**Depends on**: Phase 55 (v4.0 Housekeeping abgeschlossen)
**Requirements**: TOG-01, TOG-02, TOG-03
**Success Criteria** (what must be TRUE):
  1. Im Rechner-Tab kann der User jede einzelne Ergebnis-Zeile (Netzadresse, Broadcast, CIDR, Subnetzmaske, Host-Anzahl, etc.) per Checkbox sichtbar oder unsichtbar schalten — verdeckte Zeilen sind nicht im DOM sichtbar
  2. Ein Preset-Dropdown bietet mindestens 4 Optionen (Alle, Anfaenger, Fortgeschritten, Nur Basics) die jeweils eine sinnvolle Kombination von Zeilen aktivieren — der User erkennt sofort den Unterschied zwischen den Presets
  3. Die Toggle-Einstellung bleibt beim Tab-Wechsel (Rechner/Binaer/VLSM) erhalten — nach Hin- und Zurueckwechseln ist die gleiche Konfiguration aktiv wie vorher
**Plans**: 1 plan

Plans:
- [ ] 56-01-PLAN.md — Toggle-Presets utility + SubnetCalculator toggle UI

### Phase 57: IPv6 Math + UI
**Goal**: Der Subnetzrechner unterstuetzt IPv6-Adressen mit korrekter Berechnung und visueller 128-Bit Darstellung
**Depends on**: Phase 56 (Toggle-System als UI-Pattern verfuegbar)
**Requirements**: IPV6-01, IPV6-02
**Success Criteria** (what must be TRUE):
  1. Der User kann eine IPv6-Adresse mit Prefix (z.B. 2001:db8::1/48) eingeben und sieht korrekt berechnete Netzadresse, Host-Range und Adresstyp (Link-Local, Global Unicast, Multicast) — die Berechnung stimmt mit Referenz-Tools ueberein
  2. Ein Binaer-Display zeigt die vollstaendige 128-Bit Darstellung der IPv6-Adresse mit farblicher Trennung von Prefix und Interface-ID — der User erkennt visuell wo die Netzgrenze liegt
  3. subnetMath.js enthaelt reine IPv6-Funktionen (Parsing, Expansion, Prefix-Berechnung, Typ-Erkennung) die per Vitest getestet sind — mindestens 10 Unit-Tests fuer IPv6-Logik
**Plans**: 1 plan

Plans:
- [ ] 57-01-PLAN.md — ipv6Math.js utility (TDD) + SubnetCalculator IPv6 tab with 128-bit binary display

### Phase 57.5: Rechenweg / Erklaer-Modus
**Goal**: Anfaenger sehen den vollstaendigen Rechenweg wie ein Dozent ihn an der Tafel erklaeren wuerde — Profis koennen ihn ausblenden
**Depends on**: Phase 57 (IPv6 muss funktionieren, Rechenweg gilt fuer IPv4 + IPv6)
**Requirements**: ERK-01, ERK-02, ERK-03
**Success Criteria** (what must be TRUE):
  1. Im Binaer-Tab erscheint unter dem Bit-Grid ein Rechenweg-Panel das zeigt: Prefix → Host-Bits → Blockgroesse → Maske binaer → Netzadresse-Formel → Broadcast-Formel — Schritt fuer Schritt wie der Dozent es erklaert
  2. Jedes Ergebnis-Feld im Rechner-Tab hat einen "Warum?"-Toggle der die Herleitung einblendet (z.B. "Broadcast = Netzadresse OR Wildcard = 192.168.0.0 OR 0.0.0.31 = 192.168.0.31")
  3. Ein Kompakt/Erklaer-Umschalter erlaubt dem User zwischen reiner Ergebnis-Ansicht und ausfuehrlicher Erklaer-Ansicht zu wechseln — die Einstellung bleibt wie die Toggles session-persistent
**Plans**: 2 plans

Plans:
- [ ] 57.5-01-PLAN.md — subnetExplainer.js utility (TDD) with IPv4+IPv6 step generation
- [ ] 57.5-02-PLAN.md — SubnetCalculator.vue Erklaer-Modus UI integration + human verification

### Phase 58: Uebungsmodus Engine
**Goal**: Der Subnetzrechner bietet einen interaktiven Uebungsmodus in dem User Netzwerk-Aufgaben loesen, sofortiges Feedback bekommen und ihren Fortschritt verfolgen
**Depends on**: Phase 57 (IPv6-Math verfuegbar fuer gemischte Aufgaben)
**Requirements**: UEB-01, UEB-02, UEB-03, UEB-04
**Success Criteria** (what must be TRUE):
  1. Der User kann einen Uebungsmodus starten und bekommt eine zufaellig ausgewaehlte Aufgabe aus dem Szenario-Pool angezeigt — mit Aufgabentext und Kontext (z.B. "Berechne die Broadcast-Adresse fuer 192.168.10.0/26")
  2. Pro Aufgabe gibt es Eingabefelder fuer die erwarteten Antworten (Netzadresse, Broadcast, CIDR, Host-Anzahl etc.) — der User tippt seine Loesung ein statt sie auszuwaehlen
  3. Nach Abgabe prueft der Simulator automatisch alle Felder und zeigt pro Feld gruenes Haekchen oder rotes X mit der korrekten Loesung — der User sieht sofort wo er richtig/falsch lag
  4. Ein Fortschritts-Tracker zeigt "X von Y richtig" und die aktuelle Serie (Streak) an — der User erkennt seinen Lernfortschritt innerhalb der Session
**Plans**: 2 plans

Plans:
- [ ] 58-01-PLAN.md — practiceEngine.js utility (TDD) with scenario pool, answer checking, progress tracking
- [ ] 58-02-PLAN.md — SubnetCalculator.vue practice tab integration + human verification

### Phase 59: Szenarien-Content
**Goal**: Mindestens 25 realistische Uebungsszenarien auf CompTIA Network+ Niveau decken Subnetting, VLSM, Praxis-Kontexte und IPv6 ab — mit Schwierigkeitsgraden und typischen Fallstricken
**Depends on**: Phase 58 (Uebungsmodus-Engine muss Szenarien laden koennen)
**Requirements**: SCN-01, SCN-02, SCN-03, SCN-04, IPV6-03
**Success Criteria** (what must be TRUE):
  1. Mindestens 15 Subnetting-Aufgaben testen CIDR-Berechnung, Host-Ranges und nicht-aligned Adressen — der User begegnet realistischen Network+-Fragetypen
  2. Mindestens 5 VLSM-Aufgaben verlangen das Aufteilen eines Netzwerks in mehrere Subnetze fuer verschiedene Abteilungen/Standorte — mit unterschiedlichen Host-Anforderungen pro Subnetz
  3. Mindestens 5 Praxis-Szenarien beschreiben einen realen Kontext (Firma, Server-Rack, Filialstruktur) und fragen nach konkreten Netzwerk-Konfigurationen — der User uebt anwendungsbezogenes Denken
  4. Mindestens 5 IPv6-Szenarien decken Prefix-Berechnung, /48-Subnetting, EUI-64 und Link-Local Erkennung ab — der User kann IPv6-Grundlagen im Uebungsmodus trainieren
  5. Jede Aufgabe hat einen Schwierigkeitsgrad (Leicht/Mittel/Schwer) und die Sammlung deckt typische Fallstricke ab (Broadcast abziehen, Router-Interface beruecksichtigen, nicht auf Netzgrenze liegende Adressen)
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 60: VLAN-Tab
**Goal**: Ein neuer VLAN-Tab im Subnetzrechner visualisiert VLAN-Zuordnungen, 802.1Q Tagging und Inter-VLAN Routing — der User versteht die Zusammenhaenge zwischen VLANs und Subnetzen
**Depends on**: Phase 56 (Toggle-Pattern fuer konsistente UI)
**Requirements**: VLAN-01, VLAN-02, VLAN-03
**Success Criteria** (what must be TRUE):
  1. Ein neuer Tab "VLAN" ist im Subnetzrechner sichtbar und erlaubt dem User VLAN-IDs einzugeben und Subnetzen zuzuordnen — die Zuordnung ist sofort visuell erkennbar
  2. Eine Visualisierung zeigt Access-Ports und Trunk-Ports mit 802.1Q Tagging — der User sieht welche Frames getaggt werden und welche nicht (untagged auf Access, tagged auf Trunk)
  3. Eine Inter-VLAN Routing Darstellung zeigt Router-on-a-Stick oder L3-Switch Konfiguration mit Subinterfaces und VLAN-Zuordnung — der User versteht wie Pakete zwischen VLANs geroutet werden

**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

---

### v8.0 VirtuProf v2 (Phases 61-63)

**Milestone Goal:** VirtuProf wird kontextbewusst — der Bot weiss welche Frage der User gerade sieht, gibt gestufte Hints statt sofort die Antwort, ist im Pruefungsmodus gesperrt, und User koennen Fehler direkt melden.

- [x] **Phase 61: Kontext-Mapping** - Frontend sendet Fragen-Kontext an Chat-API, GeminiService nutzt ihn im System-Prompt (completed 2026-03-24)
- [x] **Phase 62: Hint-System** - Gestufte Tipps (Richtung, konkreter, fast die Antwort) mit Per-Frage-Tracking (completed 2026-03-24)
- [x] **Phase 63: Exam-Sperre + Fehler-Report** - VirtuProf im Exam-Mode deaktiviert, Fehler-Melde-Funktion mit automatischer Fragen-ID (completed 2026-03-24)

### Phase 61: Kontext-Mapping
**Goal**: VirtuProf weiss welche Frage der User gerade sieht und kann direkt darauf eingehen — ohne dass der User die Frage kopieren oder beschreiben muss
**Depends on**: Phase 60 (v7.2 abgeschlossen) oder eigenstaendig startbar (keine harte Abhaengigkeit auf Subnetzrechner)
**Requirements**: CTX-01, CTX-02, CTX-03
**Success Criteria** (what must be TRUE):
  1. Wenn der User in einem Lernmodus (Training, Leitner, Exam, Abenteuer) eine Frage sieht und den VirtuProf-Chat oeffnet, kennt der Bot die aktuelle Frage — der User kann "Erklaer mir diese Frage" schreiben und bekommt eine kontextbezogene Antwort
  2. Der Bot kann auf "Warum ist B richtig?" direkt antworten indem er die Antwortoptionen und die korrekte Antwort aus dem Kontext kennt — ohne dass der User die Optionen abtippen muss
  3. Bei Fragenwechsel (naechste Frage im Lernmodus) aktualisiert sich der Kontext automatisch — der Bot bezieht sich nicht mehr auf die alte Frage
  4. Die Chat-API akzeptiert den Fragen-Kontext als optionalen Parameter — bestehende Chat-Aufrufe ohne Kontext funktionieren weiterhin (Rueckwaertskompatibilitaet)
**Plans**: 2 plans

Plans:
- [ ] 61-01-PLAN.md — Backend: VirtuProfController + GeminiService accept and use question context
- [ ] 61-02-PLAN.md — Frontend: Learning modes emit question context, VirtuProf sends it

### Phase 62: Hint-System
**Goal**: User bekommen gestufte Hilfe statt sofort die Loesung — der Bot fuehrt den User schrittweise zur richtigen Antwort
**Depends on**: Phase 61 (Fragen-Kontext muss verfuegbar sein damit Hints zur aktuellen Frage passen)
**Requirements**: HINT-01, HINT-02, HINT-03
**Success Criteria** (what must be TRUE):
  1. Der User schreibt "Tipp" oder "Hint" und bekommt eine erste Hilfestellung die nur die Richtung andeutet (z.B. "Denk an OSI-Schicht 3") — nicht die volle Erklaerung
  2. Beim zweiten "Tipp" wird die Hilfe konkreter (z.B. "Es hat mit Routing-Protokollen zu tun, schau dir die Optionen B und D genauer an") — immer noch nicht die Antwort
  3. Beim dritten "Tipp" bietet der Bot an die vollstaendige Erklaerung zu zeigen — erst nach expliziter Zustimmung des Users kommt die Loesung
  4. Bei Wechsel zur naechsten Frage startet der Hint-Zaehler wieder bei 1 — alte Hint-Level haben keinen Einfluss auf die neue Frage
**Plans**: 1 plan

Plans:
- [x] 62-01-PLAN.md — Backend hintLevel + graduated prompts, Frontend hint tracking + keyword detection

### Phase 63: Exam-Sperre + Fehler-Report
**Goal**: VirtuProf ist im Pruefungsmodus nicht verfuegbar (faire Pruefungsbedingungen) und User koennen Fehler in Fragen direkt aus dem Chat melden
**Depends on**: Phase 61 (Fragen-Kontext wird fuer den Fehler-Report benoetigt — automatische Fragen-ID)
**Requirements**: EXAM-01, EXAM-02, REP-01, REP-02
**Success Criteria** (what must be TRUE):
  1. Im Exam-Mode ist die VirtuProf-Bubble entweder ausgeblendet oder der Chat-Input ist deaktiviert mit einem Hinweis "Waehrend der Pruefung nicht verfuegbar" — kein Weg KI-Hilfe zu bekommen
  2. Die Sperre aktiviert sich automatisch wenn der ExamMode-Kontext aktiv ist und deaktiviert sich automatisch nach Pruefungsende — kein manuelles Ein/Ausschalten noetig
  3. Der User kann per Button oder Kommando ("Fehler melden") ein Problem mit der aktuellen Frage melden und der Report enthaelt automatisch die Fragen-ID und den aktuellen Modus — der User muss nur noch optional einen Kommentar ergaenzen
  4. Gemeldete Fehler sind fuer den Dozenten/Admin einsehbar (z.B. ueber das bestehende SupportTicketService) — kein Report geht verloren
**Plans**: 1 plan

Plans:
- [x] 63-01-PLAN.md — Exam-Sperre (VirtuProf deaktiviert) + Fehler-Report Button mit automatischer Fragen-ID


---

### v9.0 Simulator-Werkzeuge (Phases 64-70)

**Milestone Goal:** 7 interaktive Netzwerk-Simulatoren als reine Frontend-Tools — CompTIA Network+ und Security+ Kernthemen hands-on ueben. Alle Simulatoren im Werkzeuge-Tab, kampagnenfaehig.

- [ ] **Phase 64: DNS-Resolver** - Schritt-fuer-Schritt DNS-Aufloesung, Record-Typen, Fehlersuche-Szenarien
- [ ] **Phase 65: Firewall/ACL-Builder** - Regelwerk-Editor, Paket-Simulation, Sicherheits-Szenarien
- [ ] **Phase 66: Port-Scanner** - Animierter Scan, Service-Erkennung, Host-Profile
- [ ] **Phase 67: Routing-Tabelle** - Editierbare Tabelle, Longest Prefix Match, Paket-Routing
- [ ] **Phase 68: NAT-Tabelle** - Uebersetzungs-Visualisierung, NAT-Typen, Szenarien
- [ ] **Phase 69: Wireshark-Lite** - Paket-Aufbau, vordefinierte Captures, Problemerkennung
- [ ] **Phase 70: 802.1X Auth-Flow** - Sequenzdiagramm, EAP-Phasen, Vergleich

### Phase 64: DNS-Resolver
**Goal**: User koennen eine Domain eingeben und sehen die vollstaendige DNS-Aufloesung Schritt fuer Schritt — von Root-Server bis zur IP-Adresse, mit allen gaengigen Record-Typen und Fehlersuche-Szenarien
**Depends on**: Phase 63 (v8.0 abgeschlossen) — keine harte Abhaengigkeit, eigenstaendig startbar
**Requirements**: DNS-01, DNS-02, DNS-03
**Success Criteria** (what must be TRUE):
  1. Der User gibt eine Domain ein und sieht eine animierte Schritt-fuer-Schritt Aufloesung (Root → TLD → Authoritative → IP) mit sichtbaren Zwischenstationen und erklaerenden Labels an jedem Schritt
  2. Der User kann Record-Typen (A, AAAA, MX, CNAME, PTR, NS, SOA, TXT) auswaehlen und sieht pro Typ eine visuelle Erklaerung mit Beispiel-Daten und typischem Einsatzzweck
  3. Im Uebungsmodus bekommt der User eine manipulierte DNS-Kette praesentiert ("Warum loest diese Domain nicht auf?") und muss das Problem identifizieren — mit Feedback ob die Analyse korrekt war
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 65: Firewall/ACL-Builder
**Goal**: User koennen ein Firewall-Regelwerk in einer Tabelle aufbauen und simulierte Pakete dagegen testen — mit visueller Rueckmeldung ob ein Paket erlaubt oder blockiert wird
**Depends on**: None (unabhaengig von anderen Simulatoren)
**Requirements**: FW-01, FW-02, FW-03
**Success Criteria** (what must be TRUE):
  1. Der User kann Firewall-Regeln (Src-IP, Dst-IP, Port, Protocol, Action) in einer Tabelle anlegen, bearbeiten und per Drag-and-Drop umsortieren — die Reihenfolge bestimmt die Auswertung (First Match)
  2. Der User kann ein simuliertes Paket (Src, Dst, Port, Protocol) eingeben und sieht visuell welche Regel greift (gruen = erlaubt, rot = blockiert) — mit Markierung der matchenden Zeile im Regelwerk
  3. Im Uebungsmodus bekommt der User eine Anforderung ("Erlaube HTTP von Subnetz X, blockiere alles andere") und muss ein passendes Regelwerk erstellen — mit automatischer Pruefung gegen vordefinierte Test-Pakete
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 66: Port-Scanner
**Goal**: User koennen einen simulierten Port-Scan gegen vordefinierte Host-Profile durchfuehren und lernen offene Ports, Services und verdaechtige Konfigurationen zu erkennen
**Depends on**: None (unabhaengig von anderen Simulatoren)
**Requirements**: PSCAN-01, PSCAN-02, PSCAN-03
**Success Criteria** (what must be TRUE):
  1. Der User gibt eine IP ein und sieht einen animierten Scan der Port fuer Port durchgeht — offene Ports erscheinen mit Service-Name und Version (z.B. "80/tcp — HTTP — Apache 2.4")
  2. Mindestens 4 vordefinierte Host-Profile (Webserver, Mailserver, Router, Domain Controller) liefern realistische Port-Sets — der User kann zwischen Profilen wechseln und die Unterschiede erkennen
  3. Im Uebungsmodus muss der User Fragen beantworten wie "Welcher Service laeuft auf Port 3389?" oder "Welcher Host ist vermutlich kompromittiert?" — mit Erklaerung warum bestimmte offene Ports ein Sicherheitsrisiko darstellen
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 67: Routing-Tabelle
**Goal**: User koennen eine Routing-Tabelle editieren und sehen wie Pakete anhand von Longest Prefix Match durch ein Netzwerk geroutet werden
**Depends on**: None (unabhaengig von anderen Simulatoren)
**Requirements**: RT-01, RT-02, RT-03
**Success Criteria** (what must be TRUE):
  1. Der User sieht eine editierbare Routing-Tabelle (Destination, Mask, Gateway, Interface, Metric) und kann Eintraege hinzufuegen, loeschen und aendern — inklusive Default-Route
  2. Bei Eingabe einer Ziel-IP zeigt eine Animation den Longest-Prefix-Match-Prozess: alle passenden Routen werden markiert, die spezifischste wird hervorgehoben und das Paket wird visuell ueber das gewaehlte Interface weitergeleitet
  3. Im Uebungsmodus bekommt der User ein Netzwerk-Szenario ("Subnetz 10.1.2.0/24 soll ueber Gateway 10.0.0.1 erreichbar sein") und muss die richtige Route eintragen — mit Verifikation durch Test-Pakete
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 68: NAT-Tabelle
**Goal**: User verstehen Network Address Translation visuell — sie sehen wie interne Adressen in externe uebersetzt werden und koennen verschiedene NAT-Typen vergleichen
**Depends on**: None (unabhaengig von anderen Simulatoren)
**Requirements**: NAT-01, NAT-02, NAT-03
**Success Criteria** (what must be TRUE):
  1. Eine Visualisierung zeigt den Paket-Weg von Inside Local ueber NAT-Device zu Inside Global und zum Outside-Ziel — mit sichtbarer Adress-Transformation an jedem Punkt
  2. Der User kann zwischen Static NAT, Dynamic NAT und PAT/Overload umschalten und sieht die Unterschiede in der Uebersetzungstabelle — bei PAT sind Port-Zuordnungen sichtbar, bei Static die 1:1-Zuordnung
  3. Im Uebungsmodus bekommt der User ein Szenario ("5 Clients, 1 oeffentliche IP — welche externe IP:Port Kombination sieht der Server?") und muss die korrekte Uebersetzung angeben
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 69: Wireshark-Lite
**Goal**: User koennen Netzwerk-Pakete visuell untersuchen — vom Ethernet-Frame bis zum Payload — und lernen typische Protokoll-Muster und Fehlersituationen zu erkennen
**Depends on**: None (unabhaengig von anderen Simulatoren)
**Requirements**: WS-01, WS-02, WS-03
**Success Criteria** (what must be TRUE):
  1. Der User sieht einen Paket-Aufbau als verschachtelte Darstellung (Ethernet-Header → IP-Header → TCP/UDP-Header → Payload) mit allen Feldern, Hex-Werten und menschenlesbaren Beschreibungen — klickbar zum Auf-/Zuklappen
  2. Vordefinierte Captures (TCP 3-Way Handshake, DNS Query/Response, HTTP GET, ARP Request/Reply) zeigen jeweils eine Sequenz von Paketen mit erklaerenden Annotationen — der User kann Paket fuer Paket durchklicken
  3. Im Uebungsmodus bekommt der User einen Capture mit einem Problem (Retransmissions, RST-Flags, TTL exceeded) und muss das Problem identifizieren — mit Hinweisen auf welche Felder er achten soll
  4. Die Paket-Darstellung verwendet Farb-Kodierung nach Protokoll-Schicht (L2 blau, L3 gruen, L4 orange, L7 lila) — konsistent ueber alle Captures hinweg
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 70: 802.1X Auth-Flow
**Goal**: User verstehen den 802.1X Authentifizierungs-Ablauf visuell — vom Supplicant ueber den Authenticator bis zum Auth-Server, mit allen EAP-Phasen und Protokoll-Vergleichen
**Depends on**: None (unabhaengig von anderen Simulatoren)
**Requirements**: AUTH-01, AUTH-02, AUTH-03
**Success Criteria** (what must be TRUE):
  1. Ein animiertes Sequenzdiagramm zeigt den kompletten 802.1X Ablauf (Supplicant → Authenticator → RADIUS Server) mit EAP-Phasen (EAPOL-Start, EAP-Request Identity, EAP-Response, Challenge, Success/Failure) — jeder Schritt wird nacheinander eingeblendet mit Erklaerung
  2. Im Uebungsmodus muss der User die EAP-Nachrichten in die richtige Reihenfolge bringen (Drag-and-Drop) — mit Feedback welche Schritte vertauscht waren und warum die korrekte Reihenfolge wichtig ist
  3. Eine Vergleichsansicht zeigt EAP-TLS, PEAP und EAP-FAST nebeneinander mit Unterschieden in Zertifikat-Anforderungen, Tunnel-Aufbau und Sicherheitsniveau — der User erkennt auf einen Blick welches Protokoll wann geeignet ist

**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification


### 📋 v12.0 Campaign Engine — Interaktives Kampagnen-RPG (Planned)

**Milestone Goal:** Die bestehende Graph-Engine mit den 7 Netzwerk-Simulatoren zu einem echten Spielerlebnis verschmelzen. Kampagnen sollen sich wie ein kleines RPG anfuehlen — mit Quest-Map, eingebetteten Simulator-Challenges, humorvollen ueberzeichneten Charakteren, Inventory, Reputation, Timer-Countdown und Coop-Multiplayer.

- [x] **Phase 80: SimulatorShell + Wiring** - SIM-01 bis SIM-05 — Simulator-Wrapper, Lifecycle-Hooks, pass_flag-Wiring (completed 2026-03-25)
- [x] **Phase 81: Quest-Map** - MAP-01 bis MAP-06 — D3.js SVG-Karte, Knoten-Zustaende, Zoom/Pan (completed 2026-03-25)
- [ ] **Phase 82: HUD + Timer + DauBot-UI** - HUD-01 bis HUD-06, BOT-01 bis BOT-05 — StateBagHUD, Timer-Countdown, DauBot-Dialog
- [ ] **Phase 83: Kampagnen-Content "Der grosse Ausfall"** - CAMP-01 bis CAMP-07 — 15+ Knoten, 3 Akte, 3 Enden, NPCs, Items, Reputation
- [ ] **Phase 84: Coop-Backend** - COOP-07 — 3 DB-Tabellen, CoopService, CoopController, 6 Endpoints
- [ ] **Phase 85: Coop-Frontend** - COOP-01 bis COOP-06 — Lobby, Abstimmung, Scene-Sync via Polling

### Phase 80: SimulatorShell + Wiring
**Goal**: Alle 7 Netzwerk-Simulatoren sind in Kampagnen-Szenen einbettbar — SimulatorShell laedt die richtige Komponente dynamisch, setzt pass_flag im State-Bag nach Bestehen und ruft graph-traverse mit dem Ergebnis auf
**Depends on**: Phase 78 (v11.0 abgeschlossen)
**Requirements**: SIM-01, SIM-02, SIM-03, SIM-04, SIM-05
**Success Criteria** (what must be TRUE):
  1. Eine Kampagnen-Szene mit simulator.type="firewall" (oder dns/routing/nat/portscan/wireshark/authflow) zeigt den jeweiligen Simulator direkt in der Szene eingebettet an — kein separater Tab, kein Seitenwechsel
  2. Nach Loesen der Simulator-Challenge (Score >= pass_threshold) wird pass_flag im State-Bag gesetzt und graph-traverse wird automatisch aufgerufen — die Story verzweigt entsprechend
  3. Nach Abbrechen oder falschem Loesen bleibt der User auf der Szene und kann erneut versuchen oder eine andere Edge waehlen — kein Datenverlust
  4. Alle 7 Simulator-Komponenten haben beforeDestroy-Hooks die Intervals bereinigen — kein Memory-Leak beim Szenenwechsel auf einem Tablet
  5. Der Kampagnen-Autor kann pro Node simulator.type, scenario, pass_flag und optional scenario_override in JSON definieren — SimulatorShell mappt automatisch
**Plans**: 3 plans

Plans:
- [ ] 80-01-PLAN.md — beforeDestroy-Lifecycle-Hooks fuer 5 Simulatoren + Test-Scaffold
- [ ] 80-02-PLAN.md — SimulatorShell.vue mit SIMULATOR_MAP + @result-Normierung
- [ ] 80-03-PLAN.md — AbenteuerMode Graph-Mode Wiring + SimulatorShell-Integration

### Phase 80.1: Bot-Gegner für Multiplayer-Modi (INSERTED)

**Goal:** [Urgent work - to be planned]
**Requirements**: TBD
**Depends on:** Phase 80
**Plans:** 3/3 plans complete

Plans:
- [ ] TBD (run /gsd:plan-phase 80.1 to break down)

### Phase 81: Quest-Map
**Goal**: User sehen eine interaktive 2D-Karte aller Kampagnen-Knoten als SVG-Overlay mit D3.js, koennen Knoten-Zustaende (besucht/aktuell/erreichbar/gesperrt) ablesen und per Klick oder Zoom navigieren
**Depends on**: Phase 80 (stateBag wird durch echte Traversierungen befuellt)
**Requirements**: MAP-01, MAP-02, MAP-03, MAP-04, MAP-05, MAP-06
**Success Criteria** (what must be TRUE):
  1. Die Quest-Map oeffnet sich als Slide-in-Overlay bei Klick auf den Karten-Button in der Szenen-Kopfzeile — alle Knoten der Kampagne sind als SVG-Kreise mit Verbindungslinien sichtbar
  2. Knoten zeigen korrekte visuelle Zustaende: besucht (gruen), aktuell (pulsierend), erreichbar (hell), gesperrt (grau mit Schloss-Icon) — Zustaende aktualisieren sich nach jeder Szenen-Navigation
  3. Edge-Labels zeigen die Entscheidungsoption, gesperrte Edges zeigen per Tooltip welche Bedingung fehlt (Flag-Name, Item-Name oder Reputation-Schwellwert)
  4. User kann per Klick auf einen erreichbaren Knoten dorthin navigieren — gesperrte Knoten sind nicht klickbar
  5. Zoom (Maus-Rad) und Pan (Drag) funktionieren auf groesseren Kampagnen — die Karte skaliert korrekt
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 82: HUD + Timer + DauBot-UI
**Goal**: User sehen waehrend des Spiels ein kompaktes HUD mit Inventory und Reputation, einen visuellen Timer-Countdown bei zeitkritischen Szenen mit Konsequenzen bei Ablauf, und ein Dialog-UI fuer DauBot-Fehlerkorrekturen
**Depends on**: Phase 80 (stateBag muss durch echte Traversierungen befuellt sein)
**Requirements**: HUD-01, HUD-02, HUD-03, HUD-04, HUD-05, HUD-06, BOT-01, BOT-02, BOT-03, BOT-04, BOT-05
**Success Criteria** (what must be TRUE):
  1. Das HUD zeigt in der Szenen-Kopfzeile: gesammelte Items mit Icons und Tooltips, Reputation-Bars pro Domaene (Security/Management/Team), aktuelle Rolle und Score sowie den aktiven Akt und Fortschritt — alles in einem kompakten horizontalen Streifen ohne Scrollbedarf
  2. Bei zeitkritischen Szenen (timer-Typ im Node) laeuft ein sichtbarer Countdown (Fortschrittsbalken + Sekunden) — bei Ablauf wird die konfigurierte Edge automatisch gesperrt oder eine alternative Route erzwungen
  3. Eine DauBot-Szene zeigt eine humorvolle Fehlerbeschreibung ("Klaus hat den Switch neu gestartet weil er geblinkt hat") plus Multiple-Choice-Auswahl fuer Fehlerdiagnose und Behebung
  4. Richtige DauBot-Antworten erhoehen Score und Reputation, falsche senken sie — der Effekt ist sofort im HUD sichtbar
  5. DauBot-Texte sind ueberzeichnet-humorvoll: panische Fehlerbeschreibungen, absurde Azubi-Ausreden, realistische Netzwerk-Fehler als Grundlage
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 83: Kampagnen-Content "Der grosse Ausfall"
**Goal**: Eine vollstaendige spielbare Vorzeige-Kampagne "Der grosse Ausfall" mit 15+ Knoten, 3 Akten, 4 ueberzeichneten NPCs, 3 verschiedenen Enden, mind. 3 Simulator-Typen, Items, Reputation-Verzweigungen, 2 Timer-Szenen und Schema-Validierung
**Depends on**: Phase 82 (alle HUD- und Simulator-Features muessen stehen damit Content testbar ist)
**Requirements**: CAMP-01, CAMP-02, CAMP-03, CAMP-04, CAMP-05, CAMP-06, CAMP-07
**Success Criteria** (what must be TRUE):
  1. Die Kampagne ist vollstaendig spielbar vom Intro bis zu mindestens einem der drei Enden — kein Node fuehrt in eine Sackgasse oder einen Absturz
  2. Mindestens 3 verschiedene Simulator-Challenges erscheinen eingebettet in Szenen (z.B. Firewall-Diagnose, DNS-Debug, Routing-Trace) — jede Challenge ist loesbar und hat eine Story-Konsequenz
  3. Alle 4 NPCs haben erkennbare Persoenlichkeiten durch ihren Dialog: der panische Manager ("DER INTERNET IST WEG"), der ueberhebliche Senior-Admin, Azubi Klaus mit seinen Ausreden, die gestresste Helpdesk-Kollegin
  4. Items (z.B. Server-Logs, Kaffee, Backup-Tape) sind sammelbar und werden in mindestens 2 Szenen als Voraussetzung oder Option eingesetzt — das Item-Inventory im HUD reflektiert den Stand
  5. Security-Reputation beeinflusst mindestens einen Pfad (hoch = Zugang zu versteckter Route, niedrig = Pfad gesperrt) — Reputation-Werte sind im HUD sichtbar
  6. Zwei Timer-Szenen haben echten Zeitdruck: bei Ablauf verlaeuft die Story unguemstig (andere Edge) — die Konsequenz ist dem User durch Tooltip kommuniziert
  7. Das Campaign JSON hat ein version-Feld und wird beim Laden gegen ein Schema validiert — Versionsmismatch zeigt "Neustart erforderlich" statt stilles Reset
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 84: Coop-Backend
**Goal**: Drei neue DB-Tabellen (coop_sessions, coop_players, coop_votes) und ein vollstaendiges Coop-Subsystem mit CoopService und CoopController sind deployed und per API testbar
**Depends on**: Phase 80 (graph-traverse Kontrakt muss stabil sein)
**Requirements**: COOP-07
**Success Criteria** (what must be TRUE):
  1. Per curl-Test: Host erstellt Session (POST /coop/sessions) und erhaelt einen 8-stelligen Session-Code, andere User koennen mit diesem Code beitreten (POST /coop/sessions/{code}/join)
  2. Abstimmungs-Endpunkt (POST /coop/sessions/{id}/vote) nimmt Edge-Wahl entgegen und loest bei einfacher Mehrheit automatisch graph-traverse aus — Race-Condition ist durch server-seitigen status=advancing-Guard verhindert
  3. Inaktive Sessions werden nach konfigurierbarem Timeout (Standard: 30 Minuten ohne Heartbeat) automatisch bereinigt
  4. Alle 6 Endpoints (create, join, lobby, ready, state, vote) antworten mit korrekten HTTP-Status-Codes und validen JSON-Bodies — keine 500er bei normaler Nutzung
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 85: Coop-Frontend
**Goal**: User koennen eine Coop-Session starten oder beitreten, sehen dieselbe Szene synchronisiert, stimmen ueber Entscheidungen ab und werden beim Session-Ende benachrichtigt
**Depends on**: Phase 84 (Coop-Backend muss stehen)
**Requirements**: COOP-01, COOP-02, COOP-03, COOP-04, COOP-05, COOP-06
**Success Criteria** (what must be TRUE):
  1. Host klickt "Coop starten" und sieht einen Session-Code den andere User eingeben koennen — bis zu 3 weitere Spieler koennen der Lobby beitreten und alle sehen die Teilnehmerliste
  2. Alle Spieler sehen dieselbe Szene innerhalb von 3 Sekunden nach einem Host-Vorschuss — Polling-Interval von 2-3s ist im Hintergrund aktiv ohne sichtbares Lag
  3. Bei einer Edge-Wahl sehen alle Spieler ein Abstimmungs-Overlay mit den Optionen — die Mehrheits-Option wird automatisch nach allen Votes oder nach Timeout ausgefuehrt
  4. Waehrend ein Spieler eine Simulator-Challenge loest, sehen die anderen Spieler eine Warten-Anzeige mit aktuellem Fortschritt
  5. Host kann "Session beenden" klicken — alle Spieler erhalten eine Benachrichtigung und werden zum Solo-Modus zurueckgeleitet

## Progress

**Execution Order:**
Phases execute in numeric order: 52 -> 53 -> 54 -> 55 -> 56 -> 57 -> 58 -> 59 -> 60 -> 61 -> 62 -> 63 -> 64 -> 65 -> 66 -> 67 -> 68 -> 69 -> 70 -> 71 -> 72 -> 73 -> 74

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 52. Bugfix & Release | v4.0 Housekeeping | 1/1 | Complete | 2026-03-24 |
| 53. Content-Bereinigung | v4.0 Housekeeping | 1/1 | Complete | 2026-03-24 |
| 54. Content-Verteilung | v4.0 Housekeeping | 1/1 | Complete | 2026-03-24 |
| 55. DevCloud-Hygiene | 2/2 | Complete    | 2026-03-24 | - |
| 56. Toggle-Spalten | v7.2 Subnetzrechner Pro | 1/1 | Complete | 2026-03-24 |
| 57. IPv6 Math + UI | v7.2 Subnetzrechner Pro | 1/1 | Complete | 2026-03-24 |
| 58. Uebungsmodus Engine | v7.2 Subnetzrechner Pro | 2/2 | Complete | 2026-03-24 |
| 59. Szenarien-Content | v7.2 Subnetzrechner Pro | 0/TBD | Not started | - |
| 60. VLAN-Tab | v7.2 Subnetzrechner Pro | 0/TBD | Not started | - |
| 61. Kontext-Mapping | 2/2 | Complete    | 2026-03-24 | - |
| 62. Hint-System | v8.0 VirtuProf v2 | 0/TBD | Not started | - |
| 63. Exam-Sperre + Fehler-Report | v8.0 VirtuProf v2 | Complete    | 2026-03-24 | 2026-03-24 |
| 64. DNS-Resolver | v9.0 Simulator-Werkzeuge | 0/TBD | Not started | - |
| 65. Firewall/ACL-Builder | v9.0 Simulator-Werkzeuge | 0/TBD | Not started | - |
| 66. Port-Scanner | v9.0 Simulator-Werkzeuge | 0/TBD | Not started | - |
| 67. Routing-Tabelle | v9.0 Simulator-Werkzeuge | 0/TBD | Not started | - |
| 68. NAT-Tabelle | v9.0 Simulator-Werkzeuge | 0/TBD | Not started | - |
| 69. Wireshark-Lite | v9.0 Simulator-Werkzeuge | 0/TBD | Not started | - |
| 70. 802.1X Auth-Flow | v9.0 Simulator-Werkzeuge | 0/TBD | Not started | - |
| 71. Graph-Engine + DB-Migration | 2/2 | Complete    | 2026-03-24 | - |
| 72. Akt-Struktur + Save/Resume + API | v10.0 Campaign Engine v2 | 0/TBD | Not started | - |
| 73. Rollen-System + Simulator-Integration | v10.0 Campaign Engine v2 | 0/TBD | Not started | - |
| 74. DAU-Bot | v10.0 Campaign Engine v2 | 0/TBD | Not started | - |

---

### v10.0 Campaign Engine v2 (Phases 71-74)

**Milestone Goal:** Kampagnen-System von linearer Slideshow zu einem echten RPG-artigen Erlebnis umbauen — gerichteter Szenen-Graph, State-Machine mit Flags/Items/Reputation, rollenspezifische Pfade, eingebettete Simulatoren als Aufgaben, KI-Gegner, 60-120 min pro Kampagne. Backend-only (app/lib/, app/appinfo/).

- [x] **Phase 71: Graph-Engine + DB-Migration** - Gerichteter Szenen-Graph, State-Bag, Bedingungssystem und campaign_state Tabelle (completed 2026-03-24)
- [ ] **Phase 72: Akt-Struktur + Save/Resume + API** - Akt-basierte Progression, persistenter Spielstand, REST-Endpoints
- [ ] **Phase 73: Rollen-System + Simulator-Integration** - Rollenspezifische Pfade, Simulator-Aufgaben als Szenen-Elemente mit Timer
- [ ] **Phase 74: DAU-Bot** - KI-Gegner der Anfaengerfehler macht, User korrigiert als Lern-Mechanik

### Phase 71: Graph-Engine + DB-Migration
**Goal**: Das Kampagnen-System verarbeitet gerichtete Szenen-Graphen (30-50 Knoten) statt linearer Ketten, verwaltet einen State-Bag mit Flags/Items/Reputation und wertet Szenen-Bedingungen dynamisch aus
**Depends on**: Phase 70 (v9.0 abgeschlossen)
**Requirements**: ENG-01, ENG-02, ENG-03, DB-01
**Success Criteria** (what must be TRUE):
  1. Ein Kampagnen-JSON mit Graph-Struktur (nodes + edges statt linearer scene-Liste) wird korrekt geladen und die Engine traversiert den Graph anhand der User-Entscheidungen — bei jeder Szene stehen nur die per Edge erreichbaren Nachfolge-Szenen zur Verfuegung
  2. Der State-Bag akkumuliert Flags, Items und Reputation-Werte ueber Szenen hinweg — eine Szene kann z.B. ein Flag setzen ("has_evidence") und eine spaetere Szene kann dieses Flag lesen und darauf reagieren
  3. Szenen mit Bedingungen (z.B. "requires: has_evidence" oder "min_reputation: 5") werden nur angeboten wenn die Bedingung im State-Bag erfuellt ist — nicht erfuellte Pfade sind unsichtbar
  4. Die DB-Migration erstellt die campaign_state Tabelle (state_bag JSON, act_number, graph_position, timestamps) ohne bestehende story_progress Daten zu zerstoeren — alte lineare Kampagnen funktionieren weiterhin
**Plans**: 2 plans
Plans:
- [ ] 71-01-PLAN.md — DB migration + CampaignState entity/mapper + CampaignGraphService
- [ ] 71-02-PLAN.md — StoryEngineService integration + test campaign JSON

### Phase 72: Akt-Struktur + Save/Resume + API
**Goal**: Kampagnen haben eine dramaturgische Akt-Struktur, der Spielstand persistiert zuverlaessig ueber Tage und REST-Endpoints ermoeglichen Graph-Traversal und State-Management
**Depends on**: Phase 71 (Graph-Engine und campaign_state Tabelle muessen existieren)
**Requirements**: ENG-04, ENG-05, API-01
**Success Criteria** (what must be TRUE):
  1. Szenen sind in 3-4 Akte gruppiert (Setup, Investigation, Eskalation, Showdown) und die Engine erzwingt die Akt-Reihenfolge — ein User kann nicht in den Showdown springen ohne die vorherigen Akte durchlaufen zu haben
  2. Der Spielstand (aktuelle Szene, State-Bag, Akt-Nummer, verstrichene Zeit) wird bei jeder Szenen-Transition automatisch in campaign_state gespeichert — nach Browser-Schluss und erneutem Oeffnen Tage spaeter laeuft die Kampagne exakt an der letzten Position weiter
  3. REST-Endpoints fuer Graph-Navigation (naechste Szene laden, Entscheidung senden), State-Abfrage (aktueller State-Bag, Position) und Save/Resume (Spielstand laden) sind funktional und per curl testbar — mit korrekter Fehlerbehandlung bei ungueltigen Transitionen
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 73: Rollen-System + Simulator-Integration
**Goal**: Charakterklassen beeinflussen den Kampagnenverlauf durch exklusive Szenen und Pfade, und v9.0-Simulatoren koennen als Aufgaben in Szenen eingebettet werden — mit Ergebnis-Rueckfluss in den State-Bag
**Depends on**: Phase 72 (Save/Resume und API muessen stehen, Akt-Struktur fuer sinnvolle Pfad-Verzweigung)
**Requirements**: ROLE-01, ROLE-02, TASK-01, TASK-02, TASK-03
**Success Criteria** (what must be TRUE):
  1. Eine Kampagne kann Szenen als rollenexklusiv markieren (z.B. "role: architect" oder "role: security") und die Engine blendet nur die Szenen ein die zur Charakterklasse des Users passen — ein Architect sieht Netzwerk-Szenen, ein Security-Spezialist sieht Forensik-Szenen
  2. Die Charakterklasse beeinflusst nicht nur welche Szenen sichtbar sind sondern auch welche Pfade im Graph verfuegbar werden — verschiedene Rollen erleben strukturell unterschiedliche Kampagnenverlaeufe (nicht nur kosmetische Unterschiede)
  3. Eine Szene kann einen Simulator (DNS, Firewall, Port-Scanner etc.) als Aufgabe referenzieren und das Simulator-Ergebnis (bestanden/nicht bestanden, Score, verstrichene Zeit) fliesst in den State-Bag zurueck — eine bestandene Firewall-Aufgabe kann z.B. das Flag "firewall_configured" setzen
  4. Timer-basierte Aufgaben sind moeglich: die Engine startet einen Countdown und wertet bei Ablauf automatisch als "nicht bestanden" — der Timer-Status ist im State-Bag gespeichert und ueberlebt Save/Resume
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 74: DAU-Bot
**Goal**: Ein KI-Gegner macht typische Anfaengerfehler die der User als Lern-Aufgabe korrigieren muss — der Bot wird zum paedagogischen Werkzeug
**Depends on**: Phase 73 (Simulator-Integration fuer Aufgaben-Kontext, Rollen-System fuer Szenen-Einbettung)
**Requirements**: BOT-01, BOT-02
**Success Criteria** (what must be TRUE):
  1. Der DAU-Bot generiert realistische Anfaengerfehler in Szenen-Kontext (z.B. Default-Passwoerter setzen, Firewall-Ports offen lassen, Backups vergessen) — die Fehler sind fachlich korrekt modelliert und nicht zufaellig
  2. Der User bekommt die Bot-Konfiguration/Entscheidung praesentiert und muss die Fehler identifizieren und korrigieren — das Korrektur-Ergebnis (richtig erkannt / uebersehen / falsch korrigiert) fliesst als Score in den State-Bag und beeinflusst den weiteren Kampagnenverlauf

### 📋 v11.0 Telos-Onboarding + VirtuProf Guide (Planned)

**Milestone Goal:** Jeder User bekommt ein persoenliches Lernprofil (Mini-Telos) durch ein VirtuProf-Interview beim ersten Login. VirtuProf wird zum kontextbewussten App-Guide.

- [ ] **Phase 75: DB-Migration + Telos-API + Interview-Backend** - user_telos Tabelle, REST-Endpoints, GeminiService Interview-Logik
- [ ] **Phase 76: Onboarding-Frontend + Formular-Fallback** - VirtuProf-Interview UI und Formular-Alternative ohne KI
- [ ] **Phase 77: Profil-Seite + Dozenten-Sicht** - Persoenliches Lernprofil mit Sichtbarkeits-Toggle und aggregiertes Klassen-Profil
- [ ] **Phase 78: VirtuProf Guide-Modus + Antwortlaengen** - Proaktive Tool-Erklaerungen und kontextbewusste Antwortsteuerung

### Phase 75: DB-Migration + Telos-API + Interview-Backend
**Goal**: Das Backend kann Telos-Daten speichern, lesen und aktualisieren, und VirtuProf fuehrt ein strukturiertes 10-Fragen-Interview dessen Antworten als Mini-Telos JSON persistiert werden
**Depends on**: Phase 74 (v10.0 abgeschlossen)
**Requirements**: TELOS-01, TELOS-02, API-01, DB-01
**Success Criteria** (what must be TRUE):
  1. Die user_telos Tabelle existiert mit allen Feldern (user_id, telos_json, bio, help_offer, help_wanted, visibility, onboarding_completed, created_at, updated_at) und die Migration laeuft sauber auf einer bestehenden DB ohne Datenverlust
  2. Die REST-Endpoints POST/GET/PUT /api/profile/telos sind per curl testbar — POST speichert ein Telos-JSON, GET liefert es zurueck, PUT aktualisiert einzelne Felder — mit korrekter Authentifizierung und Validierung
  3. VirtuProf fuehrt ueber die GeminiService-API ein 10-Fragen-Interview (Rolle, Erfahrung, Staerken, Schwaechen, Ziel, Zeitrahmen, Lernstil, Lernzeit, Motivation, Besonderes) und extrahiert aus den Antworten ein strukturiertes JSON das in user_telos gespeichert wird
  4. Das Interview startet automatisch beim ersten Login eines Users der noch kein Telos hat — bei bestehenden Usern mit Telos wird das Interview uebersprungen
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 76: Onboarding-Frontend + Formular-Fallback
**Goal**: Neue User durchlaufen ein interaktives Onboarding das entweder als VirtuProf-Chat oder als Formular funktioniert — in beiden Faellen entsteht ein vollstaendiges Mini-Telos
**Depends on**: Phase 75 (API-Endpoints und Interview-Backend muessen stehen)
**Requirements**: TELOS-03
**Success Criteria** (what must be TRUE):
  1. Beim ersten App-Besuch oeffnet sich automatisch das VirtuProf-Interview als Chat-Dialog — der User beantwortet 10 Fragen in natuerlicher Sprache und sieht am Ende eine Zusammenfassung seines Lernprofils
  2. Wenn KI deaktiviert ist (kein Gemini API Key oder User-Praeferenz), erscheint stattdessen ein Formular mit Dropdowns und Textfeldern fuer dieselben 10 Felder — das Ergebnis ist dasselbe Telos-JSON wie beim Interview
  3. Der User kann das Onboarding ueberspringen ("Spaeter") und wird beim naechsten Login erneut darauf hingewiesen — nach drei Ablehnungen wird nicht mehr gefragt
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 77: Profil-Seite + Dozenten-Sicht
**Goal**: User sehen ihr persoenliches Lernprofil mit Telos-Daten, Staerken/Schwaechen und Lernstatistiken, und Dozenten sehen ein aggregiertes Klassen-Profil ihrer Kurse
**Depends on**: Phase 76 (Telos-Daten muessen durch Onboarding befuellt sein)
**Requirements**: PROF-01, PROF-02, PROF-03, DOZ-01, DOZ-02
**Success Criteria** (what must be TRUE):
  1. Der User sieht auf seiner Profil-Seite: Telos-Daten (Rolle, Ziel, Zeitrahmen), automatisch berechnete Staerken/Schwaechen aus der Lernhistorie, aktuelles Level, Streak und Badges — alles auf einer Seite
  2. Der Sichtbarkeits-Toggle funktioniert: Profil auf "privat" ist nur fuer den User selbst sichtbar, "kurs" zeigt es Kursmitgliedern, "dozent" zeigt es nur dem Dozenten — die Einstellung wird sofort wirksam
  3. Der User kann sein Profil editieren: Telos-Daten aktualisieren, Bio hinzufuegen, "Ich kann helfen bei..." und "Ich suche Hilfe bei..." Felder pflegen — Aenderungen werden per PUT /api/profile/telos gespeichert
  4. Der Dozent sieht im Dozenten-Cockpit ein aggregiertes Klassen-Profil: Erfahrungslevel-Verteilung (Pie/Bar Chart), Ziel-Zertifizierungen, Durchschnitt Lernzeit/Woche und einen Pruefungs-Countdown pro User basierend auf target_date aus den Telos-Daten
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

### Phase 78: VirtuProf Guide-Modus + Antwortlaengen
**Goal**: VirtuProf wird zum kontextbewussten App-Guide der beim ersten Besuch eines Tools proaktiv erklaert und seine Antwortlaenge dynamisch anpasst — unter Nutzung der Telos-Daten fuer personalisierte Erklaerungen
**Depends on**: Phase 75 (Telos-Daten muessen lesbar sein; kann parallel zu Phase 77 laufen)
**Requirements**: GUIDE-01, GUIDE-02, GUIDE-03
**Success Criteria** (what must be TRUE):
  1. Wenn ein User zum ersten Mal ein Tool oder einen Modus oeffnet (Training, Leitner, Exam, PBQ, Subnetzrechner etc.), erscheint VirtuProf automatisch mit einer ausfuehrlichen Erklaerung — beim zweiten Besuch ist die Erklaerung kuerzer oder entfaellt, gesteuert ueber ein "first_visit" Tracking pro Tool
  2. VirtuProf antwortet standardmaessig kurz (~150 Tokens) — nur wenn der User eskaliert ("Erklaer genauer", "Mehr Details", "Warum?") wechselt VirtuProf auf ausfuehrliche Antworten (~2048 Tokens) fuer diese eine Antwort und kehrt dann zum kurzen Default zurueck
  3. VirtuProf nutzt die Telos-Daten des Users als Kontext in seinen Erklaerungen — ein Quereinsteiger bekommt grundlegendere Erklaerungen als ein erfahrener Admin, ein User mit Ziel "Security+" bekommt Security-relevante Beispiele bevorzugt
**Plans**: 3 plans

Plans:
- [ ] 81-01-PLAN.md — Backend full_graph endpoint + questMapEngine.js pure JS engine with tests
- [ ] 81-02-PLAN.md — D3 renderer + QuestMap.vue component with hexagonal nodes and overlay
- [ ] 81-03-PLAN.md — AbenteuerMode integration + visual verification

## Progress

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 75. DB-Migration + Telos-API | v11.0 | 0/? | Not started | - |
| 76. Onboarding-Frontend | v11.0 | 0/? | Not started | - |
| 77. Profil + Dozenten-Sicht | v11.0 | 0/? | Not started | - |
| 78. Guide-Modus + Antwortlaengen | v11.0 | 0/? | Not started | - |
| 80. SimulatorShell + Wiring | 3/3 | Complete   | 2026-03-25 | - |
| 81. Quest-Map | 3/3 | Complete   | 2026-03-25 | - |
| 82. HUD + Timer + DauBot-UI | v12.0 | 0/? | Not started | - |
| 83. Kampagnen-Content | v12.0 | 0/? | Not started | - |
| 84. Coop-Backend | v12.0 | 0/? | Not started | - |
| 85. Coop-Frontend | v12.0 | 0/? | Not started | - |
