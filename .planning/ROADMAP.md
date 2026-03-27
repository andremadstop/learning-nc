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
- 🚧 **v13.0 Feature Expansion** — Phases 90-95 (in progress)

## Phases

<details>
<summary>✅ v2.3 — v12.1 (Phases 1-89) — SHIPPED</summary>

Phases 1-89 shipped across milestones v2.3 through v12.1. See git history for details.

</details>

### 🚧 v13.0 Feature Expansion (Phases 90-95)

**Milestone Goal:** Die Learning-App um visuelle Identitaet, Story-Kampagne, Kompetenz-Visualisierung, Aktivitaets-Feed und Vue 3 Migrationsbewertung erweitern.

- [x] **Phase 90: NOVA Character Bible** - Persoenlichkeitsdefinition und Designgrundlage fuer alle NOVA-Visuals (completed 2026-03-27)
- [x] **Phase 91: NOVA Visual Implementation** - Animierte Bot-States, Reaktionslogik, Sound-Feedback, Vue-Komponenten (completed 2026-03-27)
- [x] **Phase 92: Ghostline Quest** - Network+ Kampagne mit Story-Arc, Terminal-Puzzles und Simulator-Integration (completed 2026-03-27)
- [ ] **Phase 93: Vue 3 Migration Evaluation** - Kompatibilitaetsanalyse, Migrationspfad und Aufwandsschaetzung
- [ ] **Phase 94: Kurs-Feed** - Ankuendigungen, Meilensteine und Activity Stream fuer Kursgruppen
- [ ] **Phase 95: Skill-Map** - D3.js Force-Graph zur Kompetenz-Visualisierung mit Lernfortschritt

## Phase Details

### Phase 90: NOVA Character Bible
**Goal**: NOVAs Persoenlichkeit, visuelle Sprache und Verhaltensregeln sind konsistent dokumentiert und dienen als verbindliche Referenz fuer alle visuellen Implementierungen
**Depends on**: Nothing (first phase of v13.0)
**Requirements**: NOVA-03
**Success Criteria** (what must be TRUE):
  1. Eine Character Bible existiert die NOVAs Persoenlichkeit (Tonfall, Humor-Stil, Grenzen) so definiert dass zwei verschiedene Entwickler denselben Bot-Charakter implementieren wuerden
  2. Die Bible definiert visuelle Design-Tokens (Farben, Formen, Proportionen) die in den Gemini-Deliverables (VISUAL_CONCEPT.md, UI_ANIMATION_GUIDE.md) referenziert werden
  3. Kontextabhaengige Verhaltensregeln sind dokumentiert — NOVA reagiert in Quiz anders als in Chat anders als in Kampagne, und diese Unterschiede sind explizit beschrieben
**Plans**: 1 plan
Plans:
- [ ] 90-01-PLAN.md — Consolidate Gemini deliverables + GeminiService.php into canonical Character Bible

### Phase 91: NOVA Visual Implementation
**Goal**: VirtuProf ist ein visuell lebendiger Bot mit Animationen, kontextabhaengigen Reaktionen, optionalem Sound-Feedback und sauberer Vue-Komponentenarchitektur
**Depends on**: Phase 90 (Character Bible als Design-Referenz)
**Requirements**: NOVA-01, NOVA-02, NOVA-04, NOVA-05
**Success Criteria** (what must be TRUE):
  1. VirtuProf zeigt sichtbar unterschiedliche Idle/Thinking/Speaking-Animationen — der User erkennt sofort ob der Bot wartet, nachdenkt oder antwortet
  2. Bot reagiert visuell auf User-Aktionen mit passenden Emotionen (Lob bei richtiger Antwort, Hinweis bei Fehler, Ermutigung bei Streak-Verlust) — die Reaktionen folgen der Reaction Logic aus den Gemini-Deliverables
  3. Sound-Feedback ist optional (User-Toggle in Einstellungen), dezent und unterstuetzt die visuellen Reaktionen — Sound aus ist der Default, Sound an fuegt kurze Audio-Cues hinzu
  4. Bubble, Dock und Overlay sind als eigenstaendige Vue-Komponenten implementiert die den Component Specs folgen — jede Komponente ist unabhaengig testbar und wiederverwendbar
**Plans**: 4 plans
Plans:
- [ ] 91-01-PLAN.md — NOVA Cyber-Sketch Avatar mit modularer Komponentenarchitektur und animierten States
- [ ] 91-02-PLAN.md — Web Audio API Sound-System mit User-Toggle
- [ ] 91-03-PLAN.md — Kontextabhaengige Reaktions-Logik (visuelle Emotionen + Sound-Cues)
- [ ] 91-04-PLAN.md — Dock und Panel Extraktion aus VirtuProf.vue Monolith

### Phase 92: Ghostline Quest
**Goal**: Studenten koennen eine vollstaendige Network+ Kampagne mit Story, Terminal-Puzzles und Simulator-Challenges durchspielen
**Depends on**: Nothing (nutzt bestehende Simulator-Infrastruktur aus v9.0 und Campaign Engine aus v12.0)
**Requirements**: GHOST-01, GHOST-02, GHOST-03, GHOST-04
**Success Criteria** (what must be TRUE):
  1. Die Kampagne hat mindestens 5 spielbare Szenen mit Terminal-Puzzles die Network+-Wissen abfragen — ein Student kann die Kampagne von Anfang bis Ende durchspielen
  2. Die Story hat einen erkennbaren Arc mit Protagonist, Antagonist und mindestens einem Wendepunkt — der Student will weiterspielen weil die Geschichte spannend ist
  3. NPC-Dialoge bieten verzweigte Optionen die den Spielverlauf beeinflussen — verschiedene Entscheidungen fuehren zu unterschiedlichen Szenen oder Ergebnissen
  4. Mindestens 3 Simulatoren (DNS, Firewall, Routing) sind als interaktive Challenges in Quest-Szenen eingebettet — der Student loest Netzwerk-Probleme innerhalb der Story statt in isolierten Uebungen
**Plans**: 2 plans
Plans:
- [ ] 92-01-PLAN.md — Foundation: TerminalPuzzle.vue, Ghostline CSS, DauBot ghostline category, schema updates
- [ ] 92-02-PLAN.md — Campaign content: ghostline_quest.json with 5-act story, branching paths, simulator integration

### Phase 93: Vue 3 Migration Evaluation
**Goal**: Eine fundierte Entscheidungsgrundlage existiert ob und wie die Migration von Vue 2 auf Vue 3 durchgefuehrt werden soll
**Depends on**: Nothing (reine Analyse, kann parallel laufen)
**Requirements**: VUE3-01, VUE3-02, VUE3-03
**Success Criteria** (what must be TRUE):
  1. Jede Vue 2 Komponente und jedes Plugin ist auf Vue 3 Kompatibilitaet geprueft — eine Tabelle zeigt fuer jede Komponente ob sie kompatibel, anpassbar oder neu geschrieben werden muss
  2. Ein konkreter Migrationspfad mit geordneten Schritten existiert — nicht "irgendwann migrieren" sondern "erst X, dann Y, dann Z" mit klaren Abhaengigkeiten
  3. Aufwand ist in T-Shirt Sizes (S/M/L/XL) pro Komponente geschaetzt und Risiken sind benannt — der User kann entscheiden ob Vue 3 Migration ein eigener Milestone wird oder aufgeschoben wird
**Plans**: 1 plan
Plans:
- [ ] 93-01-PLAN.md — Kompatibilitaetstabelle aller Komponenten + Migrationspfad + Risikobewertung + Go/No-Go

### Phase 94: Kurs-Feed
**Goal**: Dozenten und Studenten haben einen zentralen Activity Stream der Ankuendigungen, Meilensteine und neue Lerninhalte eines Kurses buendelt
**Depends on**: Nothing (eigenstaendiges Feature)
**Requirements**: FEED-01, FEED-02, FEED-03
**Success Criteria** (what must be TRUE):
  1. Ein Dozent kann eine Ankuendigung fuer einen Kurs posten die alle eingeschriebenen Studenten im Feed sehen — der Dozent muss nicht jeden einzeln benachrichtigen
  2. Studenten sehen im Dashboard einen Feed mit Ankuendigungen und erreichten Meilensteinen (z.B. "Pool X freigeschaltet", "Neuer Lerninhalt verfuegbar") — alles Wichtige an einem Ort
  3. Neue Lerninhalte und Kurs-Fortschritt erscheinen automatisch im Feed ohne manuelles Zutun — der Feed ist lebendig auch wenn der Dozent nichts postet
**Plans**: 1 plan
Plans:
- [ ] 94-01-PLAN.md — [To be planned]

### Phase 95: Skill-Map
**Goal**: Studenten sehen ihre Kompetenzen als interaktiven Force-Graph der Staerken und Luecken auf einen Blick sichtbar macht
**Depends on**: Nothing (eigenstaendiges Feature, nutzt bestehende Lernfortschritt-Daten)
**Requirements**: SKILL-01, SKILL-02, SKILL-03
**Success Criteria** (what must be TRUE):
  1. Ein D3.js Force-directed Graph zeigt Kompetenz-Cluster als verbundene Nodes — der Student sieht welche Themengebiete zusammenhaengen und wie gross jedes Cluster ist
  2. Nodes faerben sich nach Lernfortschritt (rot = schwach, gelb = in Arbeit, gruen = gemeistert) — der Student erkennt auf einen Blick wo Luecken sind
  3. Ein Klick auf einen Node zeigt die zugehoerigen Karteikarten — der Student kann direkt aus der Map heraus schwache Bereiche gezielt ueben
**Plans**: 1 plan
Plans:
- [ ] 95-01-PLAN.md — [To be planned]

## Progress

**Execution Order:**
Phase 90 first (NOVA foundation). Phase 91 after 90. Phases 92-95 independent, can run in any order or parallel.

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 90. NOVA Character Bible | 1/1 | Complete    | 2026-03-27 | - |
| 91. NOVA Visual Implementation | v13.0 | Complete    | 2026-03-27 | - |
| 92. Ghostline Quest | 2/2 | Complete   | 2026-03-27 | - |
| 93. Vue 3 Migration Evaluation | v13.0 | 0/1 | Planned | - |
| 94. Kurs-Feed | v13.0 | 0/? | Not started | - |
| 95. Skill-Map | v13.0 | 0/? | Not started | - |
