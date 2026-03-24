# Requirements: Learning-NC v10.0 Campaign Engine v2

**Defined:** 2026-03-24
**Core Value:** Kampagnen werden zu echten 60-120 min RPG-Erlebnissen mit Pfadbaum, State-Machine und eingebetteten Simulatoren.

## v10.0 Requirements

### State-Machine & Graph

- [x] **ENG-01**: Kampagnen nutzen einen gerichteten Szenen-Graph statt linearer Kette (30-50 Knoten pro Kampagne)
- [x] **ENG-02**: State-Bag speichert Flags, Items und Reputation die sich ueber die Kampagne akkumulieren und spaetere Szenen beeinflussen
- [x] **ENG-03**: Szenen koennen Bedingungen haben (z.B. "nur wenn Flag X gesetzt" oder "nur wenn Reputation > 5")

### Akte & Dauer

- [ ] **ENG-04**: Kampagnen sind in 3-4 Akte strukturiert (Setup → Investigation → Eskalation → Showdown) mit je 5-15 Szenen
- [ ] **ENG-05**: Save/Resume: Spielstand persistiert, Session kann ueber Tage unterbrochen und fortgesetzt werden

### Rollen-System

- [ ] **ROLE-01**: Charakterklasse beeinflusst verfuegbare Szenen und Pfade (nicht nur Difficulty-Modifier)
- [ ] **ROLE-02**: Exklusive Szenen pro Rolle (Architect sieht Netzwerk-Szenen, Security sieht Forensik-Szenen)

### Aufgaben-Integration

- [ ] **TASK-01**: Szenen koennen eingebettete Simulatoren als Aufgaben referenzieren (DNS, Firewall, Port-Scanner etc.)
- [ ] **TASK-02**: Simulator-Ergebnis (bestanden/nicht bestanden) beeinflusst Szenen-Fortschritt und State-Bag
- [ ] **TASK-03**: Timer-basierte Aufgaben moeglich (z.B. "Finde den kompromittierten Host in 5 Minuten")

### KI-Gegner (DAU-Bot)

- [ ] **BOT-01**: KI-Gegner der typische Anfaengerfehler macht (Default-Passwoerter, offene Ports, keine Backups)
- [ ] **BOT-02**: User muss die Fehler des Bots korrigieren als Teil des Szenarios

### API & DB

- [ ] **API-01**: REST-Endpoints fuer Graph-Traversal, State-Management, Save/Resume
- [x] **DB-01**: Migration: campaign_state Tabelle mit state_bag (JSON), act_number, graph_position, timestamps

## Future Requirements

- **MP-01**: Multiplayer Live-Sessions mit Rollenverteilung (2-4 Spieler)
- **MP-02**: Competitive-Modus (Wer loest das Szenario schneller/besser?)
- **FE-01**: Frontend-Redesign des Abenteuer-Modus fuer Graph-Navigation
- **CONT-01**: 3+ grosse Kampagnen (je 30-50 Knoten, 60-120 min)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Frontend-Redesign | Eigener Milestone nach Engine-Stabilisierung |
| Multiplayer Live | Nach Engine steht, eigener Milestone |
| Neuer Kampagnen-Content | Gemini liefert separat |
| Zeitreise-Ueberarbeitung | Baut auf Engine v2 auf, eigener Milestone |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| ENG-01 | Phase 71 | Complete |
| ENG-02 | Phase 71 | Complete |
| ENG-03 | Phase 71 | Complete |
| ENG-04 | Phase 72 | Pending |
| ENG-05 | Phase 72 | Pending |
| ROLE-01 | Phase 73 | Pending |
| ROLE-02 | Phase 73 | Pending |
| TASK-01 | Phase 73 | Pending |
| TASK-02 | Phase 73 | Pending |
| TASK-03 | Phase 73 | Pending |
| BOT-01 | Phase 74 | Pending |
| BOT-02 | Phase 74 | Pending |
| API-01 | Phase 72 | Pending |
| DB-01 | Phase 71 | Complete |

**Coverage:**
- v10.0 requirements: 14 total
- Mapped to phases: 14
- Unmapped: 0

---
*Requirements defined: 2026-03-24*
*Last updated: 2026-03-24 after roadmap creation*
