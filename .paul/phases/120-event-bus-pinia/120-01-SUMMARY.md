# Phase 120 Summary — Event-Bus to Pinia

## Outcome
- Installed `pinia@2.3.1` for Vue 2.7 compatibility and registered Pinia in `app/src/main.js`.
- Added `app/src/stores/virtuProfStore.js` and `app/src/stores/courseStore.js`.
- Replaced all `$root.$emit/$on/$off` usage in `app/src/` with store actions and reactive watchers.
- Updated `app/tests/unit/CourseDetail.test.js` to assert Pinia-based tab sync instead of the removed root event bus.

## Verification
- `rg -n '\\$root\\.\\$emit|\\$root\\.\\$on|\\$root\\.\\$off' app/src --glob '*.vue' --glob '*.js'` → 0 matches
- `npm run lint` → 0 errors, 19 existing warnings
- `npm run test -- --run` → 748 passed
- `npm run build` → success, postbuild checks passed
- `git diff --check` → clean

## Notes
- `pinia@latest` is Vue-3-only; Phase 120 pins `pinia@2.3.1`.
- Optional store accessors were added so unit tests and direct method calls do not require a booted app instance with active Pinia.
