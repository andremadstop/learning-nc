---
phase: 151-skin-picker-framework
plan: 01
status: completed
completed: 2026-04-25
---

# Plan 151-01 Summary — TDD-Init (Wave 0)

## Outcome

4 Vitest test files created with full per-requirement assertions, all in RED state. Wave 1+ implementers (Codex Plan 02/04/06, Claude Plan 03/05/07) read the test contract and make it green.

## Files Created

| Path | Test Count | RED Reason |
|------|-----------|-----------|
| `app/tests/unit/skinStore.test.js` | 9 | `Cannot find module '../../src/stores/skinStore.js'` |
| `app/tests/unit/SkinRenderer.test.js` | 5 | `Cannot find module '../../src/components/SkinRenderer.vue'` |
| `app/tests/unit/ProfLernAvatar.test.js` | 7 | `Cannot find module '../../src/components/ProfLernAvatar.vue'` |
| `app/tests/unit/characters.test.js` | 6 | Module resolves but META-01/02/03 + CLASSIC-03 assertions fail (fields don't exist yet, prof_lern_classic entry missing) |

## Verification

- ✅ All 4 files exist
- ✅ Vitest run: 4 files FAIL (expected RED). 5 assertion-fails in characters.test.js (3 import-resolved + 2 schema-derived) + 3 import errors for the other 3 = total 4 failed test files = 27 expected red checkpoints when implementations land
- ✅ ESLint: 0 errors across all 4 stub files
- ✅ No source files created (Wave 1+ owns implementation)

## Test Contract Encoded

- **PICK-03**: SkinRenderer dispatches nova → NovaDock, prof_lern_classic → ProfLernAvatar, valid char → CharacterAvatar
- **PICK-04**: `:key`-driven remount on skinStore.skinId change
- **PICK-05**: Allowlist coercion (skinStore + SkinRenderer fallback)
- **CLASSIC-01**: ProfLernAvatar.vue file existence (component name match)
- **CLASSIC-02**: book group, gaze translates pupils, gaze respects reduced-motion, wave auto-hides 1.2s, question mark, role+aria-label
- **CLASSIC-03**: characters.js has prof_lern_classic entry user_selectable:true category:'classic'
- **CLASSIC-04**: skinStore loadFromServerPayload defaults nova when key missing (read-path only)
- **META-01**: All entries have user_selectable + category + preview_thumbnail_svg fields
- **META-02**: Existing 12 entries unchanged in palette/silhouette
- **META-03**: availableSkins getter filters by user_selectable

## Next

Wave 1 ready (file-disjunct, parallelizable):
- 151-02 characters.js extension → Codex
- 151-04 VirtuProfController + test-api.sh → Codex
- 151-03 skinStore Pinia → Claude (this session, next step)

---

*Plan 01 completed 2026-04-25 ~5min wall-clock.*
