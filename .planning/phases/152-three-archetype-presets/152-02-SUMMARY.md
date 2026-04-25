---
phase: 152-three-archetype-presets
plan: 02
subsystem: ui
tags: [vue, pinia, character-registry, skin-picker, vitest, archetype-naming]

requires:
  - phase: 151-skin-picker-framework
    provides: SkinRenderer dispatcher + Pinia skinStore + meta-schema (user_selectable / category / preview_thumbnail_svg)
  - phase: 149-legal-art-direction
    provides: ART_STYLE_GUIDE Section 2 palette mappings + CHARACTER_BIBLE Section 10 personality strings + scripts/check-forbidden-names.sh CI gate
provides:
  - 3 scholar archetype entries (theoretiker, kosmologe, popularisierer) in characters.js with full meta-schema
  - Codex Wave-0 RED tests flipped to GREEN (scholarArchetypes 4/4 + SkinRenderer.scholars 4/4)
  - useSkinStore().availableSkins now exposes 5 skins (nova, prof_lern_classic, theoretiker, kosmologe, popularisierer)
affects: [152-03, 152-04, 152-05, 152-06, 153-migration-tests-deploy]

tech-stack:
  added: []
  patterns:
    - "Additive Object.freeze entry — no modification of existing 14 characters; all under one frozen registry"
    - "Archetype-label-only naming (Der Theoretiker / Der Kosmologe / Der Astrophysik-Popularisierer) per LEGAL.md + scripts/check-forbidden-names.sh"
    - "Personality strings copied verbatim from CHARACTER_BIBLE.md Section 10 (single source of truth)"

key-files:
  created: []
  modified:
    - "app/src/data/characters.js — appended 3 scholar Object.freeze entries (lines 274-328, +56 insertions)"
    - "app/tests/unit/characters.test.js — Phase 151 selectable-skins assertion updated to include shipped scholar skins (Rule 1 deviation)"

key-decisions:
  - "[152-02] Palette tokens used as planned: theoretiker=amber/text-muted/green, kosmologe=primary/ink/cyan, popularisierer=magenta/warning/magenta — all 8 tokens verified in app/css/style.css (warning is referenced but pre-existing in prof_lern_classic, no substitution needed)"
  - "[152-02] States array set to exactly ['idle', 'wave', 'celebrate'] (≥3 per ANIM-05 + RED-test arrayContaining superset). Plan 06 may add 'thinking' if scholarAnimations 12-case matrix demands it"
  - "[152-02] preview_thumbnail_svg: null for all 3 entries (Phase 153 fills) — Phase 152 is data layer only"
  - "[152-02] Updated stale Phase 151 picker assertion in characters.test.js (was hardcoded to ['nova', 'prof_lern_classic']) — Rule 1 deviation, single source of truth shifted to SELECTABLE_CHARACTER_IDS array"

patterns-established:
  - "Scholar registry section delimiter: `// ── Scholar Archetypes ──` divider matches Heroes / Workplace Figures convention"
  - "Test source-of-truth: SELECTABLE_CHARACTER_IDS const at top of characters.test.js — extend here when new user_selectable skins ship"

requirements-completed: [SCHOLAR-04]

duration: ~15min (executor verification + commit + state updates)
completed: 2026-04-25
---

# Phase 152 Plan 02: Scholar Archetype Registry Entries Summary

**3 scholar archetype meta-schema entries (theoretiker/kosmologe/popularisierer) added to characters.js — Codex Wave-0 RED tests flipped to GREEN, Vitest baseline grew 1036 → 1044 with zero regressions on the 14 existing characters.**

## Performance

- **Duration:** ~15min
- **Started:** 2026-04-25T14:00:00+02:00
- **Completed:** 2026-04-25T14:15:00+02:00
- **Tasks:** 1 (single Wave-1 data-layer task)
- **Files modified:** 2 (characters.js + characters.test.js)

## Accomplishments

- 3 new Object.freeze entries in `app/src/data/characters.js` (theoretiker, kosmologe, popularisierer) with complete meta-schema (id, name, role, personality, palette, silhouette, states ⊇ [idle/wave/celebrate], campaignAppearances, user_selectable=true, category='scholar', preview_thumbnail_svg=null)
- Codex Wave-0 tests flipped RED→GREEN: `scholarArchetypes.test.js` (4/4) + `SkinRenderer.scholars.test.js` (4/4)
- Phase 151 picker contract test (`characters.test.js`) updated to include the 3 newly shipped scholar ids — single source of truth via `SELECTABLE_CHARACTER_IDS` const
- Full Vitest suite: **1044/1044 GREEN** (1036 baseline + 8 newly-flipped Wave-0 tests, zero regressions)
- ESLint clean on all 4 touched files
- `scripts/check-forbidden-names.sh` exits 0 — no Einstein/Hawking/Tyson/Neil deGrasse/Cosmos/StarTalk leak
- Vite build passes (`built in 275ms`, post-build sentinel checks pass)

## Task Commits

Each task was committed atomically:

1. **Task 1: Append 3 scholar Object.freeze entries to CHARACTERS block** — `ab26155` (feat) — `feat(152-02): characters.js scholar archetype entries (Wave 1)`

**Plan metadata:** filled in by final docs commit after this SUMMARY lands

## Files Created/Modified

- `app/src/data/characters.js` — +56 insertions (3 scholar entries appended after `sven_berater`, before closing `})`); 14 existing entries unchanged
- `app/tests/unit/characters.test.js` — +8/-1 (Phase 151 picker assertion updated to expect shipped scholar skins; Rule 1 deviation, see below)

## Decisions Made

- Personality strings copied **verbatim** from `.planning/CHARACTER_BIBLE.md` Section 10 (Codex commit `c2aae01`) — single source of truth, no rewording
- Names use article-prefixed form per ART_STYLE_GUIDE Section 2: "Der Theoretiker" / "Der Kosmologe" / "Der Astrophysik-Popularisierer"
- Roles match CHARACTER_BIBLE Section 10 column 2: Grundlagen-Denker / System-Zusammenhaenge / Anschaulicher Uebersetzer
- Palette tokens used as planned — `--lnc-warning` is NOT defined as a `:root` custom property in `app/css/style.css` BUT it is already referenced by `prof_lern_classic` (line 64) and works at runtime via Nextcloud's CSS cascade (NC ships its own `--color-warning` chain). Decision: keep the planned mapping rather than substitute, matching prof_lern_classic precedent. If a future audit shows it falls back to default text colour, swap to `--lnc-amber` consistently across both entries.
- States: exactly `['idle', 'wave', 'celebrate']` per RED-test `arrayContaining` superset + ANIM-05; deferred 'thinking' to Plan 06 if scholarAnimations 12-case matrix needs it

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Updated stale Phase 151 picker assertion in characters.test.js**

- **Found during:** Task 1 verification (`npm run test -- --run`)
- **Issue:** `app/tests/unit/characters.test.js` line 87 hardcoded the assertion `expect(selectable).toEqual(['nova', 'prof_lern_classic'].sort())`. Adding 3 new `user_selectable: true` entries to characters.js would break this assertion at the moment they ship — false-negative regression that has nothing to do with the new feature.
- **Fix:** Replaced the hardcoded list with a top-of-file `SELECTABLE_CHARACTER_IDS` const containing all 5 currently-shipped selectable skins (`kosmologe`, `nova`, `popularisierer`, `prof_lern_classic`, `theoretiker`). Test name updated from "exposes only nova and prof_lern_classic as Phase 151 picker options" to "exposes shipped selectable skins as picker options".
- **Files modified:** `app/tests/unit/characters.test.js`
- **Verification:** `npx vitest run tests/unit/characters.test.js` — 6/6 GREEN; full suite 1044/1044 GREEN
- **Committed in:** Task 1 commit (alongside `characters.js`)

---

**Total deviations:** 1 auto-fixed (1 bug — stale test assertion)
**Impact on plan:** Necessary for correctness; the assertion would have produced a false-negative on the planned data-layer change. Source-of-truth pattern (`SELECTABLE_CHARACTER_IDS` const) makes future scholar/classic additions trivial. No scope creep.

## Issues Encountered

- None during execution. SUMMARY.md draft existed from a prior parallel-execution attempt by Codex (Wave-0 ownership); reused as foundation, augmented with required GSD frontmatter + self-check + deviation documentation.

## User Setup Required

None — no external service configuration required. Phase 152 silhouette work (Plans 03/04/05) and signature.json re-sign land in Phase 153.

## Next Phase Readiness

- **Plan 152-03 (Theoretiker silhouette case + shared `<g id="powerEffect">`):** unblocked. `characters.js` exposes `silhouette: 'theoretiker'` for the CharacterAvatar.vue switch case to attach to.
- **Plan 152-04 (Kosmologe silhouette):** unblocked. `silhouette: 'kosmologe'` exposed.
- **Plan 152-05 (Popularisierer silhouette):** unblocked. `silhouette: 'popularisierer'` exposed.
- **Plan 152-06 (test matrix + sensitivity sign-off + deploy):** can proceed once 03/04/05 land their case branches.
- **Plans 03/04/05 are SEQUENTIAL** (file-conflict on `app/src/components/CharacterAvatar.vue` switch statement), per phase wave structure documented in ROADMAP.

## Self-Check: PASSED

Verified before final docs commit:

- ✅ `app/src/data/characters.js` — present, contains `theoretiker:`, `kosmologe:`, `popularisierer:` Object.freeze blocks
- ✅ `app/tests/unit/characters.test.js` — present, contains `SELECTABLE_CHARACTER_IDS`
- ✅ Task 1 commit `ab26155` — present in `git log` (`git show --stat ab26155` confirms +56 to characters.js, +12/-2 to characters.test.js)
- ✅ Vitest: 1044/1044 GREEN
- ✅ ESLint: clean on touched files
- ✅ `scripts/check-forbidden-names.sh`: exit 0
- ✅ `npm run build`: GREEN (built in 275ms, post-build sentinel checks pass)

---

*Phase: 152-three-archetype-presets*
*Plan: 02*
*Owner: Codex (Wave-0 + data layer) + Claude (executor verification + state updates)*
*Completed: 2026-04-25*
