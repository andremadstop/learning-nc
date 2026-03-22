# Requirements: Learning-NC v6.1

**Defined:** 2026-03-22
**Core Value:** Abenteuer-Modus wird lebendiger durch KI-Erzaehler und bekommt Kampagnen fuer alle Kursthemen.

## v6.1 Requirements

### KI-Erzaehler

- [x] **NARR-01**: narrator_mode ist global aktiv fuer alle Kampagnen (nicht nur einzelne Szenen)
- [x] **NARR-02**: Gemini generiert dynamische Entscheidungsoptionen basierend auf Szenen-Kontext statt fester JSON-Choices
- [x] **NARR-03**: Freetext-Aktionen werden von Gemini bewertet und in den Story-Verlauf integriert (Relevanz, Konsequenzen)
- [x] **NARR-04**: Gemini kann als Gegner agieren (spielt Angreifer in Security-Szenarien, reagiert auf Spieler-Verteidigung)
- [x] **NARR-05**: Gemini kann als DAU agieren (simuliert unwissenden User, Spieler muss erklaeren/helfen)

### Security-Kampagnen

- [x] **SEC-01**: Kampagne "SolarWinds — Die Supply Chain" (APT, Backdoor Detection, 5 Szenen)
- [x] **SEC-02**: Kampagne "WannaCry Weekend" (Ransomware, Patch Management, 5 Szenen)
- [x] **SEC-03**: Kampagne "Log4Shell — Der Zero Day" (Dependency Vulnerabilities, 5 Szenen)
- [x] **SEC-04**: Kampagne "Colonial Pipeline" (Critical Infrastructure, CEO-Entscheidung, 5 Szenen)
- [x] **SEC-05**: Kampagne "Equifax — Die vergessene Patch-Nacht" (Vulnerability Management, 5 Szenen)

### Kurs-Kampagnen

- [x] **KURS-01**: A+ Kampagne "Der erste Tag" (Hardware-Setup, Troubleshooting, 5 Szenen)
- [x] **KURS-02**: Linux+ Kampagne "Server Down" (Linux-Administration, Recovery, 5 Szenen)
- [x] **KURS-03**: CySA+ Kampagne "Zero Day" (Threat Analysis, Incident Response, 5 Szenen)

### AI Security Content

- [x] **AISEC-01**: Fragen-Pool "AI Security & Prompt Injection" (20+ Fragen, CompTIA-kompatibel)
- [x] **AISEC-02**: Meta-Kampagne "Der KI-Fluesterer" (Spieler repariert kompromittierten KI-Assistenten, 5 Szenen)

## Future Requirements

### Erweiterte Kampagnen (v7.0+)

- **HACK-01**: Hacker-Zeitreise "Hack Through Time" — 7 Epochen
- **HACK-02**: Epochen-spezifische UI-Themes (Retro-Terminal, DOS, etc.)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Voice-Acting / TTS | Text-basiert bleibt |
| Eigene Bild-Assets | Emoji/Icons reichen |
| Kampagnen-Editor fuer Dozenten | JSON manuell, v7.0+ |
| Hacker-Zeitreise | Eigener Milestone v7.0 |
| Vektor-Embeddings | Stufe 3 |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| NARR-01 | Phase 40 | Complete |
| NARR-02 | Phase 40 | Complete |
| NARR-03 | Phase 40 | Complete |
| NARR-04 | Phase 40 | Complete |
| NARR-05 | Phase 40 | Complete |
| SEC-01 | Phase 41 | Complete |
| SEC-02 | Phase 41 | Complete |
| SEC-03 | Phase 41 | Complete |
| SEC-04 | Phase 42 | Complete |
| SEC-05 | Phase 42 | Complete |
| KURS-01 | Phase 42 | Complete |
| KURS-02 | Phase 42 | Complete |
| KURS-03 | Phase 42 | Complete |
| AISEC-01 | Phase 43 | Complete |
| AISEC-02 | Phase 43 | Complete |

**Coverage:**
- v6.1 requirements: 15 total
- Mapped to phases: 15
- Unmapped: 0

---
*Requirements defined: 2026-03-22*
*Last updated: 2026-03-22 after roadmap creation*
