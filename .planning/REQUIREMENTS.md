# Requirements: Learning-NC v3.6.0 "Architect's Ascent"

**Defined:** 2026-03-29
**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — fuer Einzellerner und Kursgruppen.

## v3.6.0 Requirements

### Content & Import

- [x] **IMPORT-01**: User kann 4 CompTIA-Kurse (Network+, Security+, Linux+, CySA+) via `occ learning:import-vault` als RAG-Quellen importieren
- [x] **IMPORT-02**: Admin kann Vault-Import im Dry-Run-Modus ausfuehren (Vorschau ohne DB-Schreibzugriff)
- [x] **IMPORT-03**: Privacy-info.json enthaelt 7 Datenkategorien (learning, ai, social, audit, gamification, assessment, external)
- [x] **IMPORT-04**: PWA-Anleitung (iOS + Android) ist als Materialien-Dokument in der DevCloud verfuegbar

### UX & Navigation

- [ ] **UX-01**: Dozent sieht in CourseDetail 5 Mega-Tabs statt 16 Einzel-Tabs (Lernraum, Teilnehmer, Wettbewerb, Kommunikation, Verwaltung)
- [ ] **UX-02**: Student kann VirtuProf als Fullscreen-Lernhelfer nutzen (eigener Top-Level-Tab, nicht nur Sidebar)
- [ ] **UX-03**: User kann Erklaerbot per X-Button oder Swipe-Geste schliessen (statt verschachteltes Menue)
- [x] **UX-04**: 9 neue Badges haben deutsche und englische Texte (Name + Beschreibung) in l10n

### Gamification

- [x] **BADGE-01**: DB-Migration fuegt `is_legacy` Flag hinzu, 17 alte Badges werden als Legacy markiert (nicht geloescht)
- [x] **BADGE-02**: 5 neue Badge-Trigger funktionieren (weekend, swarm, simulator, trouble_fixer, quick_thinker)

### Security & Compliance

- [ ] **SEC-01**: Sensible Telos-Felder (bio, telos_json) sind mit ICrypto verschluesselt gespeichert (nicht help_offer/help_wanted)
- [ ] **SEC-02**: Jede Schwarm-Moderations-Entscheidung (approve/reject) wird mit User-ID, Timestamp und Aktion in audit_events protokolliert

### AI & Kursende

- [ ] **AI-01**: Student erhaelt am Kursende eine KI-generierte Reflexion (Gemini) mit persoenlichem Lernverlauf und Next-Step-Empfehlung
- [ ] **AI-02**: Student kann nach Kursende einen ICS-Kalender-Feed abonnieren mit faelligen Leitner-Wiederholungen (sabre/vobject, Token-basierte URL)

## Future Requirements

### Deferred from v3.6.0

- **BADGE-03**: Verdiente Legacy-Badges in archivierter Sektion sichtbar halten
- **UX-05**: Kurs-Filter im Fullscreen-Erklaerbot (damit Antworten kurs-kontextuell bleiben)
- **AI-03**: Narrative Portfolio Prompt-Iteration (dedizierte Research-Phase fuer optimale Gemini-Prompts)

### Simulator v2+

- **SIM-F01**: Simulator-Sessions sind kursgebunden und tracken Fortschritt im Lernprofil
- **SIM-F02**: Dozent kann eigene Simulator-Szenarien erstellen

### DevCloud v2+

- **DVCL-F01**: Deck-Board-Integration fuer Kurs-Aufgaben
- **DVCL-F02**: Collectives-Integration fuer Kurs-Wikis

## Out of Scope

| Feature | Reason |
|---------|--------|
| Legacy-Badge-Anzeige | Nicht priorisiert, spaeterer Milestone |
| Vue 3 Migration | Blockiert durch @nextcloud/vue 9.x |
| WebSocket Chat | NC hat keinen WS-Server |
| Multi-Provider KI | Fokus auf Gemini-Optimierung |
| Kampagnen-Editor GUI | Engine muss sich erst bewaehren |
| Echtzeit-Duelle | Asynchrone Arena funktioniert |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| SEC-01 | Phase 110 | Pending |
| SEC-02 | Phase 110 | Pending |
| IMPORT-03 | Phase 110 | Complete |
| IMPORT-04 | Phase 110 | Complete |
| UX-04 | Phase 110 | Complete |
| BADGE-01 | Phase 111 | Complete |
| BADGE-02 | Phase 111 | Complete |
| IMPORT-01 | Phase 111 | Complete |
| IMPORT-02 | Phase 111 | Complete |
| UX-01 | Phase 112 | Pending |
| UX-02 | Phase 113 | Pending |
| UX-03 | Phase 113 | Pending |
| AI-01 | Phase 113 | Pending |
| AI-02 | Phase 113 | Pending |

**Coverage:**
- v3.6.0 requirements: 14 total
- Mapped to phases: 14
- Unmapped: 0

---
*Requirements defined: 2026-03-29*
*Last updated: 2026-03-29 after roadmap creation*
