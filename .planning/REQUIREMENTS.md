# Requirements: Learning-NC v3.4.0 UX-Konsolidierung & Simulator-Upgrade

**Defined:** 2026-03-28
**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Source:** Dual-Audit (Codex + Gemini, 2026-03-28) + User Input

## v3.4.0 Requirements

### UX-Navigation

- [x] **NAV-01**: Dozent-Tabs in CourseDetail sind in logische Gruppen organisiert (Lernraum, Teilnehmer, Kommunikation, Wettbewerb)
- [x] **NAV-02**: Abenteuer ist als eigenständiger Lernmodus platziert, nicht unter Arena
- [x] **NAV-03**: Kursregeln (mode_config) steuern die sichtbaren Student-Tabs und Arena-Submodes korrekt
- [x] **NAV-04**: Oldschool-Karte in ArenaSelector führt zu einem funktionalen Screen oder ist entfernt
- [x] **NAV-05**: Dozenten sehen PersonalSettings UND AdminSettings, nicht nur AdminSettings
- [x] **NAV-06**: Zeitreise-Code ist entweder reaktiviert oder komplett entfernt (kein Dead Code)
- [x] **NAV-07**: DE/EN Label-Mix bereinigt — alle UI-Labels einheitlich deutsch via t(), neue Strings immer mit echten Umlauten (ä/ü/ö)

### Simulator-Upgrade

- [x] **SIM-01**: Jeder Simulator hat mindestens 1 geführte Praxis-Session mit realem Szenario
- [ ] **SIM-02**: Praxis-Sessions führen Schritt für Schritt durch das Szenario mit Erklärungen
- [x] **SIM-03**: Fortschritt innerhalb einer Session ist sichtbar und nachverfolgbar

### Student-Dashboard

- [ ] **DASH-01**: Student sieht einen "Heute"-Startscreen mit SmartQueue, Daily Challenge und Streak
- [ ] **DASH-02**: Globaler Feed aggregiert Ankündigungen aus allen eingeschriebenen Kursen
- [ ] **DASH-03**: Pool-Ebene (PoolList) ist direkt über Navigation erreichbar, nicht nur indirekt

### DevCloud-Integration

- [ ] **DVCL-01**: Kurs-Header enthält einen Link zum zugehörigen Talk-Raum
- [ ] **DVCL-02**: Studenten sehen Kursmaterialien (read-only) als eigenen Tab
- [ ] **DVCL-03**: Buddy-Matching zeigt wer Hilfe anbietet/sucht basierend auf Telos help_offer/help_wanted
- [ ] **DVCL-04**: Werkzeuge-Tab respektiert kursbezogene Tool-Einschränkungen wenn ein Kurs aktiv ist

### Leitner-Optimierung

- [ ] **LEIT-01**: Dozent kann pro Kurs Sprint-Intervalle aktivieren (4h/12h/1d/2d statt 1d/3d/7d/14d)

## Future Requirements

### Simulator v2+

- **SIM-F01**: Simulator-Sessions sind kursgebunden und tracken Fortschritt im Lernprofil
- **SIM-F02**: Dozent kann eigene Simulator-Szenarien erstellen

### DevCloud v2+

- **DVCL-F01**: Deck-Board-Integration für Kurs-Aufgaben
- **DVCL-F02**: Collectives-Integration für Kurs-Wikis

## Out of Scope

| Feature | Reason |
|---------|--------|
| Vue 3 Migration | Blockiert durch @nextcloud/vue 9.x — eigener Milestone |
| Kampagnen-Editor GUI | Dozenten nutzen JSON, Editor erst nach Engine bewährt |
| Skill-Map Dozenten-Vergleich | Erst nach Basis-Graph bewährt (SKILL-F01) |
| Neue Kampagnen-Inhalte | Engine-Bugs erst fixen, dann Content |
| WebSocket-basierter Chat | NC hat keinen WS-Server, Talk reicht |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| NAV-01 | Phase 96 | Complete |
| NAV-02 | Phase 96 | Complete |
| NAV-03 | Phase 96 | Complete |
| NAV-04 | Phase 96 | Complete |
| NAV-05 | Phase 97 | Complete |
| NAV-06 | Phase 97 | Complete |
| NAV-07 | Phase 97 | Complete |
| SIM-01 | Phase 98 | Complete |
| SIM-02 | Phase 98 | Pending |
| SIM-03 | Phase 98 | Complete |
| DASH-01 | Phase 99 | Pending |
| DASH-02 | Phase 99 | Pending |
| DASH-03 | Phase 99 | Pending |
| DVCL-01 | Phase 100 | Pending |
| DVCL-02 | Phase 100 | Pending |
| DVCL-03 | Phase 100 | Pending |
| DVCL-04 | Phase 100 | Pending |
| LEIT-01 | Phase 100 | Pending |

**Coverage:**
- v3.4.0 requirements: 18 total
- Mapped to phases: 18
- Unmapped: 0

---
*Requirements defined: 2026-03-28*
*Last updated: 2026-03-28 — traceability filled after roadmap creation*
