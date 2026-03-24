# Requirements: Learning-NC

**Defined:** 2026-03-24
**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — fuer Einzellerner und Kursgruppen.

## v4.0 Requirements

### Bugfix & Release

- [x] **FIX-01**: Binary Tab Fix ist auf learning-dev deployed und funktioniert im Browser
- [x] **FIX-02**: App Store Token ist erneuert und aktueller Release ist im App Store hochgeladen

### Content-Bereinigung

- [ ] **CONT-01**: Wireshark-Anleitung ist von persoenlichen Homelab-Referenzen bereinigt (IPs, SSH-Aliases generalisiert)
- [ ] **CONT-02**: Nmap-Anleitung ist von persoenlichen Homelab-Referenzen bereinigt
- [ ] **CONT-03**: Network+ Wissensbasis, Lehrplan und Grossevents-Guide sind geprueft und bereinigt
- [ ] **CONT-04**: Alle bereinigten Guides liegen als geteilte Kopien vor (Originale im Personal Vault bleiben unveraendert)

### Content-Verteilung

- [ ] **DIST-01**: NC Shared Folder "Kurs-Materialien" existiert und ist fuer alle Kurs-User sichtbar
- [ ] **DIST-02**: Bereinigte Network+ Guides sind im Shared Folder abgelegt
- [ ] **DIST-03**: VirtuProf kann Fragen zu den Guides beantworten (RAG-Quellen registriert)

### DevCloud-Hygiene

- [ ] **HYGN-01**: Redundanzcheck aller User-Home-Ordner auf learning-dev ist durchgefuehrt und dokumentiert
- [ ] **HYGN-02**: Ueberfluessige/doppelte Ordner sind aufgeraeumt
- [ ] **HYGN-03**: OSSU Curriculum ist als Kursstruktur-Template evaluiert (Ergebnis dokumentiert, ggf. Import)

## Future Requirements

### Digitaler Klassenraum
- **CLASS-01**: Kurs-Chat (Echtzeit, pro Kurs)
- **CLASS-02**: Diskussionsforum (pro Frage/Thema, threaded)
- **CLASS-03**: Kanban-Board fuer Lernziele
- **CLASS-04**: Peer-Review (Schueler bewerten Freitext-Antworten)

### Skill-Profil
- **SKILL-01**: User-Profilseite mit Tags (Setup, Fokus, Rolle)
- **SKILL-02**: Staerken automatisch aus Lernhistorie
- **SKILL-03**: Skill-Map als Force-directed Graph

### KI Starter
- **START-01**: Cross-Platform Setup-Kit fuer Mitschueler

### Netzwerk-Tools (v7.1 deferred)
- **CALC-01**: Subnetzrechner (IP + Maske -> Netzadresse, Broadcast, Host-Range)
- **CALC-02**: 32-Bit Binaer-Display
- **CALC-03**: VLSM-Rechner
- **UEB-01**: Subnetting-Uebungen mit Validierung
- **INT-01**: Werkzeuge-Tab in App-Navigation

## Out of Scope

| Feature | Reason |
|---------|--------|
| Vue 3 Migration | Eigener Major-Milestone, nicht mit Housekeeping mischen |
| Neue Security-Kampagnen | Nach Content-Rollout als eigener Milestone |
| IPv6 Subnetting | Noch nicht pruefungsrelevant |
| Honeypot-Szenario | Noch in Ideenphase |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| FIX-01 | Phase 52 | Complete |
| FIX-02 | Phase 52 | Complete |
| CONT-01 | Phase 53 | Pending |
| CONT-02 | Phase 53 | Pending |
| CONT-03 | Phase 53 | Pending |
| CONT-04 | Phase 53 | Pending |
| DIST-01 | Phase 54 | Pending |
| DIST-02 | Phase 54 | Pending |
| DIST-03 | Phase 54 | Pending |
| HYGN-01 | Phase 55 | Pending |
| HYGN-02 | Phase 55 | Pending |
| HYGN-03 | Phase 55 | Pending |

**Coverage:**
- v4.0 requirements: 12 total
- Mapped to phases: 12
- Unmapped: 0

---
*Requirements defined: 2026-03-24*
*Last updated: 2026-03-24 after roadmap creation*
