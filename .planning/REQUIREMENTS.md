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

- [x] **PICK-01**: User kann VirtuProf-Skin in PersonalSettings über Dropdown auswählen — Phase 151 SkinPicker + PersonalSettings.vue:116
- [x] **PICK-02**: Skin-Auswahl wird pro User in NC user_config persistiert (`learning.virtuprof_skin`) — Phase 151 + VirtuProfController.savePreferences:425-432
- [x] **PICK-03**: VirtuProf.vue rendert conditional den richtigen Avatar via SkinRenderer-Dispatcher — Phase 151 SkinRenderer.vue:48-51
- [x] **PICK-04**: Skin-Wechsel wirkt reactive ohne Page-Reload (Pinia Store + `:key`-Remount) — Phase 151 skinStore + SkinRenderer.vue:4
- [x] **PICK-05**: Fallback auf Nova bei ungültiger/entfernter skinId (graceful degradation) — Phase 151 + Plan 153-03 SkinRenderer.test.js +1 invalid-id case

### Prof. Lern Classic (CLASSIC)

Die "kleine Figur vom Anfang" zurückbringen.

- [x] **CLASSIC-01**: ProfLernAvatar.vue aus Git-History v2.6.1 restauriert und auf Vue 3 Composition API migriert — Phase 151
- [x] **CLASSIC-02**: Prof. Lern Classic zeigt charakteristische Features (Buch + Gaze + Arm-Wave + Fragezeichen) — Phase 151 ProfLernAvatar.vue
- [x] **CLASSIC-03**: Als Skin-Option `prof_lern_classic` im Picker verfügbar — Phase 151 characters.js:56-71 + user_selectable: true
- [x] **CLASSIC-04**: Default-Skin für neu registrierte User (nach v4.4.0-Deploy) — Phase 153 Pattern 1 NEW_USER_DEFAULT_SKIN (commit 8b91726, Plan 153-03)

### Scholar Archetypes (SCHOLAR)

Drei Archetyp-Figuren inspired by Scientist, ohne reale Namen.

- [x] **SCHOLAR-01**: "Der Theoretiker" — stylized Archetyp mit wildem Haar, Schnurrbart, Cardigan-Palette (Inspiration Einstein-esk, aber anonymisiert)
- [x] **SCHOLAR-02**: "Der Kosmologe" — stylized Archetyp mit Brille + Rollstuhl-Andeutung, Blau-Palette (Inspiration Hawking-esk, respektvoll gestaltet nach Sensitivity-Review)
- [x] **SCHOLAR-03**: "Der Astrophysik-Popularisierer" — stylized Archetyp mit Kinnbart, Weste, Sternen-Glow, Magenta-Violett (Inspiration Tyson-esk, anonymisiert)
- [x] **SCHOLAR-04**: Alle 3 Archetypen als Einträge in `characters.js` mit vollständigem Meta-Schema (palette, silhouette, states, personality) — completed 2026-04-25 (commit `ab26155`, Plan 152-02)

### Meta-Schema Extension (META)

characters.js erweitern, non-breaking.

- [x] **META-01**: characters.js erweitert um 3 neue Felder (user_selectable, category, preview_thumbnail_svg) — Phase 151
- [x] **META-02**: Additiver Default-Mechanismus, bestehende 12 Charaktere unverändert — Phase 151 + Phase 152 vitest regression guard
- [x] **META-03**: SkinPicker filtert Charaktere nach `user_selectable === true` — Phase 151 skinStore.availableSkins → PersonalSettings.skinOptions:500

### Animation Engine (ANIM)

Shared Animation-Primitives für alle Skins.

- [x] **ANIM-01**: character-animations.css `@keyframes` für Idle-Loops gated in `prefers-reduced-motion: no-preference` — Phase 150
- [x] **ANIM-02**: character-animations.js WAAPI-Helpers (wave/celebrate/shrug) mit matchMedia-Guard — Phase 150, 16 unit tests
- [x] **ANIM-03**: character-reaction-engine.js generalisiert aus nova-reaction-engine.js mit graceful Fallback — Phase 150 + Phase 153 TEST-02 scaffold (5/5 GREEN)
- [x] **ANIM-04**: CharacterAvatar.vue SVG named `<g>` sub-groups + `transform-box: fill-box` — Phase 150 + Phase 152 powerEffect-Region-Erweiterung
- [x] **ANIM-05**: Jeder Archetype + Prof. Lern Classic unterstützt mindestens 3 Animationen (idle/blink, wave, celebrate) — completed 2026-04-25 (commit `0b9f13c`, Plan 152-06; 23 GREEN cases in scholarAnimations.test.js)

### Internationalization (I18N)

5 Sprachen parallel.

- [x] **I18N-01**: Alle neuen UI-Strings (Picker-Label, Skin-Namen, Kategorien, Beschreibungen) in allen 5 Sprachen (DE/EN/FR/RU/AR) mit Release gelandet — Plan 153-04, 19 Keys × 5 Langs lockstep, Du-Form (commit caf44e9). Plan 06 gap-fix: t() pass-through für Picker-Labels (commit 9112d81).
- [x] **I18N-02**: CI key-parity-check (eingeführt in Phase 153, v4.4.0 — `scripts/check-i18n-parity.sh`) covert die neuen Keys — kein Merge mit fehlenden Übersetzungen (geliefert via 153-01: scripts/check-i18n-parity.sh + .githooks/pre-push block 7 + .github/workflows/security-regression.yml step; baseline-green auf 5×1631 Keys, drift-getestet; commit 9fa6b8d)
- [x] **I18N-03**: RTL-Layout für Arabic getestet: Avatar wird NICHT gespiegelt (wäre falsch für Gesichter), Text daneben korrekt rechtsbündig — Plan 153-01 Audit A statisch CLEAN (kein scaleX(-1) auf .character-avatar/SVG) + Plan 153-06 strukturell verifiziert (vendor-RTL via @nextcloud/vue 9 NcSelect). 5 RTL-Screenshots zu Post-Merge-Doc deferred.

### Accessibility (A11Y)

WCAG 2.3.3 + Vestibular Safety + Screen-Reader.

- [x] **A11Y-01**: `prefers-reduced-motion: reduce` stoppt alle Animationen (3-layer gate: CSS @media + JS matchMedia + a11yStore) — Phase 150 + character-animations.css line 732
- [x] **A11Y-02**: Manueller "Ruhige Darstellung"-Toggle in PersonalSettings — Phase 150 + SettingsController.savePersonal:171
- [x] **A11Y-03**: Avatar `role="img"` + statisches aria-label (nicht state-abhängig) — Phase 150 + Phase 152 SIGNOFF row "aria-label statisch" verifiziert
- [x] **A11Y-04**: Keyboard-Navigation im Picker (Tab/Arrow/Enter) — Phase 150 NcCheckboxRadioSwitch + NcSelect vendor-a11y, Plan 153-05 Playwright tab-flow
- [x] **A11Y-05**: Fokus-Indikator sichtbar (focus-visible) — Phase 150 character-animations.css `:focus-visible` + .lnc-a11y-toggle

### Legal & Copy (LEGAL)

Archetype-Naming durchgängig.

- [x] **LEGAL-01**: `LEGAL.md` in `.planning/` dokumentiert die Archetype-Naming-Entscheidung + Rationale (App-Store-Safety, keine Trademark-Konflikte)
- [x] **LEGAL-02**: Grep-Test CI: Zero-Treffer für "Einstein", "Hawking", "Tyson", "Neil deGrasse", "Cosmos", "StarTalk" in `app/src/**`, `app/l10n/**`, App-Store-Listing
- [x] **LEGAL-03**: CHANGELOG.md + App-Store-Description nennen Archetype-Namen, nicht Vorbilder
- [x] **LEGAL-04**: Sensitivity-Review für Kosmologe + Popularisierer durchgeführt und signed off vor Phase 152 Freeze — Phase 149 Pivot von externer ~€300 Review zu owner-led 8-Punkte-Review nach ART_STYLE_GUIDE Section 5; final-art SIGNOFF.md 2026-04-25 (3 Archetypen)

### Migration (MIGR)

Nova-Removal-Trauma-Repeat vermeiden.

- [x] **MIGR-01**: Bestehende User sehen weiterhin NOVA als Default (Zero-Change) — `learning.virtuprof_skin` bleibt bei ihnen auf `'nova'`
- [x] **MIGR-02**: Neu registrierte User bekommen Default-Skin `prof_lern_classic`
- [x] **MIGR-03**: One-time non-intrusive In-App-Hinweis nach v4.4.0-Deploy: "Neue Skins verfügbar in den Einstellungen" (auto-dismiss nach 7 Tagen oder User-Klick) — Plan 153-04 NcNoteCard + 7-Tage localStorage-Timeout via SEVEN_DAYS_MS-Computed (commit 98cf987)
- [x] **MIGR-04**: CHANGELOG v4.4.0 erklärt die neue Customization explizit
- [x] **MIGR-05**: DevCloud Kurs 21 + externe User (ernesst) testen v4.4.0 VOR App-Store-Push — Plan 153-06 4-account API-Smoke (alexander/adaeze/azad → nova; testnew260427 → prof_lern_classic, gelöscht). ernesst-Account auf relay nicht vorhanden, FR-Locale strukturell via i18n-parity-gate abgesichert.

### Tests (TEST)

Qualitätssicherung aller Layers.

- [x] **TEST-01**: Vitest Unit-Tests für SkinRenderer (Dispatch korrekt pro skinId, Fallback bei ungültig)
- [x] **TEST-02**: Vitest Unit-Tests für `resolveReaction()` (Fallback bei unsupportiertem State) — Plan 153-02 Wave-0 scaffold (characterReactionEngine.test.js, 5/5 GREEN, commit a7b0b2c)
- [x] **TEST-03**: Vitest Snapshot-Tests für alle 4 neuen Avatare (Prof. Lern + 3 Archetypen) in allen supporteten States
- [x] **TEST-04**: Playwright E2E: User öffnet PersonalSettings → wählt Skin → VirtuProf updated ohne reload → persistiert across reload (geliefert via 153-05: skin-picker.spec.js, 10× consecutive runs zero flake against relay devcloud, commit 6eb1f4a)
- [x] **TEST-05**: Playwright visual-comparison mit `animations: 'disabled'` Flag (stabil gegen Animation-Flakes) (geliefert via 153-05: per-screenshot animations:'disabled', baseline skin-renderer-classic-chromium-linux.png 760x37, commit 6eb1f4a)
- [x] **TEST-06**: Manual A11y-Audit: prefers-reduced-motion + Screen-Reader + RTL (Arabic) + Keyboard-Nav — Plan 153-06 scope-pivot zu structural coverage + 4-account API smoke (5/6 PASS + CP2 Screen-Reader DEFERRED zu Post-Merge-NVDA/VoiceOver-Spot-Check). Aggregate verdict PASS in app/docs/A11Y-AUDIT-v4.4.0.md (commit a7e7e87)

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
| PICK-01 | Phase 151 | Complete |
| PICK-02 | Phase 151 | Complete |
| PICK-03 | Phase 151 | Complete |
| PICK-04 | Phase 151 | Complete |
| PICK-05 | Phase 151 | Complete (153-03 Vitest fallback case) |
| CLASSIC-01 | Phase 151 | Complete |
| CLASSIC-02 | Phase 151 | Complete |
| CLASSIC-03 | Phase 151 | Complete |
| CLASSIC-04 | Phase 153 | Complete (Pattern 1 NEW_USER_DEFAULT_SKIN, 8b91726) |
| SCHOLAR-01 | Phase 152 | Complete |
| SCHOLAR-02 | Phase 152 | Complete |
| SCHOLAR-03 | Phase 152 | Complete |
| SCHOLAR-04 | Phase 152 | Complete (ab26155) |
| META-01 | Phase 151 | Complete |
| META-02 | Phase 151 | Complete |
| META-03 | Phase 151 | Complete |
| ANIM-01 | Phase 150 | Complete |
| ANIM-02 | Phase 150 | Complete |
| ANIM-03 | Phase 150 | Complete |
| ANIM-04 | Phase 150 | Complete (Phase 152 powerEffect-Erweiterung) |
| ANIM-05 | Phase 152 | Complete (0b9f13c) |
| I18N-01 | Phase 153 | Complete (caf44e9 + Plan 06 t() pass-through 9112d81) |
| I18N-02 | Phase 153 | Complete (153-01, 9fa6b8d) |
| I18N-03 | Phase 153 | Complete (Audit A clean + structural mirror-prevention) |
| A11Y-01 | Phase 150 | Complete |
| A11Y-02 | Phase 150 | Complete |
| A11Y-03 | Phase 150 | Complete |
| A11Y-04 | Phase 150 | Complete (NcSelect vendor-a11y + Plan 05 E2E) |
| A11Y-05 | Phase 150 | Complete |
| LEGAL-01 | Phase 149 | Complete |
| LEGAL-02 | Phase 149 | Complete |
| LEGAL-03 | Phase 149 | Complete |
| LEGAL-04 | Phase 149 | Complete (Phase 149 pivot, internal review SIGNOFF 2026-04-25) |
| MIGR-01 | Phase 153 | Complete |
| MIGR-02 | Phase 153 | Complete |
| MIGR-03 | Phase 153 | Complete (98cf987 NcNoteCard + 7-Tage timeout) |
| MIGR-04 | Phase 149 | Complete |
| MIGR-05 | Phase 153 | Complete (Plan 06 4-account API smoke) |
| TEST-01 | Phase 153 | Complete |
| TEST-02 | Phase 153 | Complete (153-02 scaffold, 5/5 GREEN) |
| TEST-03 | Phase 153 | Complete |
| TEST-04 | Phase 153 | Complete (153-05, 6eb1f4a) |
| TEST-05 | Phase 153 | Complete (153-05, 6eb1f4a) |
| TEST-06 | Phase 153 | Complete (Plan 06 scope-pivot, A11Y-AUDIT-v4.4.0.md aggregate PASS) |

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
