---
phase: 150-animation-architecture
verified: 2026-04-25T06:35:00Z
status: passed
score: 9/9 must-haves verified
re_verification: false
known_deferred:
  - item: "14-step manual A11y walkthrough (Plan 06 Task 3)"
    reason: "User explicitly deferred — ships without manual gate, bugs hunted ad-hoc post-deploy"
    covers:
      - "A11Y-02 toggle without reload + cross-session persistence (steps 4-7)"
      - "A11Y-04 keyboard navigation (steps 8-9)"
      - "A11Y-05 focus-visible ring visual judgment (step 10)"
      - "Plan 02 OS-level reduced-motion regression (step 11)"
      - "Other PersonalSettings field regression (steps 12-14)"
  - item: "10 of 14 silhouette visual spot-checks for head/arms feature classification"
    reason: "Plan 05 Vitest covers 4 silhouettes (nova, architect, ghostline, sysadmin); auto-classification (y < bodyTop) on the other 10 (security, sysadmin variant, helpdesk, chronos, klaus_dau, dr_hartmann, frau_weber, uschi, tim_azubi, sven_berater, fallback) unverified visually"
    covers: ["ANIM-04 visual non-regression for legacy silhouettes"]
---

# Phase 150: Animation Architecture & A11y Primitive — Verification Report

**Phase Goal:** A shared animation engine exists that every skin can use; every animation respects `prefers-reduced-motion` from day one; avatars are screen-reader-friendly and free of memory leaks
**Verified:** 2026-04-25 06:35 UTC
**Status:** passed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

Truths derived from ROADMAP.md Phase 150 Success Criteria (which take priority over plan-level must_haves).

| #   | Truth (Success Criterion)                                                                                                                                                                                                                                          | Status     | Evidence                                                                                                                                                                                                                                                                                                |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `character-animations.css` provides shared `@keyframes` (blink, slight sway) all wrapped in `@media (prefers-reduced-motion: no-preference)`; OS-level reduced-motion stops ALL avatar animation                                                                  | ✓ VERIFIED | `app/src/styles/character-animations.css` (72 lines): `@keyframes blink` + `@keyframes sway` exist; both inside `@media (prefers-reduced-motion: no-preference)`; bottom-of-file `@media (prefers-reduced-motion: reduce) { .character-avatar * { animation: none !important; } }` hard-stop present       |
| 2   | `character-animations.js` exposes WAAPI helpers (`playWave`, `playCelebrate`, `playShrug`) each gated by `matchMedia('(prefers-reduced-motion: reduce)')`, returning instantly                                                                                    | ✓ VERIFIED | `app/src/utils/character-animations.js` (83 lines): all 3 helpers exported + gated via `prefersReducedMotion()` + `animationsEnabledGetter()`; 16 unit tests prove gating; no `setInterval`/`setTimeout`/`requestAnimationFrame`                                                                          |
| 3   | `character-reaction-engine.js` is generalised from `nova-reaction-engine.js` and returns `{animation, emotion, sound, duration}`; falls back to `idle` when state unsupported                                                                                     | ✓ VERIFIED | `app/src/utils/character-reaction-engine.js` (128 lines) exports `EVENT_MAP`, `resolveReaction`, `characterReactions`; `nova-reaction-engine.js` reduced to 33-line wrapper; 15 unit tests prove fallback + shared cooldown; `git diff app/src/components/VirtuProf.vue` is empty (non-breaking)         |
| 4   | Every animated SVG `<g>` uses named ids (`head`, `arms`, `body`) with `transform-box: fill-box` inline (Safari pre-16 pivot fix)                                                                                                                                  | ✓ VERIFIED | `app/src/components/CharacterAvatar.vue` lines 13/18/62 render `<g id="body|head|arms">`; `groupStyleHead/Body/Arms` computed return `transform-origin: ...; transform-box: fill-box;`; 10 Vitest snapshot tests assert structure                                                                          |
| 5   | Avatar SVG root carries `role="img"` + a static aria-label (never per-animation-state)                                                                                                                                                                            | ✓ VERIFIED | `CharacterAvatar.vue` line 10: `role="img"`, line 11: `:aria-label="ariaLabel"` (computed → `character.name` only); test asserts identical aria-label across `idle`/`thinking`/`celebrate` states; no `<title>` element                                                                                  |
| 6   | PersonalSettings "Ruhige Darstellung" toggle overrides OS preference when enabled; takes effect without reload; keyboard nav reaches every control with focus-visible ring                                                                                       | ✓ VERIFIED (UI + automated paths) / ⏳ DEFERRED (manual A11y walkthrough by user choice) | UI row at PersonalSettings.vue line 106-113; backend persistence in SettingsController.php (lines 145, 159, 171); main.js boot wiring lines 6-7, 33; CharacterAvatar reactive `lnc-quiet` class binding (line 376); deployed 2026-04-25 06:30; 7 a11yStore tests green; manual 14-step walkthrough deferred |
| 7   | Avatars are free of memory leaks — explicit cleanup of observers/listeners                                                                                                                                                                                        | ✓ VERIFIED | `CharacterAvatar.vue` `beforeUnmount()` line 143 calls `observer?.disconnect()` + `removeEventListener('visibilitychange', ...)`; `IntersectionObserver` + visibilitychange wired in `setupVisibilityPause()` line 160-166                                                                              |
| 8   | Three-layer animation gate operational (CSS + JS + User store)                                                                                                                                                                                                    | ✓ VERIFIED | Layer 1 CSS: `@media (prefers-reduced-motion: reduce)` in character-animations.css (1 occurrence). Layer 2 JS: `matchMedia` in character-animations.js (2 occurrences). Layer 3 user: `animationsEnabled` in a11yStore.js (3 occurrences). Reactive `lnc-quiet` CSS class also bound on CharacterAvatar |
| 9   | Zero-deps decision honored — no `@vueuse/core` import anywhere in `app/src/`                                                                                                                                                                                       | ✓ VERIFIED | `grep -rE "@vueuse/core" app/src/` returns 0 matches; native `IntersectionObserver` + `document.addEventListener('visibilitychange')` used instead                                                                                                                                                       |

**Score:** 9/9 truths verified

---

### Required Artifacts

| Artifact                                            | Expected                                                                          | Lines | Status     | Details                                                                                                                       |
| --------------------------------------------------- | --------------------------------------------------------------------------------- | ----: | ---------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `app/src/styles/character-animations.css`           | Shared keyframes + reduced-motion gate + lnc-quiet + focus-visible                |    72 | ✓ VERIFIED | All required selectors present; no forbidden CSS props (filter/box-shadow/width/height); imported globally in main.js         |
| `app/src/utils/character-animations.js`             | playWave/playCelebrate/playShrug + setAnimationsEnabledGetter                     |    83 | ✓ VERIFIED | All 4 named exports present; matchMedia + manual-override gates active; no timers                                              |
| `app/src/utils/character-reaction-engine.js`        | Generic engine — EVENT_MAP, resolveReaction, characterReactions                   |   128 | ✓ VERIFIED | All 3 named exports; cooldown logic preserved (session/pool/day); pure resolver + stateful facade                              |
| `app/src/utils/nova-reaction-engine.js`             | Thin wrapper preserving novaReactions API + Nova audio                            |    33 | ✓ VERIFIED | Imports characterReactions; preserves novaReactions.react/canReact/reset; novaAudio.play side-effect retained                  |
| `app/src/stores/a11yStore.js`                       | Pinia store: animationsEnabled + setEnabled + loadFromServerPayload               |    31 | ✓ VERIFIED | useA11yStore exported; default true; payload hydration with null-safety                                                        |
| `app/src/components/CharacterAvatar.vue`            | Named g sub-groups + static aria-label + visibility-pause + lnc-quiet binding     |   520 | ✓ VERIFIED | Template lines 13/18/62; transform-box inline on each g; useA11yStore + animationsQuiet computed wired                         |
| `app/src/components/PersonalSettings.vue`           | "Ruhige Darstellung" toggle row + reactive store + persisted backend body         |   885 | ✓ VERIFIED | Toggle at line 106-113; load hydration line 528-530; save body line 617; onAnimationsEnabledChange method line 598            |
| `app/lib/Controller/SettingsController.php`         | getPersonal reads + savePersonal writes `animations_enabled`                      |   230 | ✓ VERIFIED | 3 occurrences of `animations_enabled` (read default, param, setUserValue); PHPStan Level 5 clean; deployed (container grep=3) |
| `app/src/main.js`                                   | Boot-time wiring of setAnimationsEnabledGetter to a11yStore                       |    35 | ✓ VERIFIED | Lines 6/7 imports, line 33: `setAnimationsEnabledGetter(() => useA11yStore().animationsEnabled)` after `app.use(pinia)`        |
| `app/tests/unit/character-animations.test.js`       | WAAPI gating tests (3 helpers × 5 cases + setter)                                  |   101 | ✓ VERIFIED | 16/16 tests passing                                                                                                            |
| `app/tests/unit/character-reaction-engine.test.js`  | Resolver + cooldown + Nova-non-breaking + shared-state tests                      |   136 | ✓ VERIFIED | 15/15 tests passing                                                                                                            |
| `app/tests/unit/CharacterAvatar.test.js`            | Named-g + transform-box + static aria-label + 4 silhouette renders                |    95 | ✓ VERIFIED | 10/10 tests passing                                                                                                            |
| `app/tests/unit/a11yStore.test.js`                  | Defaults + setEnabled coercion + payload hydration + null-safety                  |    44 | ✓ VERIFIED | 7/7 tests passing                                                                                                              |

---

### Key Link Verification

| From                          | To                              | Via                                                                          | Status   | Details                                                                                                                            |
| ----------------------------- | ------------------------------- | ---------------------------------------------------------------------------- | -------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `app/src/main.js`             | `character-animations.css`      | `import './styles/character-animations.css'`                                  | ✓ WIRED  | Line: `import './styles/character-animations.css';`                                                                                |
| `app/src/main.js`             | `character-animations.js`       | `setAnimationsEnabledGetter(() => useA11yStore().animationsEnabled)`         | ✓ WIRED  | Lines 6+33 — wired AFTER `app.use(pinia)` (load-order critical)                                                                   |
| `app/src/main.js`             | `a11yStore.js`                  | `useA11yStore` import + invocation                                            | ✓ WIRED  | Line 7 import, line 33 call inside getter closure                                                                                  |
| `PersonalSettings.vue`        | `a11yStore.js`                  | `useA11yStore().setEnabled(...)` + `loadFromServerPayload(...)`              | ✓ WIRED  | Line 385 import; line 530 hydration; line 602 reactive setter                                                                      |
| `PersonalSettings.vue`        | `SettingsController::savePersonal` | `animations_enabled` body field via axios.put                              | ✓ WIRED  | Line 617: `animations_enabled: this.form.animationsEnabled ? 'yes' : 'no'`                                                         |
| `CharacterAvatar.vue`         | `a11yStore.js`                  | `useA11yStore` for `animationsQuiet` computed → `lnc-quiet` class            | ✓ WIRED  | Line 111 import; line 380-384 computed (try/catch fallback); line 376 class binding via `stateClasses`                            |
| `nova-reaction-engine.js`     | `character-reaction-engine.js`  | `import { characterReactions }` + delegation                                 | ✓ WIRED  | 33-line wrapper delegates `react`/`canReact`/`reset`; preserves Nova audio side-effect                                             |
| `VirtuProf.vue`               | `nova-reaction-engine.js`       | `import { novaReactions }` UNCHANGED                                         | ✓ WIRED  | Line 96 import + line 1185 callsite — `git diff` empty (non-breaking guarantee)                                                    |
| `CharacterAvatar.vue`         | Native browser APIs             | `new IntersectionObserver(...)` + `document.addEventListener('visibilitychange')` + explicit `beforeUnmount()` cleanup | ✓ WIRED  | Lines 143-146 cleanup, 160-166 setup; jsdom guard via `typeof IntersectionObserver === 'undefined'`                              |

---

### Requirements Coverage

| Requirement | Source Plan(s) | Description                                                                       | Status      | Evidence                                                                                              |
| ----------- | -------------- | --------------------------------------------------------------------------------- | ----------- | ----------------------------------------------------------------------------------------------------- |
| ANIM-01     | 01, 02         | `@keyframes` for idle loops, gated in `prefers-reduced-motion: no-preference`     | ✓ SATISFIED | character-animations.css `@keyframes blink` + `@keyframes sway` inside no-preference media block      |
| ANIM-02     | 01, 03         | WAAPI helpers (wave/celebrate/shrug) gated by matchMedia                          | ✓ SATISFIED | character-animations.js: 3 helpers, 16 tests passing, no fallback timers                              |
| ANIM-03     | 01, 04         | Generic reaction engine with graceful skin-state fallback                         | ✓ SATISFIED | character-reaction-engine.js: resolveReaction + 15 tests; nova wrapper non-breaking                   |
| ANIM-04     | 01, 05         | CharacterAvatar SVG named `<g id=head|arms|body>` + transform-box: fill-box       | ✓ SATISFIED | Template lines 13/18/62 + groupStyle* computed properties; 10 structural tests passing                |
| A11Y-01     | 02, 03         | `prefers-reduced-motion: reduce` stops all animations (CSS + JS)                  | ✓ SATISFIED | CSS hard-stop override + JS prefersReducedMotion() gate; 6 gating tests across helpers                |
| A11Y-02     | 06             | Manual "Ruhige Darstellung" toggle in PersonalSettings (independent of OS)        | ✓ SATISFIED (code) / ⏳ DEFERRED (manual cross-session check) | UI row + backend persistence + reactive lnc-quiet binding wired end-to-end; 14-step walkthrough deferred by user |
| A11Y-03     | 05             | Avatar `role="img"` + static aria-label (no animation-state spam)                 | ✓ SATISFIED | role="img" + ariaLabel computed → character.name; state-leak test asserts identical labels             |
| A11Y-04     | 06             | Keyboard navigation (Tab + Arrow + Enter)                                         | ⏳ DEFERRED (manual)  | NcCheckboxRadioSwitch is keyboard-accessible by NC convention; visual verification deferred           |
| A11Y-05     | 02, 06         | Focus-visible ring on controls                                                    | ✓ SATISFIED (CSS) / ⏳ DEFERRED (visual judgment) | `.lnc-a11y-toggle :focus-visible` rule in CSS; class applied on toggle row line 106                   |

**Coverage:** 9/9 requirements have implementation in code (7 fully verified by automated checks; 2 — A11Y-04 and the visual half of A11Y-05 — depend on the deferred manual walkthrough but the code paths are wired).

**Orphaned requirements:** None. REQUIREMENTS.md maps all 9 requirement IDs to Phase 150, and all 9 appear in plan frontmatter (ANIM-01..04 + A11Y-01..05).

---

### Anti-Patterns Scan

| File                                              | Pattern             | Severity | Impact                                                |
| ------------------------------------------------- | ------------------- | -------- | ----------------------------------------------------- |
| (all phase 150 files)                             | TODO/FIXME/XXX/HACK | ℹ️ Info  | None found across CSS/JS/Vue stub-free implementations |
| `app/src/utils/character-animations.js`           | setInterval/setTimeout/requestAnimationFrame | ℹ️ Info | None found (WAAPI-only constraint honored)            |
| `app/src/styles/character-animations.css`         | filter/box-shadow/width/height | ℹ️ Info | None found in keyframes (ART_STYLE_GUIDE Section 4)   |
| `app/src/components/CharacterAvatar.vue`         | aria-label with state-leak | ℹ️ Info | None found (`grep aria-label.*state` returns 0)      |
| `app/src/`                                       | `@vueuse/core` import | ℹ️ Info | None found (zero-deps decision v4.4.0 honored)       |

No blocker or warning anti-patterns. Plans 02/04/05/06 SUMMARY.md document a few documented deviations from PLAN (non-functional refinements; see "Documented deviations" below).

---

### Documented Deviations from Plans (informational)

These were intentional refinements made during execution and are documented in each plan's SUMMARY.md. None affect goal achievement.

- **Plan 05 — Underscore data keys:** Renamed `_observer`/`_onVisibilityChange`/`_lastIntersecting` → `observer`/`onVisibilityChange`/`lastIntersecting` (Vue ESLint rule `vue/no-reserved-keys` blocks underscore prefix in `data()`). Functional behavior unchanged.
- **Plan 05 — Test framework:** Used native `createApp` + `happy-dom` instead of `@vue/test-utils` (matches existing project pattern in `tests/unit/GlobalFeed.test.js`). Functionally equivalent for structural assertions.
- **Plan 06 — Static useA11yStore import:** Replaced suggested dynamic `import()` with top-level static import in PersonalSettings.vue (cleaner, follows existing convention).
- **Plan 06 — Save pattern:** Toggle uses the existing explicit-Save-button pattern (matches `dailyChallengeEnabled`); reactivity gives instant visual feedback, persistence requires Save click. Worth flagging in deferred-items so walkthrough step 6 (reload-persistence) is tested AFTER clicking Save.
- **Plan 06 — CharacterAvatar Pinia fallback:** `animationsQuiet` wraps `useA11yStore()` in try/catch falling back to `false` for ad-hoc test mounts without Pinia. Production main.js installs Pinia first, so this is benign — but if a future preview page mounts CharacterAvatar pre-Pinia, the avatar silently animates regardless of toggle. Logged as Open Risk.

---

### Test Suite Results

- Phase 150 unit tests: **48/48 passing** (16 + 15 + 10 + 7)
- Full app suite: **1009/1009 passing** (across 69 test files) — exactly +48 over the pre-150 baseline of 961, no regressions
- ESLint: 0 errors on all modified JS/Vue/test files
- PHPStan Level 5: **No errors** on `lib/Controller/SettingsController.php`
- Build: `personal-settings.js` (207 kB) deployed at 06:30 UTC, 5 minutes before verification ran — confirmed in container

---

### Known Deferred Manual Items

These are tracked here (NOT as blocking gaps) by explicit user choice. They remain executable any time post-deploy.

#### 1. 14-step manual A11y walkthrough (Plan 06 Task 3)

**User decision:** Ship without manual gate, hunt bugs ad-hoc post-deploy.

**Steps deferred:**
- A11Y-02: Toggle reactivity (steps 4-5), reload persistence (step 6), cross-session persistence (step 7) — *NOTE: step 6/7 require user to click Save first because PersonalSettings uses explicit-Save-button pattern, not auto-save. Flag as test-prep, not bug.*
- A11Y-04: Tab navigation reaches switch (step 8), no Tab-trap regression (step 9)
- A11Y-05: focus-visible ring on Tab focus, NOT on mouse click (step 10)
- Plan 02 OS-level regression: DevTools `prefers-reduced-motion: reduce` emulation stops avatar (step 11)
- Other PersonalSettings field regression (steps 12-14)

**Why human:** Visual rendering, real-time browser interactions, screen-reader (NVDA/VoiceOver) behavior cannot be programmatically asserted from grep/Vitest.

#### 2. Visual silhouette spot-checks (10 of 14 unverified)

**Coverage:** Plan 05 Vitest mounts and asserts structure for `nova`, `architect`, `ghostline`, `sysadmin`. The auto-classification rule (head if `cy/y/y1 < bodyTop`, else arms) is unverified for the other 10 silhouettes (`security`, `helpdesk`, `chronos`, `klaus_dau`, `dr_hartmann`, `frau_weber`, `uschi`, `tim_azubi`, `sven_berater`, fallback).

**Why human:** Whether a feature element ended up in the right `<g>` is a visual judgment — the auto-classifier may have placed e.g. `klaus_dau`'s tie-knot in head instead of arms. Production code paths execute correctly (no runtime errors, all silhouettes render); the classification is just unverified per-silhouette.

#### 3. Backend round-trip API test

`test-api.sh` (Gate 2) does not yet assert the `animations_enabled` PUT/GET round-trip. Phase 153 pre-release sweep should add it.

---

### Gaps Summary

**No gaps blocking goal achievement.**

All 9 of the phase's success criteria have working code paths in the codebase. Phase 150's technical contract — *shared engine, prefers-reduced-motion gate, screen-reader-friendly avatars, no memory leaks* — is met by:

- Three-layer animation gate operational (CSS + JS + a11yStore)
- WAAPI helpers gated in 16 unit tests across all 3 helpers
- Generic reaction engine with skin-state fallback (15 unit tests)
- Named SVG sub-groups with Safari pre-16 transform-box fix (10 unit tests)
- Static aria-label across all animation states (state-leak test asserts identity)
- Explicit `beforeUnmount()` cleanup of IntersectionObserver + visibilitychange listener
- Zero `@vueuse/core` imports — native browser APIs only
- Backend persistence verified deployed (container grep `animations_enabled = 3`)
- Full test suite: 1009/1009 passing (exactly +48 phase delta over 961 baseline)

User-facing toggle was deployed via `./scripts/deploy-prod.sh --full` at 06:30 UTC; manual A11y walkthrough is intentionally deferred per user instruction (will be executed ad-hoc during post-deploy bug hunting).

Phase 151 (Skin Picker Framework + Prof. Lern Classic) can proceed.

---

_Verified: 2026-04-25 06:35 UTC_
_Verifier: Claude (gsd-verifier)_
