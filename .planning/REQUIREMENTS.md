# Requirements: Learning-NC v4.2.0

**Defined:** 2026-04-08
**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.

## v4.2.0 Requirements

Requirements for Lehrplan-Timeline + Admin-Werkzeuge milestone.

### Lehrplan-Timeline

- [ ] **TIMELINE-01**: Dozent kann einen Zeitplan pro Kurs definieren (Kapitel mit Start- und Ziel-Datum)
- [ ] **TIMELINE-02**: Student sieht horizontalen Zeitstrahl im Dashboard mit Kapitel-Stationen und Lernfortschritt
- [ ] **TIMELINE-03**: Timeline integriert ExamReadiness-Countdown als finale Station
- [ ] **TIMELINE-04**: Zeitplan zeigt nur aktive Kapitel (synchron mit curriculum_scopes)
- [ ] **TIMELINE-05**: Dozent kann Zeitplan bearbeiten und loeschen

### Admin-Werkzeuge

- [ ] **ADMIN-01**: Admin kann Pool als CSV oder JSON per OCC-Command exportieren (`occ learning:export-pool`)
- [ ] **ADMIN-02**: Admin kann Kurs komplett exportieren per OCC-Command (`occ learning:export-course` — Kurs + Pools + Members + Stats)
- [ ] **ADMIN-03**: Admin kann Kurs archivieren (Snapshot als JSON-Blob fuer spaetere Einsicht)
- [ ] **ADMIN-04**: Admin kann Jahrgangs-Merge durchfuehren per OCC-Command (`occ learning:merge-course` — Studenten + Lernstaende in Zielkurs uebernehmen, FSRS-Werte erhalten)
- [ ] **ADMIN-05**: Dozent sieht Batch-Export-Button im Admin-Dashboard (Kurs-Statistik als CSV)
- [ ] **ADMIN-06**: Dozent kann einzelne Kurs-Statistik als CSV herunterladen (Frontend-Button)

## Future Requirements (v4.3.0+)

### Onboarding & Content Intelligence (verschoben von v4.2.0)

- **ONBOARD-01**: 2-Ebenen Fullscreen Onboarding (Splash -> Rolle -> Tour -> Datenschutz -> Profil -> Hook)
- **CONTENT-01**: Material -> Pool Generator (3 Modi: Gemini Cloud, Lokal Ollama, Manuell CSV/JSON)
- **CONTENT-02**: NOVA Sprachausgabe (Gemini TTS + Browser SpeechSynthesis Fallback)
- **CONTENT-03**: Video/Audio -> Pool Generator (YouTube-URL, Whisper ASR)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Adminer/phpPgAdmin | IDOR-Sicherheitsrisiko, direkte DB-Exposition (NLM-Warnung) |
| Automatische Kapitel-Erkennung | Zu komplex fuer v4.2 — Dozent setzt Zeitplan manuell |
| Bidirektionaler Kurs-Merge | Nur unidirektional (Quelle -> Ziel), Rueckfuehrung zu riskant |
| Mobile Timeline Widget | Web-only fuer v4.2, Mobile erst mit Capacitor v5.0 |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| TIMELINE-01 | Phase 144 | Pending |
| TIMELINE-04 | Phase 144 | Pending |
| TIMELINE-05 | Phase 144 | Pending |
| TIMELINE-02 | Phase 145 | Pending |
| TIMELINE-03 | Phase 145 | Pending |
| ADMIN-01 | Phase 146 | Pending |
| ADMIN-02 | Phase 146 | Pending |
| ADMIN-03 | Phase 147 | Pending |
| ADMIN-04 | Phase 147 | Pending |
| ADMIN-05 | Phase 148 | Pending |
| ADMIN-06 | Phase 148 | Pending |

**Coverage:**
- v4.2.0 requirements: 11 total
- Mapped to phases: 11
- Unmapped: 0

---
*Requirements defined: 2026-04-08*
*Last updated: 2026-04-08 after initial definition*
