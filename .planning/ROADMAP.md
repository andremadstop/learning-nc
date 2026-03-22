# Roadmap: Learning-NC

## Milestones

- ✅ **v2.3 PBQ OnVUE-Niveau Upgrade** — Phases 1-6 (shipped 2026-03-17)
- ✅ **v2.6 Live-Duell** — Phase 7 (shipped 2026-03-18)
- ✅ **v3.0 Gameshow-Modi** — Phases 8-13 (shipped 2026-03-20)
- ✅ **v3.1 UX-Konsolidierung** — Phases 14-16 (shipped 2026-03-21)
- ✅ **v3.2 VirtuProf KI-Assistent** — Phases 17-21 (shipped 2026-03-21)
- ✅ **v4.0 Persönlicher Lernbot** — Phases 22-27 (shipped 2026-03-21)
- ✅ **v5.0 Oldschool (Brettspiel-Modi)** — Phases 28-31 (shipped 2026-03-21)
- ✅ **v6.0 Abenteuer (Story-RPG)** — Phases 32-35 (shipped 2026-03-22)
- 🚧 **v4.1 RAG Stufe 2** — Phases 36-39 (in progress)

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

<details>
<summary>✅ v3.2 VirtuProf KI-Assistent (Phases 17-21) - SHIPPED 2026-03-21</summary>

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
- [x] 17-01-PLAN.md — GeminiService.php + 5-layer security stack + Admin Settings API key wiring
- [x] 17-02-PLAN.md — VirtuProfController::chat() endpoint + route registration + deploy

### Phase 18: RAG-Context
**Goal**: VirtuProf kennt den Lernkontext des Users — welcher Pool, welcher Kurs, welche Fragen er zuletzt falsch beantwortet hat
**Depends on**: Phase 17 (GeminiService muss existieren)
**Requirements**: RAG-01, RAG-02, RAG-03, RAG-04
**Success Criteria** (what must be TRUE):
  1. Wenn ein User VirtuProf in einem aktiven Pool fragt, enthaelt die Antwort Bezug zu Fragen oder Themen aus diesem Pool
  2. Fragt der User "Warum habe ich diese Frage falsch?", nennt VirtuProf die konkrete falsche Antwort und erklaert die korrekte
  3. VirtuProf nennt auf Anfrage den Leitner-Box-Status des Users (z.B. "Du hast 12 Karten in Box 1")
  4. Bei sehr langen Pools (>100 Fragen) wird der Kontext automatisch auf die relevantesten Inhalte begrenzt — kein API-Fehler wegen Token-Overflow
**Plans**: 1 plan

Plans:
- [x] 18-01-PLAN.md — RagContextService.php + Kontext-Builder

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
**Plans**: 1 plan

Plans:
- [x] 19-01-PLAN.md — VirtuProfBubble.vue Freitext-Chat + Session-Verlauf

### Phase 20: Ticket-Triage
**Goal**: Support-Tickets werden automatisch klassifiziert und einfache Fragen automatisch beantwortet — Admin-Postfach entlastet
**Depends on**: Phase 17 (GeminiService), Phase 18 (RAG-Context fuer bessere FAQ-Matches)
**Requirements**: TRIAGE-01, TRIAGE-02, TRIAGE-03, TRIAGE-04
**Success Criteria** (what must be TRUE):
  1. Jedes neue Support-Ticket zeigt in der Admin-Inbox ein automatisch gesetztes Label: FAQ, Bug, Feature oder Unclear
  2. Tickets mit Label FAQ haben eine automatisch generierte Antwort als Draft — Admin kann sie mit einem Klick absenden
  3. Tickets mit Label Bug oder Feature sind als "needs_review" markiert und erscheinen oben in der Inbox-Sortierung
  4. Wenn die KI-Klassifizierung unsicher ist (Confidence < 0.7), wird dem User eine Rueckfrage gestellt statt einer Auto-Antwort
**Plans**: 1 plan

Plans:
- [x] 20-01-PLAN.md — TriageService.php + Admin-Inbox Klassifizierung

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
**Plans**: 1 plan

Plans:
- [x] 21-01-PLAN.md — Opt-in Flow + Admin-Toggle + Privacy-Dokumentation

</details>

<details>
<summary>✅ v4.0 Persönlicher Lernbot (Phases 22-27) - SHIPPED 2026-03-21</summary>

### Phase 22: Lernprofil
**Goal**: User hat ein maschinenlesbares Stärken/Schwächen-Profil das jede Lernsession automatisch aktualisiert — die Datenbasis für alle Bot-Aktionen
**Depends on**: Phase 21 (v3.2 shipped)
**Requirements**: PROF-01, PROF-02, PROF-03, PROF-04
**Success Criteria** (what must be TRUE):
  1. Nach einer Trainings- oder Exam-Session ist das Profil des Users aktualisiert und zeigt die 5 schwächsten Themen nach Fehlerrate sortiert
  2. Das Profil enthält für jedes Thema einen Trend-Indikator (verbessert / verschlechtert / stabil) basierend auf den letzten 3 Sessions
  3. Ein API-Aufruf auf `/api/profile` liefert aggregierte Daten aus Leitner-Boxen, Training-Scores und Exam-Ergebnissen in einem einzigen Response
  4. Das Profil wird passiv aktualisiert — kein manueller Aufruf nötig, keine spürbare Verlangsamung der Lernsession
**Plans**: 1 plan

Plans:
- [x] 22-01-PLAN.md — LernprofilService.php + Profil-Aggregation + API-Endpoint

### Phase 23: NC Files Integration
**Goal**: Der Bot kann Markdown-Notes im NC-Dateisystem des Users erstellen und aktualisieren — Obsidian-kompatibel, User besitzt seine Daten
**Depends on**: Phase 22 (Lernprofil muss existieren für sinnvolle Dateinamen/Metadaten)
**Requirements**: FILES-01, FILES-02, FILES-03, FILES-04, FILES-05
**Success Criteria** (what must be TRUE):
  1. Beim ersten Bot-Aufruf erscheint im Nextcloud-Dateisystem des Users der Ordner /Learning/ mit Unterordnern /Zusammenfassungen/ und /Schwachstellen/
  2. Eine Bot-generierte Note öffnet sich in einem Markdown-Editor mit korrektem YAML Frontmatter (created, source, topic, status, chapter)
  3. Die Note enthält mindestens einen Wiki-Link ([[...]]) und mindestens einen Tag (#schwach oder #gemeistert) — kompatibel mit Obsidian
  4. Wird dieselbe Note erneut generiert (gleicher Dateiname = gleiches Thema), wird die bestehende Datei aktualisiert statt eine neue angelegt
**Plans**: 1 plan

Plans:
- [x] 23-01-PLAN.md — NcFilesService.php + Ordnerstruktur + Frontmatter-Generator

### Phase 24: Note-Generator
**Goal**: Gemini erstellt inhaltlich gehaltvolle Zusammenfassungen für schwache Themen — gespeichert als NC-Files-Notes mit konkretem Lernnutzen
**Depends on**: Phase 23 (NC Files Integration muss Notes schreiben können)
**Requirements**: NOTE-01, NOTE-02, NOTE-03, NOTE-04
**Success Criteria** (what must be TRUE):
  1. Nach einem Trigger öffnet der User eine generierte Note und findet: Kernpunkte des Themas, seinen häufigsten Fehler, eine konkrete Übungsempfehlung
  2. Die Note enthält Wiki-Links zu mindestens einer verwandten Simulation oder Frage aus dem Pool
  3. Wenn der User für dasselbe Thema ein zweites Mal eine Note anfordert, wird die bestehende Note aktualisiert — keine Duplikate im /Zusammenfassungen/-Ordner
  4. Die Gemini-Anfrage enthält keine persönlichen Daten des Users — nur Thema, Fehlermuster und Frageinhalte
**Plans**: 1 plan

Plans:
- [x] 24-01-PLAN.md — NoteGeneratorService + NoteGeneratorController + GeminiService::generateNote()

### Phase 25: Lernplan + Fortschritt
**Goal**: User findet jede Woche einen aktuellen Lernplan und ein Fortschritts-Dashboard als Markdown in seinem NC-Dateisystem
**Depends on**: Phase 24 (Note-Generator muss laufen, Profil und Files sind etabliert)
**Requirements**: PLAN-01, PLAN-02, PLAN-03, PLAN-04
**Success Criteria** (what must be TRUE):
  1. /Learning/Lernplan.md enthält einen wöchentlichen Plan mit Tages-Checkboxen (- [ ] Montag: ...) basierend auf den aktuellen Profil-Schwächen
  2. /Learning/Fortschritt.md zeigt die aktuelle Leitner-Box-Verteilung, den Trend der letzten 4 Wochen und konkrete Empfehlungen
  3. Der Lernplan referenziert schwache Kapitel per Wiki-Link auf vorhandene /Zusammenfassungen/-Notes
  4. Beide Dateien sind lesbar ohne Learning-NC — reines Markdown, kein App-Lock-in
**Plans**: 1 plan

Plans:
- [x] 25-01-PLAN.md — LernplanService.php + FortschrittService.php + NC BackgroundJob

### Phase 26: Chat-Memory
**Goal**: VirtuProf erinnert sich über Sessions hinweg an den Kontext des Users — Gespräche bauen aufeinander auf statt jedes Mal von vorne zu beginnen
**Depends on**: Phase 22 (Lernprofil), Phase 19 (Chat-UI ist die Oberfläche)
**Requirements**: MEM-01, MEM-02, MEM-03, MEM-04
**Success Criteria** (what must be TRUE):
  1. Wenn ein User VirtuProf in einer neuen Session fragt "Erinnerst du dich an unser letztes Gespräch?", kann der Bot auf konkrete Inhalte aus früheren Sessions verweisen
  2. VirtuProf nennt bei einer Erklärungsfrage nicht dieselbe Erklärung die bereits gegeben wurde — er baut auf dem bekannten Kontext auf
  3. Der User kann in den Einstellungen "Chat-History löschen" klicken und VirtuProf hat danach keinerlei Erinnerung an frühere Sessions
  4. Wenn 50 Kontext-Einträge erreicht sind, werden die ältesten automatisch zu einer Zusammenfassung komprimiert — kein Datenverlust, kein API-Fehler
**Plans**: 1 plan

Plans:
- [x] 26-01-PLAN.md — ChatMemoryService.php + Komprimierungs-Job + Settings-Toggle

### Phase 27: Auto-Trigger
**Goal**: Der Bot handelt proaktiv — nach einem schlechten Exam, nach wiederholten Fehlern und wöchentlich — ohne dass der User manuell eingreifen muss
**Depends on**: Phase 24 (Note-Generator), Phase 25 (Lernplan-Generator), Phase 26 (Chat-Memory)
**Requirements**: TRIG-01, TRIG-02, TRIG-03, TRIG-04
**Success Criteria** (what must be TRUE):
  1. Nach einem Exam mit weniger als 70% erscheint in der VirtuProfBubble ein Hinweis "Ich habe eine Zusammenfassung für dein schwächstes Thema erstellt" — die Note ist in /Learning/Schwachstellen/ auffindbar
  2. Nach 5 falschen Antworten zum gleichen Thema erscheint ein "Zusammenfassung erstellen"-Button direkt in der Trainingsansicht
  3. Jeden Sonntag wird /Learning/Lernplan.md automatisch aktualisiert (NC BackgroundJob) — der User findet Montagmorgens einen frischen Plan
  4. Der User kann manuell für jedes Kapitel "Zusammenfassung erstellen" anfordern und erhält innerhalb von 10 Sekunden eine Note
**Plans**: 1 plan

Plans:
- [x] 27-01-PLAN.md — AutoTriggerService.php + ExamFinish-Hook + BackgroundJob-Wiring

</details>

<details>
<summary>✅ v5.0 Oldschool (Brettspiel-Modi) (Phases 28-31) - SHIPPED 2026-03-21</summary>

### Phase 28: Brettspiel-Backend
**Goal**: GameshowService unterstützt rundenbasierte Brettspiel-Sessions mit persistentem Spielfeld-State — beide Spiele können darauf aufbauen
**Depends on**: Phase 27 (v4.0 shipped)
**Requirements**: BACK-01, BACK-02, BACK-03
**Success Criteria** (what must be TRUE):
  1. Eine Session mit mode='lernwuerfel' oder mode='wissensturm' kann erstellt werden und verwaltet Spieler-Reihenfolge, aktiven Spieler und Spielfeld-Zustand im Session-JSON
  2. Der API-Endpunkt gibt bei jedem Poll den aktuellen Spielfeld-State zurück: Figurpositionen (Lernwürfel) oder Turm-Blöcke (Wissensturm) aller Spieler
  3. Nach einer Spieler-Aktion (Würfeln + Antwort) wechselt der aktive Spieler automatisch zum nächsten in der Reihe — kein manueller Trigger nötig
  4. Scoring-Logik für beide Modi ist serverseitig implementiert und beeinflusst den Session-State (Figurposition vorwärts / Block hinzufügen / Steal)
**Plans**: TBD

### Phase 29: Oldschool-Menü
**Goal**: Spieler finden den Oldschool-Bereich in CourseDetail und können zwischen Lernwürfel und Wissensturm wählen
**Depends on**: Phase 28 (Backend muss Sessions für beide Modi anlegen können)
**Requirements**: OLD-01, OLD-02
**Success Criteria** (what must be TRUE):
  1. In CourseDetail erscheint ein "Oldschool" Tab neben dem Arena-Tab — sichtbar für alle Kurs-Mitglieder
  2. Im Oldschool-Tab zeigt OldschoolSelector.vue zwei Karten (Lernwürfel, Wissensturm) mit Kurzbeschreibung und Start-Button
  3. Ein Klick auf eine Karte startet die Lobby des jeweiligen Spiels — analog zum Arena-Flow
**Plans**: TBD

### Phase 30: Lernwürfel
**Goal**: Spieler können eine vollständige Runde Mensch-ärgere-dich-nicht mit Lernfragen spielen — vom Würfeln bis zum Sieg
**Depends on**: Phase 29 (Oldschool-Menü muss in Lernwürfel-Lobby routen)
**Requirements**: WUERF-01, WUERF-02, WUERF-03, WUERF-04, WUERF-05, WUERF-06, WUERF-07
**Success Criteria** (what must be TRUE):
  1. Das Spielbrett als SVG mit 30 Feldern ist sichtbar; die Figuren der Spieler (farbige Kreise) bewegen sich nach richtiger Antwort auf das korrekte Feld
  2. Der Würfel animiert (CSS rotate) und zeigt die gewürfelte Zahl — bei einer 6 erscheint automatisch ein zweiter Wurf
  3. Nach dem Würfeln erscheint eine Frage aus dem Pool; richtige Antwort = Figur rückt vor, falsche Antwort = Figur bleibt stehen
  4. Landet eine Figur auf einem besetzten Feld, wird der Gegner auf Start zurückgesetzt; Sonderfelder (★) lösen Bonus-Würfel, Schutz oder Falle aus
  5. Erreicht die erste Figur Feld 30, erscheint Confetti + VirtuProf-Glückwunsch und das Spiel endet
**Plans**: 1 plan

Plans:
- [x] 30-01-PLAN.md — LernwuerfelMode.vue SVG board + full game flow

### Phase 31: Wissensturm
**Goal**: Spieler können eine vollständige Runde Wissensturm spielen — Kategorien wählen, Blöcke sammeln, stehlen und gewinnen
**Depends on**: Phase 29 (Oldschool-Menü muss in Wissensturm-Lobby routen)
**Requirements**: TURM-01, TURM-02, TURM-03, TURM-04, TURM-05
**Success Criteria** (what must be TRUE):
  1. Der Spieler sieht 5 Kategorie-Buttons (je eine Farbe, entsprechend den 5 Pools/Kapiteln) und kann eine davon auswählen
  2. Nach richtiger Antwort erscheint ein Block dieser Farbe sichtbar auf dem Turm des Spielers im SVG-Rendering
  3. Eine falsche Antwort löst eine Verlust-Animation aus (oberster Block fällt) und entfernt den Block vom Turm
  4. Bei einem Steal (Gegner antwortet falsch, aktueller Spieler richtig) wechselt der Block sichtbar vom Gegner-Turm auf den eigenen Turm
  5. Sobald ein Spieler alle 5 Farben auf seinem Turm hat, endet das Spiel mit Sieger-Anzeige und VirtuProf-Kommentar
**Plans**: TBD

</details>

<details>
<summary>✅ v6.0 Abenteuer (Story-RPG) (Phases 32-35) - SHIPPED 2026-03-22</summary>

### Phase 32: Story-Engine Backend
**Goal**: StoryEngine.php lädt und verwaltet Kampagnen vollständig — Szenen, Entscheidungen, Skill-Checks und Fortschritt funktionieren serverseitig und sind bereit für das Frontend
**Depends on**: Phase 31 (v5.0 shipped)
**Requirements**: STORY-01, STORY-02, STORY-03, STORY-04, STORY-05
**Success Criteria** (what must be TRUE):
  1. Ein API-Aufruf auf `/api/story/campaign/{id}/scene/{sceneId}` liefert narrative Texte, Entscheidungsoptionen und einen optionalen Skill-Check aus echten Pool-Fragen
  2. Ein Skill-Check-Ergebnis (richtig/falsch) wird an den Server gesendet und die Engine liefert die korrekte Folge-Szene gemäß dem verzweigenden Story-Baum zurück
  3. Pool-Fragen werden nach dem `pool_filter`-Feld der Szene gefiltert — eine Szene mit filter="routing" zieht ausschliesslich Fragen zum Thema Routing
  4. Der Kampagnen-Fortschritt eines Users (aktuelle Szene, getroffene Entscheidungen, Ergebnisse) überlebt einen Browser-Neustart — er landet beim nächsten Login an derselben Stelle
  5. Alle 5 Kampagnen-JSONs werden vom Service korrekt geladen und validiert — ein malformatiertes JSON wirft einen strukturierten Fehler statt einem 500er
**Plans**: TBD

### Phase 33: RPG-Frontend + Tab
**Goal**: Spieler können eine Kampagne im Browser vollständig spielen — von der Kampagnen-Auswahl über Szenen und Skill-Checks bis zum Abschluss-Screen
**Depends on**: Phase 32 (Story-Engine muss Szenen und Skill-Check-Ergebnisse liefern)
**Requirements**: RPG-01, RPG-02, RPG-03, RPG-04, RPG-05
**Success Criteria** (what must be TRUE):
  1. In CourseDetail erscheint ein "Abenteuer" Tab; ein Klick darauf zeigt die Kampagnen-Übersicht mit Fortschrittsanzeige pro Kampagne (noch nicht gestartet / In Szene X / Abgeschlossen)
  2. Eine Szene zeigt narrative Text-Box, NPC-Dialog (Portrait + Text) und 2-4 Entscheidungs-Karten nebeneinander — ein Klick auf eine Karte löst den nächsten Schritt aus
  3. Enthält ein Schritt einen Skill-Check, erscheint die Frage aus dem Pool mit Antwortoptionen; nach der Antwort zeigt eine Animation (grüner Haken = Erfolg, roter X = Misserfolg) das Ergebnis
  4. Im Koop-Modus (2-4 Spieler) sehen alle Spieler dieselbe Szene gleichzeitig; bei einer Entscheidung erscheint eine Abstimmungs-UI und die Mehrheit bestimmt den Weg
  5. AbenteuerMode.vue ist auch standalone aufrufbar (ohne Kurs-Kontext) über einen direkten App-Route-Eintrag
**Plans**: TBD

### Phase 34: Charakter-System + Simulation-Integration
**Goal**: Spieler wählen eine Klasse und spüren diesen Unterschied in Skill-Checks; jede Kampagne endet mit einer echten PBQ-Simulation deren Ergebnis den Epilog beeinflusst
**Depends on**: Phase 33 (RPG-Frontend muss Charakter-Auswahl und Simulations-Trigger rendern können)
**Requirements**: CHAR-01, CHAR-02, CHAR-03, SIM-01, SIM-02
**Success Criteria** (what must be TRUE):
  1. Beim Start einer Kampagne wählt der Spieler eine von 4 Klassen (Architekt, Security, Sysadmin, Helpdesk); die Wahl ist für die gesamte Kampagne gespeichert
  2. Ein Architekt der eine Routing-Frage beantwortet sieht eine leichtere Frage (niedrigerer Schwierigkeitsgrad aus Pool) als ein Helpdesk-Spieler derselben Kampagne
  3. NPC-Dialoge zeigen ein Text-Portrait (Emoji oder SVG-Platzhalter) und unterscheiden sich inhaltlich je nach gewählter Charakter-Klasse
  4. Am Ende jeder Kampagne startet eine PBQ-Simulation (bestehende PbqRenderer-Komponente) — der Spieler muss ein Netzwerk-Szenario konfigurieren
  5. Das Ergebnis der Simulation (Vollständig gelöst / Teilweise / Nicht gelöst) bestimmt welcher von drei Epilog-Texten angezeigt wird
**Plans**: TBD

### Phase 35: Kampagnen-Content
**Goal**: Alle 5 Kampagnen existieren als vollständige, spielbare JSON-Dateien mit je 5 Szenen, Entscheidungszweigen, Skill-Check-Mappings und einer finalen Simulation
**Depends on**: Phase 34 (Charakter-System und Simulations-Integration müssen verstanden sein, damit JSON-Schema korrekt befüllt wird)
**Requirements**: CAMP-01, CAMP-02, CAMP-03, CAMP-04, CAMP-05
**Success Criteria** (what must be TRUE):
  1. Kampagne 1 "Der große Ausfall" ist spielbar: alle 5 Szenen laden, Routing/VLAN/WLAN-Skill-Checks ziehen reale Fragen, beide Entscheidungszweige (Erfolg/Misserfolg) führen zu unterschiedlichen Szenen
  2. Kampagne 2 "Einbruch im Netz" ist spielbar: Incident-Response- und Forensik-Skill-Checks sind korrekt gemappt, der Security-Klassenvorteil greift
  3. Kampagnen 3, 4 und 5 sind spielbar: alle JSONs validieren ohne Fehler, alle Szenen-IDs sind auflösbar, keine toten Verzweigungen
  4. Jede Kampagne endet mit einem Simulations-Trigger der auf eine existierende PBQ-Konfiguration zeigt, und die drei Epilog-Varianten (Erfolg/Teilweise/Misserfolg) sind textlich ausformuliert
  5. Ein neuer Spieler kann Kampagne 1 von Anfang bis Ende durchspielen ohne auf einen 404-Fehler, eine leere Szene oder eine fehlende Frage zu treffen
**Plans**: TBD

</details>

### 🚧 v4.1 RAG Stufe 2 (In Progress)

**Milestone Goal:** VirtuProf beantwortet Fragen basierend auf echtem Kursmaterial (PDF/Markdown) -- nicht nur Pool-Fragen. Dokument-Upload, Text-Extraktion, Chunking-Pipeline, Keyword-Suche und Multi-Source-RAG mit Quellenangaben.

- [x] **Phase 36: Dokument-Upload + Extraktion** - Dozent laedt PDF/Markdown hoch, System extrahiert Text, Status-Uebersicht (completed 2026-03-22)
- [x] **Phase 37: Chunking-Pipeline** - Text wird in ~500-Token-Chunks mit Kapitel-Tags zerlegt, als BackgroundJob verarbeitet und in DB gespeichert (completed 2026-03-22)
- [ ] **Phase 38: Chunk-Suche** - Keyword-basierte Suche findet relevante Chunks zur User-Frage, sortiert nach Relevanz
- [ ] **Phase 39: Multi-Source-RAG** - RagContextService buendelt alle Quellen mit Prioritaeten, VirtuProf zeigt Quellenangaben

## Phase Details

### Phase 36: Dokument-Upload + Extraktion
**Goal**: Dozent kann Kursmaterialien (PDF/Markdown) in Nextcloud hochladen und das System extrahiert automatisch den Text -- die Rohtext-Basis fuer alle weiteren RAG-Schritte
**Depends on**: Phase 35 (v6.0 shipped)
**Requirements**: DOCS-01, DOCS-02, DOCS-03, DOCS-04
**Success Criteria** (what must be TRUE):
  1. Ein Dozent kann in den Kurs-Einstellungen einen NC-Ordner als Materialordner verknuepfen und dort PDF/Markdown-Dateien ablegen
  2. Nach dem Upload einer PDF-Datei extrahiert das System via pdftotext den Volltext und speichert ihn -- der Dozent sieht in der Materialliste den Status "Extrahiert"
  3. Nach dem Upload einer Markdown-Datei wird der Rohtext direkt uebernommen -- gleicher Status "Extrahiert" in der Materialliste
  4. Der Dozent sieht eine Materialliste pro Kurs mit Dateiname, Dateityp, Extraktions-Status (Hochgeladen / Extrahiert / Fehler) und Upload-Datum
**Plans**: 2 plans

Plans:
- [x] 36-01-PLAN.md — Backend: DB Migration, DocumentService, DocumentController, Routes
- [ ] 36-02-PLAN.md — Frontend: CourseMaterials.vue + CourseDetail Integration + Verification

### Phase 37: Chunking-Pipeline
**Goal**: Extrahierter Text wird automatisch in durchsuchbare Chunks zerlegt und in der Datenbank gespeichert -- bereit fuer die Suche, ohne den Dozenten zu blockieren
**Depends on**: Phase 36 (Extraktion muss Rohtext liefern)
**Requirements**: CHUNK-01, CHUNK-02, CHUNK-03, CHUNK-04
**Success Criteria** (what must be TRUE):
  1. Nach der Extraktion eines Dokuments startet automatisch ein BackgroundJob der den Text in ~500-Token-Chunks zerlegt -- der Dozent muss nichts manuell ausloesen
  2. Chunks die aus einem Abschnitt mit Heading stammen erhalten den Kapitel-Tag aus der naechsten uebergeordneten Ueberschrift (z.B. "Kapitel 6: Routing")
  3. In der Tabelle `learning_rag_chunks` existieren nach dem Job Eintraege mit course_id, chapter, text, source_file und created_at -- pruefbar per SQL
  4. Ein 50-seitiges PDF wird innerhalb von 60 Sekunden vollstaendig gechunkt -- der Job blockiert keine anderen NC-BackgroundJobs
**Plans**: 1 plan

Plans:
- [ ] 37-01-PLAN.md — DB Migration + ChunkingService + ChunkingJob (full pipeline)

### Phase 38: Chunk-Suche
**Goal**: Das System kann zu einer User-Frage die relevantesten Chunks finden -- die Bruecke zwischen Frage und Kursmaterial
**Depends on**: Phase 37 (Chunks muessen in der DB existieren)
**Requirements**: SEARCH-01, SEARCH-02
**Success Criteria** (what must be TRUE):
  1. Eine User-Frage "Was ist OSPF?" liefert Chunks zurueck die das Wort "OSPF" enthalten -- kein leeres Ergebnis wenn das Kursmaterial OSPF behandelt
  2. Die Ergebnisse sind nach Relevanz sortiert: Chunks mit mehr Keyword-Treffern und passendem Kapitel-Match stehen weiter oben
  3. Die Suche liefert maximal 5 Chunks zurueck um das Gemini Context-Window nicht zu sprengen
**Plans**: 1 plan

Plans:
- [ ] 38-01-PLAN.md — Keyword-based chunk search with relevance ranking

### Phase 39: Multi-Source-RAG
**Goal**: VirtuProf nutzt alle verfuegbaren Wissensquellen mit intelligenter Priorisierung und zeigt dem User woher die Antwort stammt
**Depends on**: Phase 38 (Chunk-Suche muss relevante Dokument-Chunks liefern)
**Requirements**: RAG-01, RAG-02, RAG-03, RAG-04
**Success Criteria** (what must be TRUE):
  1. VirtuProf beantwortet eine Frage zu einem Thema das nur im Kursmaterial (nicht in Pool-Fragen) vorkommt -- die Antwort ist inhaltlich korrekt basierend auf dem hochgeladenen PDF
  2. Jede VirtuProf-Antwort die auf Kursmaterial basiert enthaelt eine Quellenangabe im Format "[Quelle: Dateiname, Kap. X]" am Ende der Antwort
  3. VirtuProf bezieht User-Schwaechen und vergangene Erklaerungen ein: bei einer Frage zu einem Thema das der User wiederholt falsch beantwortet hat, referenziert die Antwort fruehere Fehlversuche
  4. Das Context-Fenster wird priorisiert gefuellt: zuerst relevante Dokument-Chunks, dann passende Pool-Fragen, dann Chat-History -- bei Ueberlauf werden niedrig-priorisierte Quellen abgeschnitten statt einen API-Fehler zu verursachen
**Plans**: TBD

## Progress

**Execution Order:**
Phases 36-39 execute sequentially: 36 → 37 → 38 → 39. Each phase depends on the previous.

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
| 17. Gemini Backend + Security | v3.2 | 2/2 | Complete | 2026-03-21 |
| 18. RAG-Context | v3.2 | 1/1 | Complete | 2026-03-21 |
| 19. Chat-UI | v3.2 | 1/1 | Complete | 2026-03-21 |
| 20. Ticket-Triage | v3.2 | 1/1 | Complete | 2026-03-21 |
| 21. Datenschutz & Compliance | v3.2 | 1/1 | Complete | 2026-03-21 |
| 22. Lernprofil | v4.0 | 1/1 | Complete | 2026-03-21 |
| 23. NC Files Integration | v4.0 | 1/1 | Complete | 2026-03-21 |
| 24. Note-Generator | v4.0 | 1/1 | Complete | 2026-03-21 |
| 25. Lernplan + Fortschritt | v4.0 | 1/1 | Complete | 2026-03-21 |
| 26. Chat-Memory | v4.0 | 1/1 | Complete | 2026-03-21 |
| 27. Auto-Trigger | v4.0 | 1/1 | Complete | 2026-03-21 |
| 28. Brettspiel-Backend | v5.0 | Complete | Complete | 2026-03-21 |
| 29. Oldschool-Menü | v5.0 | Complete | Complete | 2026-03-21 |
| 30. Lernwürfel | v5.0 | 1/1 | Complete | 2026-03-21 |
| 31. Wissensturm | v5.0 | Complete | Complete | 2026-03-21 |
| 32. Story-Engine Backend | v6.0 | Complete | Complete | 2026-03-22 |
| 33. RPG-Frontend + Tab | v6.0 | Complete | Complete | 2026-03-22 |
| 34. Charakter-System | v6.0 | Complete | Complete | 2026-03-22 |
| 35. Kampagnen-Content | v6.0 | Complete | Complete | 2026-03-22 |
| 36. Dokument-Upload + Extraktion | 2/2 | Complete    | 2026-03-22 | - |
| 37. Chunking-Pipeline | 1/1 | Complete    | 2026-03-22 | - |
| 38. Chunk-Suche | v4.1 | 0/? | Not started | - |
| 39. Multi-Source-RAG | v4.1 | 0/? | Not started | - |
