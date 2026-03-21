# Requirements: v5.0 Oldschool (Brettspiel-Modi)

**Defined:** 2026-03-21
**Core Value:** Lernen mit Brettspiel-Feeling — Strategie, Glück und Schadenfreude

## v5.0 Requirements

### Oldschool-Menü

- [x] **OLD-01**: "Oldschool" Tab in CourseDetail neben Arena
- [x] **OLD-02**: OldschoolSelector.vue mit 2 Karten (Lernwürfel, Wissensturm) — analog zu ArenaSelector

### Lernwürfel (Mensch ärgere dich nicht)

- [x] **WUERF-01**: Spielbrett mit 30 Feldern als Rundkurs (SVG), Figuren als farbige Kreise
- [x] **WUERF-02**: Würfel-Animation (CSS rotate) zeigt Zahl 1-6
- [x] **WUERF-03**: Nach Würfeln: Frage beantworten — richtig = vorwärts, falsch = stehenbleiben
- [x] **WUERF-04**: Rauswerfen: Landest du auf besetztem Feld → Gegner zurück zum Start
- [x] **WUERF-05**: Sonderfelder (★): Bonus-Würfel, Schutzfeld, Falle (1 Runde aussetzen)
- [x] **WUERF-06**: 6 gewürfelt = nochmal würfeln
- [x] **WUERF-07**: Erster bei Feld 30 gewinnt, Confetti + VirtuProf

### Wissensturm (Trivial Pursuit Kategorien)

- [x] **TURM-01**: 5 Kategorien (= 5 Pools oder Kapitel im Kurs), je eine Blockfarbe
- [x] **TURM-02**: Spieler wählt Kategorie → Frage → richtig = Block in dieser Farbe auf Turm
- [x] **TURM-03**: Falsche Antwort = oberster Block fällt (Verlust-Animation)
- [x] **TURM-04**: Steal: Richtig wenn Gegner falsch → du bekommst seinen Block
- [x] **TURM-05**: Wer zuerst alle 5 Farben hat gewinnt

### Backend

- [x] **BACK-01**: GameshowService erweitern: mode='lernwuerfel' + mode='wissensturm' Scoring-Logik
- [x] **BACK-02**: Spielfeld-State im Session-JSON: Figurpositionen, Sonderfelder, Würfel-Ergebnis
- [x] **BACK-03**: Rundenbasierte Logik: aktiver Spieler → Würfel → Frage → nächster Spieler

## Out of Scope

| Feature | Reason |
|---------|--------|
| Weitere Brettspiele | v5.1+ (Schlangen&Leitern, Quartett, Monopoly, Risiko) |
| KI-Gegner | Nur echte Spieler |
| Custom Spielbretter | Feste Layouts pro Modus |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| BACK-01 | Phase 28 | Complete |
| BACK-02 | Phase 28 | Complete |
| BACK-03 | Phase 28 | Complete |
| OLD-01 | Phase 29 | Complete |
| OLD-02 | Phase 29 | Complete |
| WUERF-01 | Phase 30 | Complete |
| WUERF-02 | Phase 30 | Complete |
| WUERF-03 | Phase 30 | Complete |
| WUERF-04 | Phase 30 | Complete |
| WUERF-05 | Phase 30 | Complete |
| WUERF-06 | Phase 30 | Complete |
| WUERF-07 | Phase 30 | Complete |
| TURM-01 | Phase 31 | Complete |
| TURM-02 | Phase 31 | Complete |
| TURM-03 | Phase 31 | Complete |
| TURM-04 | Phase 31 | Complete |
| TURM-05 | Phase 31 | Complete |

**Coverage:**
- v5.0 requirements: 17 total
- Mapped to phases: 17
- Unmapped: 0

---
*Requirements defined: 2026-03-21*
