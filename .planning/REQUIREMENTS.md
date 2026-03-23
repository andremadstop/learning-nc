# Requirements: Learning-NC v6.2

**Defined:** 2026-03-22
**Core Value:** Hybrid-CI mit erweitertem Charakter-Cast — die App bekommt ein Gesicht.

## v6.2 Requirements

### Design-System

- [x] **DS-01**: CSS-Token-Layer mit Farbpaletten (Primary, Ink, Cyan, Amber, Magenta, Danger, Green) als CSS-Variablen
- [x] **DS-02**: Dark/Light Mode Tokens (Adventure=Dark, Training=Light)
- [x] **DS-03**: Motion-Utility-Layer (fade, snap-in, pulse, reduced-motion Fallbacks)
- [x] **DS-04**: Narrative-Skin "Paper & Circuits" fuer Abenteuer-Modus (Gemini-Stil auf Codex-Tokens)

### Charakter-System

- [x] **CHAR-01**: CharacterAvatar.vue Komponente (SVG-basiert, States: idle/thinking/explain/alert/celebrate, Emotionen)
- [x] **CHAR-02**: Character-Registry JSON mit allen 13 Figuren (ID, Name, Rolle, Palette, States, Silhouette)
- [x] **CHAR-03**: 7 Helden-Charaktere: NOVA (Tutor), Architekt, Security-Agentin, Sysadmin, Helpdesk-Rookie, CHRONOS, Ghostline
- [x] **CHAR-04**: 6 Workplace-Charaktere: DAU (klickt alles kaputt), Chef (Geld/KPIs), DSGVO-Beauftragte (Compliance), Uschi (keine Ahnung aber haelt Laden zusammen), Azubi (motiviert, Anfaengerfehler), Externer Berater (redet viel, macht wenig)

### Kampagnen-Integration

- [ ] **KI-01**: Kampagnen-Intro Animation pro Kampagne (CSS/SVG, <100KB, 3-5 Sekunden)
- [ ] **KI-02**: NPC-Portraits in DialoguePanel (CharacterAvatar + Sprechblase)
- [ ] **KI-03**: Workplace-Figuren als NPCs in bestehende Kampagnen einbauen (Chef in Colonial Pipeline, DSGVO in Equifax, DAU in WannaCry, Uschi in A+ "Der erste Tag", Azubi in Log4Shell, Berater in SolarWinds)
- [ ] **KI-04**: Skill-Check UI mit Charakter-Reaktion (Erfolg/Misserfolg Animation)

### UI-Komponenten

- [x] **UI-01**: CampaignCard.vue (Dark-Gradient, Charakter-Portrait, Difficulty-Badge)
- [x] **UI-02**: DialogueStage.vue (Speaker-Bar, Portrait links, Sprechfeld rechts, Emotions-Tags)
- [x] **UI-03**: ModeIdentityBanner.vue (Modus + Mentor + Ziel pro Lernmodus)

## Future Requirements

### Erweiterte Animationen (v7.0+)

- **ANIM-01**: Epochen-spezifische UI-Themes (Retro-Terminal, DOS, etc.)
- **ANIM-02**: 3-Layer Parallax fuer Kampagnen-Szenen
- **ANIM-03**: Charakter-spezifische Idle-Animationen

## Out of Scope

| Feature | Reason |
|---------|--------|
| Voice-Acting / TTS | Text-basiert bleibt |
| Canvas-Animationen | Performance, nur CSS/SVG |
| Hand-gezeichnete Illustrationen | SVG-Silhouetten reichen, wartbar |
| Lottie >20KB | Performance-Budget |
| Parallax | v7.0, zu komplex fuer v6.2 |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| DS-01 | Phase 44 | Complete |
| DS-02 | Phase 44 | Complete |
| DS-03 | Phase 44 | Complete |
| DS-04 | Phase 44 | Complete |
| CHAR-01 | Phase 45 | Complete |
| CHAR-02 | Phase 45 | Complete |
| CHAR-03 | Phase 45 | Complete |
| CHAR-04 | Phase 45 | Complete |
| KI-01 | Phase 47 | Pending |
| KI-02 | Phase 47 | Pending |
| KI-03 | Phase 47 | Pending |
| KI-04 | Phase 47 | Pending |
| UI-01 | Phase 46 | Complete |
| UI-02 | Phase 46 | Complete |
| UI-03 | Phase 46 | Complete |

**Coverage:**
- v6.2 requirements: 15 total
- Mapped to phases: 15
- Unmapped: 0

---
*Requirements defined: 2026-03-22*
*Last updated: 2026-03-22 after roadmap creation*
