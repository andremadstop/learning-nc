# Requirements: v4.0 Persönlicher Lernbot

**Defined:** 2026-03-21
**Core Value:** Jeder Schüler bekommt einen KI-Begleiter der mitlernt und individuelle Lernmaterialien erstellt

## v4.0 Requirements

### Lernprofil

- [x] **PROF-01**: LernprofilService aggregiert Stärken/Schwächen pro User aus Leitner-Boxen, Training-Scores und Exam-Ergebnissen
- [x] **PROF-02**: Profil identifiziert die 5 schwächsten Themen/Kapitel des Users
- [x] **PROF-03**: Profil trackt Lernhistorie (wann, was, wie lange, Trend)
- [x] **PROF-04**: Profil wird bei jeder Lernsession automatisch aktualisiert

### NC Files Integration

- [x] **FILES-01**: App erstellt einen /Learning/ Ordner im User-Home beim ersten Zugriff
- [x] **FILES-02**: App kann Markdown-Dateien in /Learning/ erstellen und aktualisieren
- [x] **FILES-03**: Ordnerstruktur: /Learning/Zusammenfassungen/, /Learning/Schwachstellen/, /Learning/Lernplan.md
- [x] **FILES-04**: Dateien haben YAML Frontmatter (created, source, topic, status, chapter)
- [x] **FILES-05**: Dateien nutzen Wiki-Links ([[...]]) und Tags (#schwach, #gemeistert) für Obsidian-Kompatibilität

### Note-Generator

- [x] **NOTE-01**: Bei Trigger generiert Gemini eine Zusammenfassung für ein schwaches Thema
- [x] **NOTE-02**: Zusammenfassung enthält: Kernpunkte, häufigster Fehler des Users, Übungsempfehlung
- [x] **NOTE-03**: Zusammenfassung verlinkt auf relevante Simulationen/Fragen via Wiki-Links
- [x] **NOTE-04**: Bestehende Notes werden aktualisiert statt dupliziert (Dateiname = Thema)

### Lernplan

- [x] **PLAN-01**: Bot generiert wöchentlichen Lernplan basierend auf Profil-Schwächen
- [x] **PLAN-02**: Lernplan als Markdown mit Checkliste (- [ ] Montag: VLAN-Kapitel wiederholen)
- [x] **PLAN-03**: Lernplan wird in /Learning/Lernplan.md geschrieben (überschreibt vorherigen)
- [x] **PLAN-04**: Fortschritts-Dashboard als /Learning/Fortschritt.md (Box-Stats, Trend, Empfehlungen)

### Chat-Memory

- [x] **MEM-01**: Chat-Kontext wird über Sessions hinweg gespeichert (DB-backed, pro User)
- [x] **MEM-02**: Bot erinnert sich an vergangene Fragen und Erklärungen
- [x] **MEM-03**: Max 50 Kontext-Einträge pro User (älteste werden zusammengefasst)
- [x] **MEM-04**: User kann Chat-History löschen (Datenschutz)

### Auto-Trigger

- [x] **TRIG-01**: Nach einem Exam mit <70% wird automatisch eine Schwachstellen-Note generiert
- [x] **TRIG-02**: Nach 5 falschen Antworten zum gleichen Thema wird eine Zusammenfassung angeboten
- [x] **TRIG-03**: Wöchentlich (Sonntag) wird der Lernplan aktualisiert (NC BackgroundJob)
- [x] **TRIG-04**: User kann manuell "Zusammenfassung erstellen" für jedes Kapitel anfordern

## Future Requirements

- **FUTURE-01**: Obsidian-Plugin für bidirektionale Sync (Notes → Lernfortschritt)
- **FUTURE-02**: Sprachnotizen (Whisper STT → Note)
- **FUTURE-03**: Lerngruppen-Notes (gemeinsame Zusammenfassungen)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Eigenes Obsidian-Plugin | NC Sync reicht, zu komplex |
| Voice-Interaktion | Browser STT unzuverlässig |
| Automatische Kurs-Erstellung | Dozenten-Aufgabe, nicht Bot |
| Fine-Tuning | Gemini + RAG reicht |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| PROF-01 | Phase 22 | Complete |
| PROF-02 | Phase 22 | Complete |
| PROF-03 | Phase 22 | Complete |
| PROF-04 | Phase 22 | Complete |
| FILES-01 | Phase 23 | Complete |
| FILES-02 | Phase 23 | Complete |
| FILES-03 | Phase 23 | Complete |
| FILES-04 | Phase 23 | Complete |
| FILES-05 | Phase 23 | Complete |
| NOTE-01 | Phase 24 | Complete |
| NOTE-02 | Phase 24 | Complete |
| NOTE-03 | Phase 24 | Complete |
| NOTE-04 | Phase 24 | Complete |
| PLAN-01 | Phase 25 | Complete |
| PLAN-02 | Phase 25 | Complete |
| PLAN-03 | Phase 25 | Complete |
| PLAN-04 | Phase 25 | Complete |
| MEM-01 | Phase 26 | Complete |
| MEM-02 | Phase 26 | Complete |
| MEM-03 | Phase 26 | Complete |
| MEM-04 | Phase 26 | Complete |
| TRIG-01 | Phase 27 | Complete |
| TRIG-02 | Phase 27 | Complete |
| TRIG-03 | Phase 27 | Complete |
| TRIG-04 | Phase 27 | Complete |

**Coverage:**
- v4.0 requirements: 22 total
- Mapped to phases: 22
- Unmapped: 0

---
*Requirements defined: 2026-03-21*
*Traceability updated: 2026-03-21 (roadmap created)*
