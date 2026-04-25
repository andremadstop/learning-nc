---
phase: 150-animation-architecture
plan: 05
status: completed
completed: 2026-04-25
requirements: [ANIM-04, A11Y-03]
---

# Plan 150-05 Summary — CharacterAvatar Refactor (Named g sub-groups + native pause)

## Outcome

`CharacterAvatar.vue` refactored from flat SVG into named `<g id="head|body|arms">` sub-groups with `transform-box: fill-box` inline (Safari pre-16 pivot fix). SVG root now carries `role="img"` + static `aria-label = character.name` (no state leak — A11Y-03 + Pitfall #9). Animation pause via native `IntersectionObserver` + `document.visibilitychange` event listener with explicit `beforeUnmount()` cleanup. **No `@vueuse/core` import** (v4.4.0 zero-deps decision).

## Files Modified

| Path | Change | Lines (after) |
|------|--------|---------------|
| `app/src/components/CharacterAvatar.vue` | Template split into 3 named `<g>` sub-groups; new computed `headFeatures`, `armsFeatures`, `ariaLabel`, `groupStyle*`; new `data` + `mounted` + `beforeUnmount` + `setupVisibilityPause()` method | ~470 (was 401) |
| `app/tests/unit/CharacterAvatar.test.js` | 10 real tests (replaces 4 `.todo` stubs from Plan 150-01); uses native `createApp` + `happy-dom` (project pattern, no `@vue/test-utils`) | 95 |

## Verification

- ✅ Vitest: `cd app && npx vitest run tests/unit/CharacterAvatar.test.js` → 10/10 green
- ✅ ESLint: `npx eslint --ext .vue,.js src/components/CharacterAvatar.vue tests/unit/CharacterAvatar.test.js` → 0 errors
- ✅ All 7 must_haves from Plan 05 frontmatter satisfied:
  - `<g id="head|body|arms">` rendered + tested
  - `transform-box: fill-box` inline on each `<g>`
  - `role="img"` + static `aria-label` on root `<svg>`
  - IntersectionObserver + visibilitychange pause active
  - No per-frame aria-label mutation (state-leak guard test passes)
  - 14 silhouettes still render (sanity test for nova/architect/ghostline/sysadmin)
  - No `<title>` element

## Deviations from PLAN

1. **Test framework:** Plan suggested `@vue/test-utils` (`mount`). Project pattern (per `tests/unit/GlobalFeed.test.js` and others) does NOT use `@vue/test-utils` — switched to native `createApp` + `happy-dom`. Functionally equivalent for structural assertions, no new dep needed.
2. **Reactive data field naming:** Plan example used `_observer` / `_onVisibilityChange` / `_lastIntersecting` (underscore prefix). Vue ESLint rule `vue/no-reserved-keys` blocks underscore-prefixed `data()` keys. Renamed to `observer` / `onVisibilityChange` / `lastIntersecting` (no functional change — these are still imperative slots, not reactive state, but Vue doesn't enforce the underscore convention).
3. **Test 5 (aria-label state-leak):** Original example mounted 3 instances without unmount → Vue warned about app-already-mounted. Added explicit `mountedApp.unmount()` + DOM-reset between mounts. Functionality unchanged.

## Open Risks / Follow-ups

- `IntersectionObserver` not present in `happy-dom` → guarded with `typeof IntersectionObserver === 'undefined'` early-return. Tests don't exercise the pause path, only the structural invariants. If real-browser pause behavior needs E2E coverage, add a Playwright test in Phase 153.
- Existing `state`-based CSS animations (`ca-pulse`, `ca-shake`, `ca-bounce`, `ca-tilt`, `ca-gentle-bounce`) on the OUTER `.character-avatar` div are NOT affected by the IntersectionObserver pause (which only touches SVG sub-tree). This is acceptable — outer-div transitions are short-lived (200-800ms) and don't loop indefinitely.
- `setupVisibilityPause()` runs on every avatar mount; for views with many avatars (skill-map page) this could create N observers. Performance budget per ART_STYLE_GUIDE Section 4 is fine for current usage. If skill-map gains 50+ simultaneous avatars in v4.5.x, consolidate via shared root observer.

## Wave Status

Wave 2 complete. Wave 3 (`150-06` a11yStore + PersonalSettings + Backend) ready — depends on `setAnimationsEnabledGetter` from Plan 150-03 which is now committed.

---

*Plan 05 completed 2026-04-25, ~10 min wall-clock.*
