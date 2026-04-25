# Requirements: Learning-NC v4.4.0 Character & Personality

**Defined:** 2026-04-18
**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — fuer Einzellerner und Kursgruppen.

**Milestone-Ziel:** VirtuProf bekommt ein Gesicht. Weg von der futuristischen Box (NovaDock) zurück zu figürlicher Tiefe und Persönlichkeit. User können das Erscheinungsbild anpassen inkl. Archetype-Presets (Theoretiker / Kosmologe / Astrophysik-Popularisierer) und der wiederbelebten Prof. Lern Classic.

**Product Decisions:**
- **Naming:** Archetype-Naming (keine realen Namen) — App-Store-sicher, kein Lizenz-Risiko
- **Sensitivity Review:** Ja, externe Review vor Phase 4 eingeplant (~€300)
- **Migration:** Zero-Change-Default für bestehende User (NOVA bleibt), neue User bekommen Prof. Lern Classic

## v4.4.0 Requirements

### Skin-Picker Framework (PICK)

Grundlage für alles — ohne Picker keine User-Customization.

- [ ] **PICK-01**: User kann VirtuProf-Skin in PersonalSettings über Dropdown auswählen (Nova / Prof. Lern Classic / 3 Archetypen)
- [ ] **PICK-02**: Skin-Auswahl wird pro User in NC user_config persistiert (`learning.virtuprof_skin`)
- [ ] **PICK-03**: VirtuProf.vue rendert conditional den richtigen Avatar (NovaDock / ProfLernAvatar / CharacterAvatar) via SkinRenderer-Dispatcher
- [ ] **PICK-04**: Skin-Wechsel wirkt reactive ohne Page-Reload (Pinia Store + `:key`-Remount)
- [ ] **PICK-05**: Fallback auf Nova bei ungültiger/entfernter skinId (graceful degradation)

### Prof. Lern Classic (CLASSIC)

Die "kleine Figur vom Anfang" zurückbringen.

- [ ] **CLASSIC-01**: `VirtuProfAvatar.vue` aus Git-History v2.6.1 restauriert und auf Vue 3 Composition API migriert
- [ ] **CLASSIC-02**: Prof. Lern Classic zeigt charakteristische Features: liest hinter Buch, Blick-Folge-Effekt (hover gaze), Arm-Wave bei Klick (auto-hides after 1.2s), Fragezeichen auf Körper
- [ ] **CLASSIC-03**: Als Skin-Option `prof_lern_classic` im Picker verfügbar
- [ ] **CLASSIC-04**: Default-Skin für neu registrierte User (nach v4.4.0-Deploy)

### Scholar Archetypes (SCHOLAR)

Drei Archetyp-Figuren inspired by Scientist, ohne reale Namen.

- [x] **SCHOLAR-01**: "Der Theoretiker" — stylized Archetyp mit wildem Haar, Schnurrbart, Cardigan-Palette (Inspiration Einstein-esk, aber anonymisiert)
- [x] **SCHOLAR-02**: "Der Kosmologe" — stylized Archetyp mit Brille + Rollstuhl-Andeutung, Blau-Palette (Inspiration Hawking-esk, respektvoll gestaltet nach Sensitivity-Review)
- [x] **SCHOLAR-03**: "Der Astrophysik-Popularisierer" — stylized Archetyp mit Kinnbart, Weste, Sternen-Glow, Magenta-Violett (Inspiration Tyson-esk, anonymisiert)
- [x] **SCHOLAR-04**: Alle 3 Archetypen als Einträge in `characters.js` mit vollständigem Meta-Schema (palette, silhouette, states, personality) — completed 2026-04-25 (commit `ab26155`, Plan 152-02)

### Meta-Schema Extension (META)

characters.js erweitern, non-breaking.

- [ ] **META-01**: `characters.js` erweitert um 3 neue Felder: `user_selectable` (Boolean), `category` (String: `'hero'|'scholar'|'classic'|'workplace'`), `preview_thumbnail_svg` (String, inline mini-SVG)
- [ ] **META-02**: Additiver Default-Mechanismus: bestehende 12 Charaktere laufen unverändert (Defaults für neue Felder)
- [ ] **META-03**: SkinPicker filtert Charaktere nach `user_selectable === true`

### Animation Engine (ANIM)

Shared Animation-Primitives für alle Skins.

- [ ] **ANIM-01**: `character-animations.css` enthält `@keyframes` für Idle-Loops (blink, slight sway) — alle gated in `@media (prefers-reduced-motion: no-preference)`
- [ ] **ANIM-02**: `character-animations.js` exportiert WAAPI-Helpers für Event-Triggered Animationen (wave, celebrate, shrug) — alle mit `matchMedia('(prefers-reduced-motion: reduce)')`-Guard
- [ ] **ANIM-03**: `character-reaction-engine.js` generalisiert aus `nova-reaction-engine.js`, mapped Events (answer-correct, answer-wrong, chat-message, badge-earned) auf `{animation, emotion, sound, duration}` mit graceful Fallback wenn Skin State nicht supportet
- [ ] **ANIM-04**: CharacterAvatar.vue SVG in named `<g id="head">`, `<g id="arms">`, `<g id="body">` sub-groups gewrappt mit `transform-box: fill-box` (Safari pre-16 Fix)
- [x] **ANIM-05**: Jeder Archetype + Prof. Lern Classic unterstützt mindestens 3 Animationen (idle/blink, wave, celebrate) — completed 2026-04-25 (commit `0b9f13c`, Plan 152-06; 23 GREEN cases in scholarAnimations.test.js)

### Internationalization (I18N)

5 Sprachen parallel.

- [ ] **I18N-01**: Alle neuen UI-Strings (Picker-Label, Skin-Namen, Kategorien, Beschreibungen) in allen 5 Sprachen (DE/EN/FR/RU/AR) mit Release gelandet
- [ ] **I18N-02**: CI key-parity-check (existiert seit v4.2.2) covert die neuen Keys — kein Merge mit fehlenden Übersetzungen
- [ ] **I18N-03**: RTL-Layout für Arabic getestet: Avatar wird NICHT gespiegelt (wäre falsch für Gesichter), Text daneben korrekt rechtsbündig

### Accessibility (A11Y)

WCAG 2.3.3 + Vestibular Safety + Screen-Reader.

- [ ] **A11Y-01**: `prefers-reduced-motion: reduce` stoppt alle Animationen komplett (CSS `@media` + JS `matchMedia`-Guard)
- [ ] **A11Y-02**: Manueller "Ruhige Darstellung"-Toggle in PersonalSettings (unabhängig vom OS-Preference, falls User OS-Settings nicht ändern will/kann)
- [ ] **A11Y-03**: Avatar hat `role="img"` + statisches `aria-label` (nicht animation-state-abhängig — sonst spamt Screen-Reader)
- [ ] **A11Y-04**: Keyboard-Navigation im Picker funktioniert (Tab + Arrow + Enter)
- [ ] **A11Y-05**: Fokus-Indikator sichtbar (focus-visible)

### Legal & Copy (LEGAL)

Archetype-Naming durchgängig.

- [x] **LEGAL-01**: `LEGAL.md` in `.planning/` dokumentiert die Archetype-Naming-Entscheidung + Rationale (App-Store-Safety, keine Trademark-Konflikte)
- [x] **LEGAL-02**: Grep-Test CI: Zero-Treffer für "Einstein", "Hawking", "Tyson", "Neil deGrasse", "Cosmos", "StarTalk" in `app/src/**`, `app/l10n/**`, App-Store-Listing
- [x] **LEGAL-03**: CHANGELOG.md + App-Store-Description nennen Archetype-Namen, nicht Vorbilder
- [ ] **LEGAL-04**: Externe Sensitivity-Review für Kosmologe-Archetyp + Popularisierer-Archetyp durchgeführt und signed off vor Phase 4 Freeze (~€300 Budget)

### Migration (MIGR)

Nova-Removal-Trauma-Repeat vermeiden.

- [ ] **MIGR-01**: Bestehende User sehen weiterhin NOVA als Default (Zero-Change) — `learning.virtuprof_skin` bleibt bei ihnen auf `'nova'`
- [ ] **MIGR-02**: Neu registrierte User bekommen Default-Skin `prof_lern_classic`
- [ ] **MIGR-03**: One-time non-intrusive In-App-Hinweis nach v4.4.0-Deploy: "Neue Skins verfügbar in den Einstellungen" (auto-dismiss nach 7 Tagen oder User-Klick)
- [x] **MIGR-04**: CHANGELOG v4.4.0 erklärt die neue Customization explizit
- [ ] **MIGR-05**: DevCloud Kurs 21 + externe User (ernesst) testen v4.4.0 VOR App-Store-Push

### Tests (TEST)

Qualitätssicherung aller Layers.

- [ ] **TEST-01**: Vitest Unit-Tests für SkinRenderer (Dispatch korrekt pro skinId, Fallback bei ungültig)
- [ ] **TEST-02**: Vitest Unit-Tests für `resolveReaction()` (Fallback bei unsupportiertem State)
- [ ] **TEST-03**: Vitest Snapshot-Tests für alle 4 neuen Avatare (Prof. Lern + 3 Archetypen) in allen supporteten States
- [ ] **TEST-04**: Playwright E2E: User öffnet PersonalSettings → wählt Skin → VirtuProf updated ohne reload → persistiert across reload
- [ ] **TEST-05**: Playwright visual-comparison mit `animations: 'disabled'` Flag (stabil gegen Animation-Flakes)
- [ ] **TEST-06**: Manual A11y-Audit: prefers-reduced-motion + Screen-Reader + RTL (Arabic) + Keyboard-Nav

## Out of Scope

Explizit nicht in v4.4.0 — Begründung dokumentiert.

| Feature | Reason |
|---------|--------|
| Echte Portraits (Fotos, photorealistische Zeichnungen) | Lizenz-Risiko (Hawking-Estate, Tyson lebt, Einstein Trademark), Würde-Problem, Bundle-Size. Archetype-Naming ersetzt. |
| WebGL / 3D-Avatare | Bundle +500kb, GPU-Last auf Tablets, A11Y-Probleme |
| User-Upload eigener Avatare | DSGVO, Moderation, XSS-Surface, Speicher — evtl. v5.x |
| Voice-Personalisierung pro Preset (Einstein-typische Zitate) | Prompt-Engineering-Overhead, i18n-Explosion, Stereotyp-Risiko |
| Gamification-Unlocks (Preset als Achievement) | "Paywall for UX", frustriert bei Wechsel. Alle Skins sofort verfügbar. |
| Chibi-Varianten | Verschoben nach v4.5.x — erstmal Core-Varianten stabil bekommen |
| Advanced Mikro-Animationen (peek, shrug, head-tilt, think) | Phase-2-Primitive baut nur die 3 Must-haves (blink, wave, celebrate); Rest v4.5.x |
| Onboarding-Integration (Mentor-Wahl) | Konflikt mit v4.3.0 OnbProfileTiles.vue; verschoben auf v4.5.x INBOX-Item |
| Grid-Picker mit Live-Preview-Animation | Dropdown reicht für v4.4.0; Grid als Polish v4.5.x |
| Admin-Config für Custom-Presets (JSON-File) | Niedrige Priorität, nur Power-User; v4.5.x |

## v4.5.x Requirements (Deferred)

Acknowledged aber nicht in v4.4.0 Roadmap.

- Advanced Micro-Animationen (peek, shrug, head-tilt, think)
- Chibi-Varianten der 3 Archetypen + Prof. Lern
- Onboarding-Integration: Mentor-Wahl als Skin-Picker-Moment
- Grid-Picker UI mit Live-Preview
- Admin-Config für Custom-Character-JSON (gitignored)

## Traceability

Roadmap mapping 2026-04-18. 100% coverage (40/40 REQs).

| Requirement | Phase | Status |
|-------------|-------|--------|
| PICK-01 | Phase 151 | Pending |
| PICK-02 | Phase 151 | Pending |
| PICK-03 | Phase 151 | Pending |
| PICK-04 | Phase 151 | Pending |
| PICK-05 | Phase 151 | Pending |
| CLASSIC-01 | Phase 151 | Pending |
| CLASSIC-02 | Phase 151 | Pending |
| CLASSIC-03 | Phase 151 | Pending |
| CLASSIC-04 | Phase 151 | Pending |
| SCHOLAR-01 | Phase 152 | Complete |
| SCHOLAR-02 | Phase 152 | Complete |
| SCHOLAR-03 | Phase 152 | Complete |
| SCHOLAR-04 | Phase 152 | Complete (ab26155) |
| META-01 | Phase 151 | Pending |
| META-02 | Phase 151 | Pending |
| META-03 | Phase 151 | Pending |
| ANIM-01 | Phase 150 | Pending |
| ANIM-02 | Phase 150 | Pending |
| ANIM-03 | Phase 150 | Pending |
| ANIM-04 | Phase 150 | Pending |
| ANIM-05 | Phase 152 | Complete (0b9f13c) |
| I18N-01 | Phase 153 | Pending |
| I18N-02 | Phase 153 | Pending |
| I18N-03 | Phase 153 | Pending |
| A11Y-01 | Phase 150 | Pending |
| A11Y-02 | Phase 150 | Pending |
| A11Y-03 | Phase 150 | Pending |
| A11Y-04 | Phase 150 | Pending |
| A11Y-05 | Phase 150 | Pending |
| LEGAL-01 | Phase 149 | Complete |
| LEGAL-02 | Phase 149 | Complete |
| LEGAL-03 | Phase 149 | Complete |
| LEGAL-04 | Phase 149 | Pending |
| MIGR-01 | Phase 153 | Pending |
| MIGR-02 | Phase 153 | Pending |
| MIGR-03 | Phase 153 | Pending |
| MIGR-04 | Phase 149 | Complete |
| MIGR-05 | Phase 153 | Pending |
| TEST-01 | Phase 153 | Pending |
| TEST-02 | Phase 153 | Pending |
| TEST-03 | Phase 153 | Pending |
| TEST-04 | Phase 153 | Pending |
| TEST-05 | Phase 153 | Pending |
| TEST-06 | Phase 153 | Pending |

**Coverage:**
- v4.4.0 requirements: 40 total
- Mapped to phases: 40/40 ✓
- Unmapped: 0

**Phase distribution:**
- Phase 149 (Legal + Art Direction + Copy): 5 REQs (LEGAL-01..04, MIGR-04)
- Phase 150 (Animation Architecture + A11y Primitive): 9 REQs (ANIM-01..04, A11Y-01..05)
- Phase 151 (Skin Picker Framework + Prof. Lern Classic): 12 REQs (PICK-01..05, CLASSIC-01..04, META-01..03)
- Phase 152 (Three Archetype Presets): 5 REQs (SCHOLAR-01..04, ANIM-05)
- Phase 153 (Migration + Tests + Deploy + App Store): 13 REQs (MIGR-01..03, MIGR-05, TEST-01..06, I18N-01..03)

---
*Requirements defined: 2026-04-18*
*Last updated: 2026-04-25 — Phase 152 complete: SCHOLAR-01..04 + ANIM-05 (commits ab26155, ..., bbf2367, Plan 152-06)*
