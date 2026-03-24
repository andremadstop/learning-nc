# Requirements: Learning-NC v8.0 VirtuProf v2

**Defined:** 2026-03-24
**Core Value:** VirtuProf wird kontextbewusst — der Bot weiss welche Frage der User sieht, gibt gestufte Hints und ist im Pruefungsmodus gesperrt.

## v8.0 Requirements

### Kontext-Mapping

- [x] **CTX-01**: Frontend sendet die aktuell angezeigte Frage (Text + Optionen + korrekte Antwort + Erklaerung) als Kontext an die Chat-API
- [x] **CTX-02**: GeminiService baut den Fragen-Kontext in den System-Prompt ein sodass der Bot weiss welche Frage der User gerade sieht
- [ ] **CTX-03**: Bot kann auf "Erklaer mir diese Frage" oder "Warum ist B richtig?" direkt antworten ohne dass der User die Frage kopieren muss

### Hint-System

- [ ] **HINT-01**: User kann "Tipp" oder "Hint" schreiben und bekommt eine gestufte Hilfe (Richtung → konkreter → fast die Antwort) statt sofort die Loesung
- [ ] **HINT-02**: Der Hint-Level wird pro Frage getrackt (1→2→3), bei neuer Frage reset
- [ ] **HINT-03**: Nach Hint 3 bietet der Bot an die vollstaendige Erklaerung zu zeigen

### Pruefungsmodus-Sperre

- [ ] **EXAM-01**: Im Exam-Mode ist die VirtuProf-Bubble ausgeblendet oder der Chat-Input deaktiviert — keine KI-Hilfe waehrend der Pruefung
- [ ] **EXAM-02**: Der Sperr-Zustand wird vom ExamMode-Kontext gesteuert, nicht manuell

### Fehler-Report

- [ ] **REP-01**: User kann per Button oder Kommando ("Fehler melden") ein Problem mit der aktuellen Frage melden (falsche Antwort, schlechte Uebersetzung, unklare Formulierung)
- [ ] **REP-02**: Der Report enthaelt automatisch die Fragen-ID, den aktuellen Modus und optional User-Kommentar

## Future Requirements

- **FUT-01**: QA-Scanner — Bot prueft automatisch alle Fragen auf Konsistenz
- **FUT-02**: Proaktive Hinweise ("Dieses Thema kommt in 3 weiteren Fragen vor")
- **FUT-03**: Lernstatistik-Integration ("Du hast bei diesem Thema 40% falsch")

## Out of Scope

| Feature | Reason |
|---------|--------|
| Multi-Bot-Swarm | Zu teuer (API-Kosten), ein schlauer Bot reicht |
| Eigener LLM statt Gemini | Infra-Aufwand, Gemini funktioniert |
| Kontext fuer Subnetzrechner | Subnetzrechner hat eigenen Erklaer-Modus |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| CTX-01 | Phase 61 | Complete |
| CTX-02 | Phase 61 | Complete |
| CTX-03 | Phase 61 | Pending |
| HINT-01 | Phase 62 | Pending |
| HINT-02 | Phase 62 | Pending |
| HINT-03 | Phase 62 | Pending |
| EXAM-01 | Phase 63 | Pending |
| EXAM-02 | Phase 63 | Pending |
| REP-01 | Phase 63 | Pending |
| REP-02 | Phase 63 | Pending |

**Coverage:**
- v8.0 requirements: 10 total
- Mapped to phases: 10
- Unmapped: 0

---
*Requirements defined: 2026-03-24*
*Last updated: 2026-03-24 after roadmap creation*
