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
- [ ] **Phase 54: Content-Verteilung** - Shared Folder einrichten, Guides ablegen, RAG-Quellen registrieren
- [ ] **Phase 55: DevCloud-Hygiene** - Ordner-Redundanzcheck, Aufraeumen, OSSU-Evaluation

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
**Plans**: TBD

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
**Plans**: TBD

### Phase 60: VLAN-Tab
**Goal**: Ein neuer VLAN-Tab im Subnetzrechner visualisiert VLAN-Zuordnungen, 802.1Q Tagging und Inter-VLAN Routing — der User versteht die Zusammenhaenge zwischen VLANs und Subnetzen
**Depends on**: Phase 56 (Toggle-Pattern fuer konsistente UI)
**Requirements**: VLAN-01, VLAN-02, VLAN-03
**Success Criteria** (what must be TRUE):
  1. Ein neuer Tab "VLAN" ist im Subnetzrechner sichtbar und erlaubt dem User VLAN-IDs einzugeben und Subnetzen zuzuordnen — die Zuordnung ist sofort visuell erkennbar
  2. Eine Visualisierung zeigt Access-Ports und Trunk-Ports mit 802.1Q Tagging — der User sieht welche Frames getaggt werden und welche nicht (untagged auf Access, tagged auf Trunk)
  3. Eine Inter-VLAN Routing Darstellung zeigt Router-on-a-Stick oder L3-Switch Konfiguration mit Subinterfaces und VLAN-Zuordnung — der User versteht wie Pakete zwischen VLANs geroutet werden

**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 52 -> 53 -> 54 -> 55 -> 56 -> 57 -> 58 -> 59 -> 60

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 52. Bugfix & Release | v4.0 Housekeeping | 1/1 | Complete | 2026-03-24 |
| 53. Content-Bereinigung | v4.0 Housekeeping | 1/1 | Complete | 2026-03-24 |
| 54. Content-Verteilung | v4.0 Housekeeping | 0/1 | Not started | - |
| 55. DevCloud-Hygiene | v4.0 Housekeeping | 0/TBD | Not started | - |
| 56. Toggle-Spalten | 1/1 | Complete    | 2026-03-24 | - |
| 57. IPv6 Math + UI | 1/1 | Complete    | 2026-03-24 | - |
| 58. Uebungsmodus Engine | 2/2 | Complete   | 2026-03-24 | - |
| 59. Szenarien-Content | v7.2 Subnetzrechner Pro | 0/TBD | Not started | - |
| 60. VLAN-Tab | v7.2 Subnetzrechner Pro | 0/TBD | Not started | - |
