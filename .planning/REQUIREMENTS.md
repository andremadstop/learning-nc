# Requirements: Learning-NC v11.0 Telos-Onboarding + VirtuProf Guide

**Defined:** 2026-03-24
**Core Value:** Jeder User bekommt ein persoenliches Lernprofil das die gesamte App-Erfahrung personalisiert.

## v11.0 Requirements

### Onboarding-Interview

- [ ] **TELOS-01**: VirtuProf fuehrt beim ersten Login ein 10-Fragen-Interview (Rolle, Erfahrung, Staerken, Schwaechen, Ziel, Zeitrahmen, Lernstil, Lernzeit, Motivation, Besonderes)
- [ ] **TELOS-02**: Antworten werden als strukturiertes Mini-Telos JSON in der DB gespeichert (user_telos Tabelle)
- [ ] **TELOS-03**: Formular-Fallback wenn KI deaktiviert ist (gleiche Felder als Dropdown/Textfeld)

### Profil-Seite

- [ ] **PROF-01**: User sieht seine Profil-Seite mit Telos-Daten + automatischen Staerken/Schwaechen + Level/Streak/Badges
- [ ] **PROF-02**: Sichtbarkeits-Toggle: Profil privat / fuer Kurs sichtbar / nur fuer Dozent
- [ ] **PROF-03**: Profil editierbar (Telos-Daten aktualisieren, Bio, "Ich kann helfen bei...", "Ich suche Hilfe bei...")

### Dozenten-Sicht

- [ ] **DOZ-01**: Dozent sieht aggregiertes Klassen-Profil aus Telos-Daten (Erfahrungslevel-Verteilung, Ziel-Zertifizierungen, Durchschnitt Lernzeit/Woche)
- [ ] **DOZ-02**: Pruefungs-Countdown pro User (aus Telos target_date) im Dozenten-Cockpit

### VirtuProf Guide-Modus

- [ ] **GUIDE-01**: VirtuProf erklaert proaktiv wenn User zum ersten Mal ein Tool/Modus oeffnet (ausfuehrlich beim ersten Mal, kurz bei Wiederholung)
- [ ] **GUIDE-02**: Antwortlaengen-Steuerung: Kurze Antworten als Default (~150 Tokens), ausfuehrlich nur nach User-Eskalation ("Erklaer genauer", ~2048 Tokens)
- [ ] **GUIDE-03**: VirtuProf nutzt Telos-Daten als Kontext ("Als Quereinsteiger kennst du vielleicht...")

### API & DB

- [ ] **API-01**: REST-Endpoints: POST /api/profile/telos (speichern), GET /api/profile/telos (lesen), PUT /api/profile/telos (aktualisieren)
- [ ] **DB-01**: Migration: user_telos Tabelle (user_id, telos_json, bio, help_offer, help_wanted, visibility, onboarding_completed, created_at, updated_at)

## Future Requirements

- **FEED-01**: Kurs-Feed mit Meilensteinen und Ankuendigungen (v13.0)
- **SKILL-01**: Skill-Map als Force-directed Graph
- **SWIPE-01**: VirtuProf Swipe-Animation auf Mobile

## Out of Scope

| Feature | Reason |
|---------|--------|
| Kurs-Feed/Timeline | Eigener Milestone (v13.0) |
| Skill-Map Graph | Nach Profil steht |
| Swipe-Animation | Eigener UX-Milestone |
| Export-Prompt (Bring Your Own AI) | Nice-to-have, spaeter |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| TELOS-01 | Phase 75 | Pending |
| TELOS-02 | Phase 75 | Pending |
| TELOS-03 | Phase 76 | Pending |
| PROF-01 | Phase 77 | Pending |
| PROF-02 | Phase 77 | Pending |
| PROF-03 | Phase 77 | Pending |
| DOZ-01 | Phase 77 | Pending |
| DOZ-02 | Phase 77 | Pending |
| GUIDE-01 | Phase 78 | Pending |
| GUIDE-02 | Phase 78 | Pending |
| GUIDE-03 | Phase 78 | Pending |
| API-01 | Phase 75 | Pending |
| DB-01 | Phase 75 | Pending |

**Coverage:**
- v11.0 requirements: 13 total
- Mapped to phases: 13
- Unmapped: 0

---
*Requirements defined: 2026-03-24*
*Last updated: 2026-03-24 after roadmap creation*
