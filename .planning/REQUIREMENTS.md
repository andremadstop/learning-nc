# Requirements: Learning-NC v2.3 PBQ OnVUE-Niveau Upgrade

**Defined:** 2026-03-16
**Core Value:** PBQ-Simulationen auf OnVUE-Niveau — interaktive CLI, SVG-Topologie, Dozenten-Tools

## v1 Requirements

### CLI State Machine

- [x] **CLI-01**: PbqCli unterstützt domain-Feld (cisco_ios/linux/windows/sql/generic) zur Bestimmung des Prompt-Schemas
- [x] **CLI-02**: Cisco IOS: `conf t` wechselt zu config-Modus, `interface X` zu config-if-Modus, `exit` zurück
- [x] **CLI-03**: Unbekannte Befehle zeigen kontextgerechte Fehlermeldung (z.B. `% Invalid command`)
- [x] **CLI-04**: `command_outputs` Dict ermöglicht konfigurierte Feedback-Texte pro Befehl
- [x] **CLI-05**: State Machine persistiert Modus zwischen Befehlen innerhalb einer Frage-Session

### SVG Topology Renderer

- [x] **SVG-01**: NetworkTopologySvg.vue rendert JSON node-link Schema ohne raw SVG / v-html
- [x] **SVG-02**: Icon-Bibliothek mit 8 Gerätetypen: router, switch, firewall, server, cloud, workstation, ap, wre
- [x] **SVG-03**: Hotspot-Koordinaten werden via getScreenCTM() nach viewBox-Skalierung korrekt berechnet
- [x] **SVG-04**: PbqPlacement kann SVG-Topologie als Hintergrund statt Bild-URL nutzen

### Inline-Dropdown

- [x] **DROP-01**: Dropdown-Picker erscheint direkt am angeklickten Node (positioniert)
- [x] **DROP-02**: scoring_mode=strict: nur exakte Gerätezuordnung wird gewertet
- [x] **DROP-03**: scoring_mode=partial: anteilige Punkte bei Teiltreffern

### Multi-Panel Layout

- [x] **PANEL-01**: multi_panel=true zeigt CLI und Topologie nebeneinander
- [x] **PANEL-02**: Responsive Fallback: untereinander auf kleinen Screens (<768px)

### PBQ Author Tool

- [x] **AUTHOR-01**: Visueller Editor zur Auswahl des PBQ-Typs und Eingabe der Config-Felder
- [x] **AUTHOR-02**: Automatische Generierung von gültigem PBQ-JSON aus Formulareingaben
- [x] **AUTHOR-03**: Live-Vorschau der resultierenden PBQ-Simulation im Editor

### Instructor Notes

- [ ] **NOTE-01**: DB-Migration fügt instructor_note (TEXT) und note_visible (BOOLEAN) zu oc_learning_questions hinzu
- [ ] **NOTE-02**: QuestionForm bietet Texteditor für instructor_note + Visibility-Toggle
- [ ] **NOTE-03**: TrainingMode, ExamMode, LeitnerMode, SmartQueue zeigen Notiz wenn note_visible=true
- [ ] **NOTE-04**: Instructor sieht eigene Notiz unabhängig von note_visible immer (im Bearbeitungsmodus)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Andere CLI-Domains als cisco_ios/linux (full support) | Phase 1 definiert Schema, volle Implementierung iterativ |
| Echtzeit-Kollaboration im Author Tool | Zu komplex für v1 |
| Import von OnVUE-Fragen | Kein öffentliches Format verfügbar |
| Mobile App für PBQ-Editor | Web-first |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| CLI-01 | Phase 1 | Complete |
| CLI-02 | Phase 1 | Complete |
| CLI-03 | Phase 1 | Complete |
| CLI-04 | Phase 1 | Complete |
| CLI-05 | Phase 1 | Complete |
| SVG-01 | Phase 2 | Complete |
| SVG-02 | Phase 2 | Complete |
| SVG-03 | Phase 2 | Complete |
| SVG-04 | Phase 2 | Complete |
| DROP-01 | Phase 3 | Complete |
| DROP-02 | Phase 3 | Complete |
| DROP-03 | Phase 3 | Complete |
| PANEL-01 | Phase 4 | Complete |
| PANEL-02 | Phase 4 | Complete |
| AUTHOR-01 | Phase 5 | Complete |
| AUTHOR-02 | Phase 5 | Complete |
| AUTHOR-03 | Phase 5 | Complete |
| NOTE-01 | Phase 6 | Pending |
| NOTE-02 | Phase 6 | Pending |
| NOTE-03 | Phase 6 | Pending |
| NOTE-04 | Phase 6 | Pending |

**Coverage:**
- v1 requirements: 21 total
- Mapped to phases: 21
- Unmapped: 0 ✓

---
*Requirements defined: 2026-03-16*
