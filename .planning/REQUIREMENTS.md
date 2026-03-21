# Requirements: v6.0 Abenteuer (Story-RPG)

**Defined:** 2026-03-21
**Core Value:** Lernen durch Abenteuer — Fachfragen werden zu Skill-Checks in einer spannenden Story

## v6.0 Requirements

### Story-Engine

- [x] **STORY-01**: StoryEngine.php Service lädt Kampagnen-JSON und verwaltet den Spielfortschritt pro User
- [x] **STORY-02**: Szenen haben narrative Texte, Entscheidungen, Skill-Checks und optionale Simulationen
- [x] **STORY-03**: Skill-Checks ziehen Fragen aus echten Pools gefiltert nach Thema (pool_filter)
- [x] **STORY-04**: Verzweigende Story-Baum: Erfolg/Teilweise/Misserfolg führen zu verschiedenen Szenen
- [x] **STORY-05**: Kampagnen-Fortschritt wird persistent gespeichert (DB oder JSON in NC Files)

### Kampagnen-Content

- [ ] **CAMP-01**: Kampagne 1 "Der große Ausfall" (Network+ Fokus) — 5 Szenen, Routing + VLAN + WLAN
- [ ] **CAMP-02**: Kampagne 2 "Einbruch im Netz" (Security+ Fokus) — 5 Szenen, Incident Response + Forensik
- [ ] **CAMP-03**: Kampagne 3 "Der neue Standort" (Mixed) — 5 Szenen, Design + Verkabelung + VPN
- [ ] **CAMP-04**: Kampagne 4 "Ransomware" (Security+) — 5 Szenen, IR + Backup + Recovery
- [ ] **CAMP-05**: Kampagne 5 "Das Erbe" (Mixed A+/Network+/Linux+) — 5 Szenen, Legacy + Migration

### Charakter-System

- [ ] **CHAR-01**: 4 spielbare Klassen (Architekt, Security, Sysadmin, Helpdesk) mit Stärken/Schwächen
- [ ] **CHAR-02**: Charakter-Wahl beeinflusst Skill-Check-Schwierigkeit (Stärke = leichtere Fragen, Schwäche = schwerer)
- [ ] **CHAR-03**: NPC-Dialoge mit Charakter-Portraits (Text-basiert, kein Voice)

### RPG-Frontend

- [x] **RPG-01**: AbenteuerMode.vue Komponente mit Szenen-Renderer (narrative Box, Entscheidungs-Karten, NPC-Dialog)
- [x] **RPG-02**: Skill-Check UI (Frage aus Pool, Ergebnis-Animation Erfolg/Misserfolg)
- [x] **RPG-03**: Kampagnen-Übersicht (Karte oder Liste aller 5 Kampagnen mit Fortschritt)
- [x] **RPG-04**: "Abenteuer" Tab in CourseDetail + Standalone
- [x] **RPG-05**: Koop-Modus: 2-4 Spieler, Abstimmung bei Entscheidungen (Mehrheit gewinnt)

### Simulation-Integration

- [ ] **SIM-01**: Jede Kampagne endet mit einer PBQ-Simulation (nutzt bestehende PbqRenderer)
- [ ] **SIM-02**: Simulations-Ergebnis beeinflusst Story-Epilog (Erfolg vs. Teilerfolg)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Eigene Bild-Assets | Platzhalter-Emojis/Icons, echte Bilder optional per Prompt |
| Voice-Acting | Text-basiert |
| Kampagnen-Editor | Dozenten erstellen Kampagnen manuell als JSON |
| Mehr als 5 Kampagnen | v6.1+ |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| STORY-01 | Phase 32 | Complete |
| STORY-02 | Phase 32 | Complete |
| STORY-03 | Phase 32 | Complete |
| STORY-04 | Phase 32 | Complete |
| STORY-05 | Phase 32 | Complete |
| RPG-01 | Phase 33 | Complete |
| RPG-02 | Phase 33 | Complete |
| RPG-03 | Phase 33 | Complete |
| RPG-04 | Phase 33 | Complete |
| RPG-05 | Phase 33 | Complete |
| CHAR-01 | Phase 34 | Pending |
| CHAR-02 | Phase 34 | Pending |
| CHAR-03 | Phase 34 | Pending |
| SIM-01 | Phase 34 | Pending |
| SIM-02 | Phase 34 | Pending |
| CAMP-01 | Phase 35 | Pending |
| CAMP-02 | Phase 35 | Pending |
| CAMP-03 | Phase 35 | Pending |
| CAMP-04 | Phase 35 | Pending |
| CAMP-05 | Phase 35 | Pending |

**Coverage:** 21/21 requirements mapped
