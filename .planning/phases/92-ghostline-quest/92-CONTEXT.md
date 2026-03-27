# Phase 92: Ghostline Quest - Context

**Gathered:** 2026-03-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Eine vollstaendige, spielbare Network+ Kampagne "Ghostline Quest" mit Story-Arc, Terminal-Puzzles, Simulator-Integration und verzweigten Entscheidungspfaden. Nutzt die bestehende Campaign Engine (Graph-Format), SimulatorShell, DauBot und VirtuProf-Integration. Neue Komponente: TerminalPuzzle.vue. Visueller Ghostline-Modus (Glitch-Effekte) als CSS-Layer.

</domain>

<decisions>
## Implementation Decisions

### Claude's Discretion (volle Autonomie)

User hat volle Autonomie erteilt. Alle Bereiche — Terminal-Puzzle Mechanik, Ghostline Visual Takeover, Story-Umfang, Network+ Lernziel-Mapping — liegen in Claudes Ermessen. Die folgenden Leitplanken muessen eingehalten werden:

### Stil & Atmosphaere
- **Mr. Robot Vibes**: Paranoia, Hacker-Kultur, "Wer kontrolliert wen?", unreliable narrator Momente
- **23 (Karl Koch/CCC)**: Deutsche Hacker-Geschichte, Idealismus vs. Kriminalitaet, "Wir haben es euch gesagt"
- **CCC "told you so" Charme**: Aufklaererischer Unterton, kritische Infrastruktur-Awareness, "Wir warnen seit Jahren" Haltung
- **Ueberzeichnete Charaktere**: Archetypen aus der Security-Welt, gute UND boese Hacker, moralische Graubereiche
- **Humor**: Wie Phase 83 — stark ueberzeichnet aber nie gemein, Galgenhumor, Insider-Witze die auch Einsteiger verstehen

### Pacing & Werkzeug-Integration
- **KRITISCH**: Simulatoren und Werkzeuge organisch einstreuen, NICHT als Bloecke hintereinander
- Normale Quizfragen und Story-Zwischensequenzen als Puffer zwischen Tool-Einsaetzen
- Guter Rhythmus: Story → Quiz → Story → Simulator → Story → DauBot → Story → Terminal-Puzzle
- Variation ist wichtiger als Dichte — lieber weniger Tools mit guter Story-Einbettung als Tool-Spam
- Alle vorhandenen App-Tools nutzen: FirewallBuilder, DnsResolver, RoutingTable, PortScanner, WiresharkLite, AuthFlow, DauBot
- Jedes Tool maximal 1-2x pro Kampagne, aber verschiedene Szenarien

### Story-Scope
- Komplex: Mindestens 5 Szenen, mehrere Akte, verzweigte Entscheidungspfade
- Kampagnen-Variationen: Unterschiedliche Wege durch die Quest, nicht linear
- Gute und boese Hacker als NPCs — moralische Entscheidungen mit Konsequenzen
- Aktuelle Themen: Ransomware, Supply-Chain-Attacks, Zero-Days, Social Engineering, kritische Infrastruktur
- Network+ kursrelevant: CompTIA Network+ Objectives als thematische Grundlage, aber in spannende Szenarien verpackt

### Terminal-Puzzle Mechanik (Claude's Discretion)
- Neues Vue-Component: TerminalPuzzle.vue
- Integration in SimulatorShell via SIMULATOR_MAP erweitern (type: "terminal")
- CLI-aehnliche Eingabe mit validierten Commands (nicht komplett freie Shell)
- Retro-Terminal Aesthetic passend zum Ghostline-Theme (Glitch, Scanlines)
- Puzzle-Typen: Log-Analyse, Command-Eingabe, Packet-Inspection, Config-Fixes

### Ghostline Visual Takeover (Claude's Discretion)
- Basiert auf Gemini-Spec: `.planning/reference/GHOSTLINE_UI_SPEC.md`
- Glitch-Effekte: Chromatic Aberration, Scanlines, Terminal-Overlay
- Farbwechsel: Cyan → Magenta/Rose (#d946ef / #f43f5e) im Danger-State
- Scope: Campaign-Panel + VirtuProf-Bereich, NICHT die komplette NC-App
- NOVA → Ghostline Transition: CSS-Klasse `.ghostline-mode` auf Campaign-Container
- Rueckkehr nach Quest: Visueller "Reboot" (kurzer Flash → Standard)

### Network+ Lernziel-Mapping (Claude's Discretion)
- CompTIA Network+ Domains als Grundlage, aber nicht sklavisch an Objectives gebunden
- Typische Szenarien: Netzwerk-Ausfall, Intrusion Detection, Firewall-Konfiguration, DNS-Poisoning, Routing-Probleme
- Praxisnahe Situationen statt trockene Pruefungsfragen
- Lerneffekt durch Story-Kontext: "Warum ist das wichtig?" kommt durch die Handlung

</decisions>

<specifics>
## Specific Ideas

- "Ein bisschen Mr. Robot, ein bisschen 23, viel CCC told you so" — das ist der Ton
- Simulatoren organisch in die Story einbauen, nicht von einem zum naechsten springen
- Normale Fragen und Zwischensequenzen als Puffer zwischen Werkzeug-Einsaetzen
- Gute und boese Hacker, komplexe Entscheidungspfade, Kampagnen-Variationen
- Aktuelle Security-Themen (Ransomware, Supply-Chain, Zero-Days)
- Ueberzeichnete Charaktere die trotzdem ernst genommen werden
- Kursrelevante Inhalte in spannende Szenarien verpackt
- Stabiliaet ist wichtig — muss sauber in der bestehenden Engine laufen

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- `CampaignGraphService.php` (843 Zeilen): Graph-basierte Szenen-Navigation, State-Bag (flags, items, reputation, timers, simulators), immutable Updates
- `StoryEngineService.php` (92KB): Campaign Loader, Skill-Checks, Character-Filtering, Schema-Validierung
- `DauBotService.php` (20KB): Deterministische Fehler-Szenarien, 5 Kategorien (default, firewall, dns, routing, nat) — erweiterbar um "ghostline" Kategorie
- `SimulatorShell.vue` + `simulatorShellLogic.js`: Dynamischer Component-Loader, Szenario-Resolution, @result Event mit {passed, score}
- `GeminiService.php`: Chat mit questionContext + telosProfile — Campaign-spezifischer Bot-Kontext moeglich
- `VirtuProf.vue`: Multi-Step Bubble, Chat-History, Emotion-States, Reaction-Engine
- `nova-reaction-engine.js`: Kontextabhaengige Emotionen — erweiterbar fuer Ghostline-Reaktionen
- 7 Simulator-Komponenten: FirewallBuilder, DnsResolver, RoutingTable, NatTable, PortScanner, WiresharkLite, AuthFlowSimulator
- 27 bestehende Kampagnen als Referenz-Format in `app/data/campaigns/`
- `campaign-schema.json`: Validierungs-Schema fuer Campaign JSON

### Established Patterns
- Campaign JSON: nodes[] mit id/type/title/narrative/choices, edges[] mit from/to/label/conditions
- Simulator-Einbettung: Node `type: "simulator"` mit `simulator: {type, scenario, pass_flag}`
- DauBot-Einbettung: Node `type: "bot_correction"` mit `bot_correction: {category, error_options[], fix_options[]}`
- Timer: Node `timer: {seconds, consequence: {type, target_edge}}`
- State-Bag: `{flags, items, reputation, timers, simulators, bot_corrections, _visited_nodes}`

### Integration Points
- Neues TerminalPuzzle.vue in `SIMULATOR_MAP` eintragen (SimulatorShell.vue)
- Campaign JSON in `app/data/campaigns/ghostline_quest.json`
- CSS Ghostline-Mode als Klasse auf Campaign-Container (kein globaler App-Skin)
- DauBot-Kategorie "ghostline" in DauBotService.php Template-Katalog
- campaign-schema.json um node-type "terminal" erweitern (falls noetig)

</code_context>

<deferred>
## Deferred Ideas

- Cyber-Deck Hardware-UI Redesign fuer Simulatoren — v14.0 (`.planning/reference/CYBER_DECK_GUIDE_v14.md`)
- OSI-Layer Diagnose-Overlay (NOVA erklaert Fehler visuell) — spaetere Phase (`.planning/reference/DIAGNOSTIC_POC.html`)
- Kampagnen-Editor GUI — eigener Milestone
- Generative Kampagnen via Gemini — eigener Milestone
- Difficulty-Scaling der Simulator-Szenarien — eigene Phase

</deferred>

---

*Phase: 92-ghostline-quest*
*Context gathered: 2026-03-27*
