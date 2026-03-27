# Requirements: Learning-NC v13.0 Feature Expansion

**Defined:** 2026-03-27
**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.

## v13.0 Requirements

### NOVA Visual Redesign

- [ ] **NOVA-01**: VirtuProf zeigt animierte Idle/Thinking/Speaking-States (CSS/SVG)
- [ ] **NOVA-02**: Bot reagiert visuell auf User-Aktionen (Reaktions-Logik: Lob, Hinweis, Fehler)
- [x] **NOVA-03**: Character Bible definiert NOVAs Persoenlichkeit konsistent ueber alle Kontexte
- [ ] **NOVA-04**: Sound-Feedback bei Bot-Interaktionen (optional, User-Toggle)
- [ ] **NOVA-05**: Vue-Komponenten fuer Bubble, Dock, Overlay nach Component Specs

### Ghostline Quest

- [ ] **GHOST-01**: Network+ Kampagne mit mindestens 5 Szenen und Terminal-Puzzles
- [ ] **GHOST-02**: Story-Arc mit Protagonist, Antagonist und Wendepunkt
- [ ] **GHOST-03**: NPC-Dialoge mit verzweigten Optionen
- [ ] **GHOST-04**: Simulator-Integration (DNS, Firewall, Routing) in Quest-Szenen

### Vue 3 Migration

- [ ] **VUE3-01**: Kompatibilitaetsanalyse aller Vue 2 Komponenten und Plugins
- [ ] **VUE3-02**: Migrationspfad-Dokument mit konkreten Schritten
- [ ] **VUE3-03**: Risikobewertung und Aufwandsschaetzung (T-Shirt Sizes)

### Kurs-Feed

- [ ] **FEED-01**: Dozent kann Ankuendigungen fuer einen Kurs posten
- [ ] **FEED-02**: Studenten sehen Activity Stream mit Ankuendigungen und Meilensteinen
- [ ] **FEED-03**: Feed zeigt automatisch neue Lerninhalte und Kurs-Fortschritt

### Skill-Map

- [ ] **SKILL-01**: D3.js Force-directed Graph zeigt Kompetenz-Cluster
- [ ] **SKILL-02**: Nodes faerben sich nach Lernfortschritt (rot→gelb→gruen)
- [ ] **SKILL-03**: User kann in Node klicken um zugehoerige Karten zu sehen

## Future Requirements

### NOVA v2+

- **NOVA-F01**: Animierte Uebergaenge zwischen NOVA-Emotionen (Morphing)
- **NOVA-F02**: User-anpassbare NOVA-Skins/Themes

### Skill-Map v2+

- **SKILL-F01**: Skill-Map vergleich zwischen Usern (Dozenten-View)
- **SKILL-F02**: Empfehlungs-Engine basierend auf Skill-Gaps

## Out of Scope

| Feature | Reason |
|---------|--------|
| Vue 3 Umsetzung | Nur Evaluierung in v13.0, Migration ist eigener Milestone |
| Kampagnen-Editor GUI | Dozenten nutzen JSON, Editor erst nach Engine bewaehrt |
| Voice-Chat im Coop | Zu komplex, Text-Chat reicht |
| NOVA 3D-Avatar | Performance auf Tablets, CSS/SVG reicht |
| Skill-Map Dozenten-Vergleich | Erst nach Basis-Graph steht |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| NOVA-01 | Phase 91 | Pending |
| NOVA-02 | Phase 91 | Pending |
| NOVA-03 | Phase 90 | Complete |
| NOVA-04 | Phase 91 | Pending |
| NOVA-05 | Phase 91 | Pending |
| GHOST-01 | Phase 92 | Pending |
| GHOST-02 | Phase 92 | Pending |
| GHOST-03 | Phase 92 | Pending |
| GHOST-04 | Phase 92 | Pending |
| VUE3-01 | Phase 93 | Pending |
| VUE3-02 | Phase 93 | Pending |
| VUE3-03 | Phase 93 | Pending |
| FEED-01 | Phase 94 | Pending |
| FEED-02 | Phase 94 | Pending |
| FEED-03 | Phase 94 | Pending |
| SKILL-01 | Phase 95 | Pending |
| SKILL-02 | Phase 95 | Pending |
| SKILL-03 | Phase 95 | Pending |

**Coverage:**
- v13.0 requirements: 18 total
- Mapped to phases: 18
- Unmapped: 0

---
*Requirements defined: 2026-03-27*
*Last updated: 2026-03-27 after roadmap creation*
