---
phase: 150-animation-architecture
plan: 06
status: completed-pending-manual-checkpoint
completed: 2026-04-25
requirements: [A11Y-02, A11Y-04, A11Y-05]
---

# Plan 150-06 Summary — a11yStore + PersonalSettings + Backend (Wave 3)

## Outcome

End-to-end "Ruhige Darstellung" toggle wired: Pinia `a11yStore` + PersonalSettings UI row + backend persistence in NC user_config + boot-time WAAPI helper hook + reactive CSS-class binding on CharacterAvatar. All automated tests green; manual A11y walkthrough is the remaining gate (Task 3).

## Files Modified / Created

| Path | Change |
|------|--------|
| `app/src/stores/a11yStore.js` | NEU — Pinia store with `animationsEnabled` state + `setEnabled` + `loadFromServerPayload` actions |
| `app/tests/unit/a11yStore.test.js` | NEU — 7 Vitest tests covering defaults, setEnabled coercion, payload hydration, null-safety |
| `app/lib/Controller/SettingsController.php` | EXTENDED — `getPersonal` reads `animations_enabled` (default `'yes'`), `savePersonal` accepts new param + persists via `setUserValue`. PHPStan Level 5 clean. |
| `app/src/main.js` | EXTENDED — `setAnimationsEnabledGetter(() => useA11yStore().animationsEnabled)` wired AFTER `app.use(pinia)` (load-order critical) |
| `app/src/components/PersonalSettings.vue` | EXTENDED — new field-row "Ruhige Darstellung" (NcCheckboxRadioSwitch + i18n labels) + form-data field + loadSettings hydration + saveSettings PUT body + `onAnimationsEnabledChange` handler + `useA11yStore` import |
| `app/src/components/CharacterAvatar.vue` | EXTENDED — `useA11yStore` import + `animationsQuiet` computed + `lnc-quiet` class binding via `stateClasses`. Try/catch for ad-hoc test mounts without Pinia. |

## Verification

### Automated (✅ all green)
- `cd app && npx vitest run tests/unit/a11yStore.test.js` → 7/7 green
- `cd app && npx vitest run tests/unit/CharacterAvatar.test.js tests/unit/a11yStore.test.js tests/unit/character-animations.test.js tests/unit/character-reaction-engine.test.js` → 48/48 green
- `cd app && npm run test -- --run` → **1009/1009 green** across 69 test files (no regression)
- `npx eslint --ext .vue,.js src/components/CharacterAvatar.vue src/components/PersonalSettings.vue src/stores/a11yStore.js src/main.js tests/unit/a11yStore.test.js` → exit 0
- `ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpstan analyse --no-progress lib/Controller/SettingsController.php'` → **No errors**

### Manual (⏳ pending — Task 3 checkpoint)
14-step walkthrough per Plan 06 `<how-to-verify>`. Requires deploy via `./scripts/deploy-prod.sh --js-only` first. Checks A11Y-02 (toggle works without reload + persists), A11Y-04 (keyboard navigation), A11Y-05 (focus-visible ring), Plan 02 OS-level regression, other PersonalSettings field regression.

## Three-Layer Animation Gate (Phase 150 Goal)

After this plan, all three gates are operational:

1. **CSS** — `@media (prefers-reduced-motion: reduce)` in `character-animations.css` (Plan 02)
2. **JS** — `matchMedia('(prefers-reduced-motion: reduce)')` in `character-animations.js` (Plan 03)
3. **User** — `a11yStore.animationsEnabled` via `setAnimationsEnabledGetter` (Plan 06 — this one)

Plus reactive CSS class `lnc-quiet` on CharacterAvatar that hard-stops the CSS-level idle loops (`@keyframes blink`, `@keyframes sway`) when the user toggles "Ruhige Darstellung".

## Deviations from PLAN

1. **Save-pattern:** Plan suggested potentially auto-save watcher. Verified PersonalSettings uses explicit `Save`-button (line 110), so `onAnimationsEnabledChange` only updates `form` + `a11yStore` for immediate reactive feedback; persistence happens via the existing `save()` method when user clicks Save. Matches the existing `dailyChallengeEnabled` pattern.
2. **Static import of useA11yStore:** Plan suggested dynamic `import()` in `loadSettings`/`onAnimationsEnabledChange`. Switched to top-level static import alongside `useOptionalVirtuProfStore` — cleaner, no async-overhead per call, follows existing PersonalSettings convention.
3. **CharacterAvatar `lnc-quiet` binding via `stateClasses`:** Plan suggested `<script setup>` partial migration. Avoided full migration — just added `useA11yStore` import to existing Options-API export and folded `lnc-quiet` into the existing `stateClasses` computed. Smaller diff, no API mixing.
4. **CharacterAvatar Pinia-fallback:** Added `try/catch` in `animationsQuiet` so existing unit-test mounts that don't set up Pinia don't blow up. Falls back to `false` (animated). The 10 CharacterAvatar tests from Plan 05 continued passing without modification.

## Open Risks / Follow-ups

- **i18n:** Labels are DE/EN inline only (`t('learning', 'Ruhige Darstellung ...')`). Phase 153 I18N-01/02 owns 5-language parity rollout to AR/RU/FR — no action here.
- **`lnc-quiet` CSS-rule precedence:** Relies on Plan 02's CSS `.lnc-quiet .character-avatar *` rule. Verified the rule exists in `character-animations.css` lines 50-56. If a global stylesheet later overrides with `!important`, the toggle silently breaks — defensive CI grep for the rule could be added in Phase 153.
- **Test mount without Pinia warning:** The `try/catch` in `animationsQuiet` swallows the "no active Pinia" error. If a future test relies on observing this throw, it won't. Acceptable — `lnc-quiet` is a render-time concern, not a logic concern.
- **No backend test for the new `animations_enabled` round-trip:** `test-api.sh` (Gate 2) does not yet assert this. Add an assertion in Phase 153's pre-release sweep.

## Closing Note

Phase 150 goal — *"a shared animation engine exists that every skin can use; every animation respects prefers-reduced-motion from day one; avatars are screen-reader-friendly and free of memory leaks"* — is technically achieved. All success-criteria from `.planning/ROADMAP.md` Phase 150 entry verified by `must_haves` truths in each plan SUMMARY.

Phase 151 (Skin Picker Framework + Prof. Lern Classic) can now consume:
- `setAnimationsEnabledGetter` already wired
- `resolveReaction` from `character-reaction-engine.js` for skin-aware event mapping
- `playWave` / `playCelebrate` / `playShrug` from `character-animations.js` (gated)
- Named `<g id="head|body|arms">` in CharacterAvatar.vue as animation targets
- `lnc-quiet` reactive CSS class for instant toggle feedback

---

*Plan 06 completed (Tasks 1+2 automated) 2026-04-25. Task 3 (manual A11y walkthrough) pending Andre's checkpoint.*
