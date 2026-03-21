# Requirements: v3.1 UX-Konsolidierung

**Defined:** 2026-03-20
**Core Value:** Weniger Modi-Verwirrung, robustere Multiplayer-Erfahrung

## v3.1 Requirements

### Training-Merge

- [x] **TMERGE-01**: TrainingMode bietet einen Wahr/Falsch-Modus mit Swipe-Animationen (Wischen oder Buttons)
- [x] **TMERGE-02**: SwipeMode.vue wird entfernt, alle Referenzen in CourseDetail/App.vue zeigen auf TrainingMode
- [x] **TMERGE-03**: User kann zwischen Multiple-Choice und Wahr/Falsch innerhalb von TrainingMode umschalten
- [x] **TMERGE-04**: Bestehende Swipe-CSS-Animationen (Karte wegfliegen, Farbfeedback) werden in TrainingMode übernommen

### Arena-Zusammenfassung

- [x] **ARENA-01**: Duell und Gameshow erscheinen unter einem gemeinsamen Tab/Menüpunkt in CourseDetail
- [x] **ARENA-02**: Der gemeinsame Menüpunkt hat einen neuen, passenden Namen (z.B. "Arena", "Wettkampf", o.ä.)
- [x] **ARENA-03**: Innerhalb des gemeinsamen Bereichs kann der User zwischen Duell (1v1), Sprint (2-5) und Elimination (2-5) wählen
- [x] **ARENA-04**: Die Modi-Auswahl ist visuell ansprechend (Karten oder Buttons mit Icons + Kurzbeschreibung)

### Session-Robustheit

- [x] **ROBUST-01**: Spieler kann ein laufendes Duell/Gameshow jederzeit abbrechen (Button sichtbar)
- [x] **ROBUST-02**: Nach Spielende gibt es einen "Neues Spiel" und einen "Zurück" Button
- [x] **ROBUST-03**: Wenn alle Gegner disconnecten (Timeout), wird eine klare Meldung angezeigt statt Endlos-Warten
- [x] **ROBUST-04**: Wenn ein Spieler während des Spiels die Seite verlässt und zurückkommt, wird die laufende Session wiederhergestellt
- [x] **ROBUST-05**: Stale/verwaiste Sessions werden bereinigt (Status auf expired nach 5 Minuten Inaktivität aller Spieler)

## Future Requirements

- **ARENA-05**: Turnier-Bracket Visualisierung
- **ARENA-06**: Zuschauer-Modus

## Out of Scope

| Feature | Reason |
|---------|--------|
| Backend-Service Merge (DuelService + GameshowService) | Zu riskant, beide funktionieren getrennt |
| Neue Spielmodi | Eigenes Milestone |
| Sound-Effekte | Rein visuell |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| TMERGE-01 | Phase 14 | Complete |
| TMERGE-02 | Phase 14 | Complete |
| TMERGE-03 | Phase 14 | Complete |
| TMERGE-04 | Phase 14 | Complete |
| ARENA-01 | Phase 15 | Complete |
| ARENA-02 | Phase 15 | Complete |
| ARENA-03 | Phase 15 | Complete |
| ARENA-04 | Phase 15 | Complete |
| ROBUST-01 | Phase 16 | Complete |
| ROBUST-02 | Phase 16 | Complete |
| ROBUST-03 | Phase 16 | Complete |
| ROBUST-04 | Phase 16 | Complete |
| ROBUST-05 | Phase 16 | Complete |

**Coverage:**
- v3.1 requirements: 13 total
- Mapped to phases: 13
- Unmapped: 0

---
*Requirements defined: 2026-03-20*
