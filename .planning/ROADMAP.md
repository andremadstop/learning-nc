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
- 🚧 **v12.1 DevCloud Optimierung** — Phases 86-89 (in progress)

## Phases

<details>
<summary>✅ v2.3 — v12.0 (Phases 1-85) — SHIPPED</summary>

Phases 1-85 shipped across milestones v2.3 through v12.0. See git history for details.

</details>

### 🚧 v12.1 DevCloud Optimierung (Phases 86-89)

**Milestone Goal:** DevCloud-Infrastruktur automatisieren, strategisch verknuepfen und fuer Studenten + AI-Agents optimieren.

- [x] **Phase 86: Pipeline Tooling** - Sanitize-Script, /lerninhalt Skill-Pipeline und Staleness-Check (completed 2026-03-27)
- [ ] **Phase 87: NC Platform Setup** - Talk-Willkommen, Dashboard-Einstieg
- [ ] **Phase 88: VirtuProf Manifest** - Manifest-Lesen, Niveau-Differenzierung
- [ ] **Phase 89: Cross-App Linking** - Content-Changelog in Talk, Deck Deep-Links

## Phase Details

### Phase 86: Pipeline Tooling
**Goal**: Lerninhalte koennen in einem automatisierten Schritt erstellt, bereinigt und in die DevCloud kopiert werden — mit Warnungen wenn Inhalte veraltet sind
**Depends on**: Nothing (first phase of v12.1)
**Requirements**: PIPE-01, PIPE-02, PIPE-03
**Success Criteria** (what must be TRUE):
  1. `scripts/devcloud-sanitize.py` existiert, ist ausfuehrbar und bereinigt Markdown-Dateien von persoenlichen Referenzen — `--dry-run` zeigt was geaendert wuerde ohne zu schreiben, `--track` aktualisiert ein Manifest
  2. Der `/lerninhalt` Skill fuehrt nach Inhaltserstellung automatisch Sanitize + Copy nach `_devcloud/` aus — ein neuer Lerninhalt landet mit einem Befehl bereinigt in der DevCloud
  3. Der Staleness-Check erkennt wenn Personal-Vault-Dateien neuer als ihre `_devcloud/`-Kopien sind und gibt eine sichtbare Warnung aus — veraltete DevCloud-Inhalte fallen sofort auf
**Plans**: 2 plans

Plans:
- [ ] 86-01-PLAN.md — devcloud-sanitize.py CLI mit --dry-run und --track
- [ ] 86-02-PLAN.md — /lerninhalt Skill-Pipeline (Sanitize + Copy) und Staleness-Check

### Phase 87: NC Platform Setup
**Goal**: Studenten finden nach dem Login sofort den Einstieg zur DevCloud und werden in Talk-Raeumen mit klaren Regeln und Zweck empfangen
**Depends on**: Nothing (parallel zu Phase 86 moeglich)
**Requirements**: TALK-01, DASH-01, DASH-02
**Success Criteria** (what must be TRUE):
  1. Alle 5 Talk-Raeume haben eine gepinnte Willkommensnachricht die Zweck und Regeln des Raums erklaert — ein neuer Student versteht sofort wofuer jeder Raum da ist
  2. Das NC-Dashboard zeigt nach Login einen Einstiegslink zum Dozenten-Material und zur Learning App — der Student muss nicht manuell nach der App suchen
  3. `00-Uebersicht.md` ist als Startpunkt erreichbar (Dashboard-Widget oder Default-Files-Redirect) — der Student landet beim ersten Klick auf einer orientierten Uebersichtsseite
**Plans**: TBD

Plans:
- [ ] 87-01: Talk-Willkommensnachrichten (5 Raeume, gepinnt)
- [ ] 87-02: Dashboard-Konfiguration (Einstiegslinks + Uebersicht-Redirect)

### Phase 88: VirtuProf Manifest
**Goal**: VirtuProf kennt die DevCloud-Inhalte via Manifest und empfiehlt passende Lerninhalte abgestimmt auf das Niveau des Users
**Depends on**: Phase 86 (Manifest muss durch Pipeline befuellt sein)
**Requirements**: MNFT-01, MNFT-02
**Success Criteria** (what must be TRUE):
  1. VirtuProf liest `_manifest.json` und kann bei Themen-Fragen passende DevCloud-Lerninhalte verlinken — der Student fragt z.B. "Was ist Subnetting?" und bekommt einen Link zum Subnetting-Guide in der DevCloud
  2. Die Empfehlungen unterscheiden zwischen Einsteiger- und Profi-Niveau basierend auf User-Kontext (Telos-Daten, Lernhistorie) — ein Anfaenger bekommt den Grundlagen-Guide, ein Fortgeschrittener das Profi-Material
  3. Bei Themen ohne passendes DevCloud-Material antwortet VirtuProf normal ohne kaputte Links — kein Verweis auf nicht-existierende Inhalte
**Plans**: TBD

Plans:
- [ ] 88-01: Manifest-Loader fuer VirtuProf (GeminiService liest _manifest.json)
- [ ] 88-02: Niveau-Differenzierung (Einsteiger/Profi basierend auf User-Kontext)

### Phase 89: Cross-App Linking
**Goal**: Neue Lerninhalte werden automatisch im Talk angekuendigt und Deck-Karten verlinken direkt auf Lerneinheiten in der Learning App
**Depends on**: Phase 86 (Pipeline muss Content-Updates erkennen), Phase 87 (Talk-Raeume muessen eingerichtet sein)
**Requirements**: TALK-02, DECK-01, DECK-02
**Success Criteria** (what must be TRUE):
  1. Bei neuen oder aktualisierten Lerninhalten wird automatisch ein Post im "Allgemein"-Raum erstellt — Studenten sehen im Talk was sich geaendert hat, ohne manuell zu pruefen
  2. Deck-Karten koennen via Beschreibungsfeld Deep-Links auf Lerneinheiten in der Learning App enthalten — ein Klick auf den Link oeffnet die entsprechende Lerneinheit
  3. Beispiel-Karten im Kurs-Kanban enthalten funktionierende Links zu Learning-App-Pools — neue Studenten sehen sofort wie Deck und Learning App zusammenspielen
**Plans**: TBD

Plans:
- [ ] 89-01: Content-Changelog Talk-Bot (automatische Posts bei Updates)
- [ ] 89-02: Deck Deep-Links (Link-Schema + Beispiel-Karten)

## Progress

**Execution Order:**
Phases 86-87 can run in parallel. Phase 88 after 86. Phase 89 after 86+87.

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 86. Pipeline Tooling | 2/2 | Complete   | 2026-03-27 | - |
| 87. NC Platform Setup | v12.1 | 0/2 | Not started | - |
| 88. VirtuProf Manifest | v12.1 | 0/2 | Not started | - |
| 89. Cross-App Linking | v12.1 | 0/2 | Not started | - |
