# Requirements: Learning-NC v7.0

**Defined:** 2026-03-23
**Core Value:** Zeitreise durch 7 IT-Security-Epochen — Geschichte erleben statt auswendig lernen.

## v7.0 Requirements

### Engine

- [ ] **ENG-01**: HackThroughTime.vue Haupt-Komponente mit Epochen-Navigation und CHRONOS-Guide
- [ ] **ENG-02**: EpochTheme-System: 7 CSS-Themes die per data-Attribut aktiviert werden (--epoch-* Tokens)
- [x] **ENG-03**: Epochen-Fortschritt wird persistent gespeichert (welche Epochen abgeschlossen, Gesamt-Score)
- [x] **ENG-04**: "Museum"-Zwischensequenzen mit historischen Fakten zwischen den Szenen
- [x] **ENG-05**: "Was haben wir daraus gelernt?" Skill-Check am Ende jeder Epoche (Pool-basiert)

### Epochen-Themes

- [ ] **THEME-01**: 1960er Phone Phreaking (gruen-auf-schwarz Terminal, Monospace, Scanline-Effekt)
- [ ] **THEME-02**: 1980er WarGames (DOS-Prompt blau/weiss, ASCII-Art Rahmen, Blinking Cursor)
- [ ] **THEME-03**: 1990er Morris Worm (Netscape-grau, Times New Roman, 3D-Buttons, Statusbar)
- [ ] **THEME-04**: 2000er SQL Injection (XP-Luna blau/gruen, Tahoma, Startmenue-Ecken)
- [ ] **THEME-05**: 2010er APT & Nation States (Dark Terminal modern, monospace, Matrix-Regen subtil)
- [ ] **THEME-06**: 2020er AI & Supply Chain (Cloud-Dashboard, Cards, Metriken, Slack-Aesthetic)
- [ ] **THEME-07**: Zukunft Quantum Threat (Hologramm-blau, transparente Panels, Glow-Effekte)

### Kampagnen-Content

- [ ] **CAMP-01**: 1960er "Blue Box" — Captain Crunch, Phone Phreaking, 2600 Hz Ton (3 Szenen)
- [ ] **CAMP-02**: 1980er "Shall We Play a Game?" — WarGames, Kevin Mitnick Social Engineering, BBS (4 Szenen)
- [ ] **CAMP-03**: 1990er "The Worm" — Morris Worm, Buffer Overflow, Finger-Daemon, Internet-Kollaps (3 Szenen)
- [ ] **CAMP-04**: 2000er "Bobby Tables" — SQL Injection, Code Red, MySpace Worm, OWASP-Gruendung (4 Szenen)
- [ ] **CAMP-05**: 2010er "The Shadow Brokers" — Stuxnet, APT1, Snowden, EternalBlue (4 Szenen)
- [ ] **CAMP-06**: 2020er "Supply Chain" — SolarWinds, Log4Shell, Prompt Injection, Deepfakes (4 Szenen)
- [ ] **CAMP-07**: Zukunft "Quantum Dawn" — Post-Quantum Crypto, QKD, Shors Algorithmus (3 Szenen)

### Charakter-System

- [x] **CHAR-01**: 4 epochen-affine Klassen: Phreaker (60er-80er), Script-Kiddie→Ethical Hacker (90er-00er), Red Teamer (10er-20er), Quantum Defender (Zukunft)
- [x] **CHAR-02**: Klassen-Staerken beeinflussen Skill-Check-Schwierigkeit in "ihrer" Epoche

## Future Requirements

### Multiplayer (v7.1+)

- **MP-01**: Koop-Zeitreise fuer 2-4 Spieler
- **MP-02**: Epochen-spezifische Rollen-Verteilung

## Out of Scope

| Feature | Reason |
|---------|--------|
| Multiplayer-Koop | v7.1+ |
| Mehr als 7 Epochen | 7 deckt die Geschichte ab |
| Pixel-Art Assets | CSS/SVG/ASCII-Art reicht |
| Voice-Acting | Text-basiert |
| Epochen-Editor | JSON manuell |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| ENG-01 | Phase 48 | Pending |
| ENG-02 | Phase 48 | Pending |
| ENG-03 | Phase 48 | Complete |
| ENG-04 | Phase 48 | Complete |
| ENG-05 | Phase 48 | Complete |
| THEME-01 | Phase 49 | Pending |
| THEME-02 | Phase 49 | Pending |
| THEME-03 | Phase 49 | Pending |
| THEME-04 | Phase 49 | Pending |
| THEME-05 | Phase 49 | Pending |
| THEME-06 | Phase 49 | Pending |
| THEME-07 | Phase 49 | Pending |
| CAMP-01 | Phase 50 | Pending |
| CAMP-02 | Phase 50 | Pending |
| CAMP-03 | Phase 50 | Pending |
| CAMP-04 | Phase 50 | Pending |
| CAMP-05 | Phase 51 | Pending |
| CAMP-06 | Phase 51 | Pending |
| CAMP-07 | Phase 51 | Pending |
| CHAR-01 | Phase 48 | Complete |
| CHAR-02 | Phase 48 | Complete |

**Coverage:**
- v7.0 requirements: 21 total
- Mapped to phases: 21
- Unmapped: 0

---
*Requirements defined: 2026-03-23*
*Last updated: 2026-03-23 after roadmap creation*
