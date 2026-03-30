# Requirements: Learning-NC

**Defined:** 2026-03-30
**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — fuer Einzellerner und Kursgruppen.

## v3.7.0 Requirements

### UX-Vereinfachung

- [ ] **UX-01**: Schüler sieht Training-Modus nicht (weder Tab noch Link) — Dozent behält Zugriff für Live-Demos
- [ ] **UX-02**: Wahr/Falsch-Fragen werden als Single-Choice mit 2 Optionen gehandhabt — kein eigener Pool-Typ `true_false` mehr
- [ ] **UX-03**: Migration: bestehende Wahr/Falsch-Pools (question_type `true_false` → `single`) per DB-Migration
- [ ] **UX-04**: Smart Queue ist prominenter Einstieg im Lernraum-Tab mit Anzeige fälliger Karten
- [ ] **UX-05**: mode_config ermöglicht Dozenten, Modi (Training, Exam etc.) pro Kurs ein-/auszuschalten

### DSGVO Help-Seite

- [ ] **DSGVO-01**: NC Help & Privacy (`/settings/help`) zeigt verlinkten DSGVO-Inhalt via `privacy` URL in config.php
- [ ] **DSGVO-02**: Datenschutzerklärung-Seite existiert und enthält die 7 Kategorien aus privacy-info.json
- [ ] **DSGVO-03**: Impressum ist über NC-Settings (legal notice) erreichbar

### Dashboard Prüfungstermin

- [ ] **DASH-01**: Dozent kann Prüfungsdatum pro Kurs setzen (neues Feld `exam_date` in course-Tabelle + API)
- [ ] **DASH-02**: NC Dashboard Widget zeigt Countdown-Tage bis Prüfungstermin für Schüler
- [ ] **DASH-03**: Widget ist hidden / zeigt Placeholder wenn kein Prüfungsdatum gesetzt

### PBQ-Simulator CLI-Feedback

- [ ] **PBQ-01**: CLI-Terminal gibt Feedback bei unbekannten Befehlen (Cisco-style: `% Unrecognized command`)
- [ ] **PBQ-02**: Korrekte Befehle werden bestätigt (Ausgabe wie im echten Terminal)
- [ ] **PBQ-03**: Unvollständige Befehle zeigen Hilfe-Hinweis (`Incomplete command`)

### Badge-Korrektur

- [ ] **BADGE-01**: streak_30 Badge durch streak_14 ersetzt (28-Tage-Kurs-kompatibel)
- [ ] **BADGE-02**: Bestehende streak_30-Einträge werden zu streak_14 migriert

## v3.8+ Requirements (deferred)

### Dashboard Zeitstrahl

- **ZEIT-01**: Dozent trägt Kurs-Themen + Zeitplan ein (course_schedule Tabelle)
- **ZEIT-02**: Dashboard zeigt Themen-Timeline als horizontalen Zeitstrahl
- **ZEIT-03**: Aktueller Stand im Zeitstrahl markiert

### Materialien

- **MAT-01**: material_folder für Security+, Linux+, CySA+, A+ verknüpfen
- **MAT-02**: Vault-Import für restliche CompTIA-Kurse

## Out of Scope

| Feature | Reason |
|---------|--------|
| Zeitstrahl-Backend (course_schedule) | Eigener Milestone (v3.8+) |
| Vue 3 Migration | Blockiert durch @nextcloud/vue 9.x |
| Bot-Gegner Server-Side | Niedrige Prio, Klaus nur client-side |
| FSRS statt Leitner | Eigener Milestone (v3.8+) |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| UX-01 | Phase 114 | Pending |
| UX-04 | Phase 114 | Pending |
| UX-05 | Phase 114 | Pending |
| UX-02 | Phase 115 | Pending |
| UX-03 | Phase 115 | Pending |
| DSGVO-01 | Phase 116 | Pending |
| DSGVO-02 | Phase 116 | Pending |
| DSGVO-03 | Phase 116 | Pending |
| DASH-01 | Phase 117 | Pending |
| DASH-02 | Phase 117 | Pending |
| DASH-03 | Phase 117 | Pending |
| PBQ-01 | Phase 118 | Pending |
| PBQ-02 | Phase 118 | Pending |
| PBQ-03 | Phase 118 | Pending |
| BADGE-01 | Phase 118 | Pending |
| BADGE-02 | Phase 118 | Pending |

**Coverage:**
- v3.7.0 requirements: 16 total
- Mapped to phases: 16
- Unmapped: 0 ✓

---
*Requirements defined: 2026-03-30*
*Last updated: 2026-03-30 — traceability mapped after roadmap creation*
