---
phase: 152-three-archetype-presets
plan: 05
subsystem: ui
tags: [vue, svg, character-avatar, archetype, popularisierer, kosmos-projektion, radial-gradient]

# Dependency graph
requires:
  - phase: 152
    provides: "<g id='powerEffect'> shared overlay group + region-aware feature partitioning (headFeatures / armsFeatures / powerFeatures) from Plan 03; :class='el.class || null' element dispatch on all 12 dispatch sites from Plan 04"
  - phase: 152
    provides: "characters.js popularisierer entry (silhouette: 'popularisierer', palette { primary: magenta, accent: warning, glow: magenta }, name: 'Der Astrophysik-Popularisierer') from Plan 02"
  - phase: 149
    provides: "ART_STYLE_GUIDE.md Section 2.3 (Comic-Superhero Popularisierer rules + No-Go list) + scripts/check-forbidden-names.sh CI gate"
provides:
  - "case 'popularisierer': in CharacterAvatar.vue featureElements switch (~21 elements: 6 head + 9 arms + 6 power)"
  - "<defs> block at top of SVG with <radialGradient id='popularisierer-projection-gradient'> (magenta 0.9 -> 0.4 -> 0 fade)"
  - "popularisierer: 0.34 width entry in bodyPath() widths table"
  - "Smoke test extension: tests/unit/CharacterAvatar.test.js it.each now covers 7 silhouette IDs"
affects:
  - 152-06 (sensitivity-review SIGNOFF + close-out — popularisierer SVG now exists for review)
  - 153 (release-and-l10n — App Store description can reference 3 working scholar archetypes)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "SVG <defs> + radialGradient for Power-Element gradient fill (referenced via fill='url(#id)' from element data) — pattern available for future archetypes needing complex gradient fills"
    - "Standing-pose archetype reuses default trapezoid bodyPath (no special-case branch needed) — only kosmologe required empty path; theoretiker + popularisierer share the default"

key-files:
  modified:
    - "app/src/components/CharacterAvatar.vue (+46 LOC: 12 defs block + 1 width entry + 33 case body / -1 line tweak)"
    - "app/tests/unit/CharacterAvatar.test.js (+1 char: 'popularisierer' appended to it.each tuple)"

key-decisions:
  - "[152-05] <defs> block placed AFTER <svg> open tag and BEFORE <g id='body'> — SVG forward-references require defs first; advisor-confirmed insertion point"
  - "[152-05] popularisierer case inserted AFTER theoretiker (line 522) and BEFORE default: — keeps the awk verify range terminating at 'default:' rather than another scholar"
  - "[152-05] 21 elements (6 head + 9 arms + 6 power) shipped as recommended by the plan — no padding to reach 'about 20', no truncation"
  - "[152-05] Vest pattern is 5 scattered circles with pal.accent — generic dots, NOT a recognizable signature pattern (Section 2.3 line 128)"
  - "[152-05] Kosmos-Projektion uses radialGradient with --lnc-magenta only — avoids the --lnc-warning :root cascade question flagged in 152-02 (warning is only used as accent for star highlights, where pal.accent already resolves through the existing characters.js cascade)"
  - "[152-05] No special-case bodyPath branch for popularisierer — default trapezoid with width 0.34 (vest gives broader silhouette than nova/theoretiker) handles standing pose correctly"

patterns-established:
  - "Region-tagged power elements pattern (Plan 03) extends seamlessly to gradient-fill power elements (Plan 05) — element-data fill: 'url(#id)' propagates to SVG via existing dispatch sites without template changes"
  - "Comic-Superhero stylization recipe (Section 2.3): generic features (kinnbart triangle), scattered accents (5 dots), palette-driven contrast (magenta/violet/gold), arms-open Q-curves — together avoid both real-person likeness AND racial-exaggeration"

requirements-completed:
  - SCHOLAR-03

# Metrics
duration: 5min
completed: 2026-04-25
---

# Phase 152 Plan 05: Popularisierer Silhouette Summary

**Popularisierer silhouette case in CharacterAvatar.vue — kinnbart + magenta vest with generic star-pattern accents + Kosmos-Projektion (radialGradient circle + 5 star highlights, region: 'power'), plus inline `<defs>` block exposing `popularisierer-projection-gradient` for the Mini-Galaxie center fill.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-04-25T12:42:04Z
- **Completed:** 2026-04-25T12:47:32Z
- **Tasks:** 2 (both type="auto" tdd="true")
- **Files modified:** 2 source files (CharacterAvatar.vue, CharacterAvatar.test.js)

## Accomplishments

- Popularisierer silhouette renders without error: 21 elements split across 3 region-tagged groups (6 head / 9 arms / 6 power)
- Inline `<defs>` block with `<radialGradient id="popularisierer-projection-gradient">` shipped — magenta center 0.9 -> 60% 0.4 -> 100% transparent, referenced via `fill='url(#popularisierer-projection-gradient)'` on the Kosmos-Projektion center circle
- Smoke test extension: `it.each` in CharacterAvatar.test.js now exercises 7 silhouette IDs (`nova`, `architect`, `ghostline`, `sysadmin`, `theoretiker`, `kosmologe`, `popularisierer`) — every Phase 152 archetype now mount-tested
- ART_STYLE_GUIDE Section 2.3 compliance verified end-to-end via 5 independent gates (forbidden-names CI, No-Go awk-grep, `<text>` grep, ESLint, vite build)

## Task Commits

1. **Task 1: `<defs>` block + popularisierer width entry** — `49d8d29` (feat)
2. **Task 2: `case 'popularisierer':` ~21 elements + smoke test extension** — `4ad3d89` (feat)

**Plan metadata commit:** pending (this SUMMARY + STATE + ROADMAP final commit)

## Files Created/Modified

- `app/src/components/CharacterAvatar.vue` — `<defs>` radialGradient + `popularisierer: 0.34` width + `case 'popularisierer':` body (kinnbart triangle, mouth Q-curve, 2 eye dots, 2 eyebrow lines, vest rect, collar V, 5 star-pattern dots, 2 open-arm Q-curves, Kosmos-Projektion gradient circle, 5 star highlights with region: 'power')
- `app/tests/unit/CharacterAvatar.test.js` — `it.each` tuple extended with `'popularisierer'` (now 7 IDs covered)

## Decisions Made

- **Insertion order for popularisierer case:** placed AFTER theoretiker, BEFORE default — keeps awk verify range correct.
- **`<defs>` placement:** between `<svg>` open and `<g id="body">` — SVG forward-reference requirement, harmless DOM for the other 14 silhouettes (no fill='url(#...)' references outside popularisierer).
- **Element count:** 21 (6 head + 9 arms + 6 power) — the plan's recommended layout summed to exactly this; no padding, no truncation.
- **No special-case bodyPath branch:** popularisierer uses the default trapezoid via width 0.34 (broader than theoretiker 0.32 due to vest) — the vest rect overlays the trapezoid, exactly as the plan specified.
- **`pal.accent` for star highlights:** characters.js maps popularisierer.accent to `var(--lnc-warning)`. Star highlights inherit this via the existing dispatch — no need to introduce `--lnc-warning` into the new `<defs>` (the gradient uses `--lnc-magenta` only, dodging the cascade question flagged in 152-02 STATE).

## Deviations from Plan

None — plan executed exactly as written. The recommended layout (~20 elements) was followed verbatim with the natural 21-element count (6 head + 9 arms + 6 power, including 5 vest dots + 5 star highlights as specified).

## Issues Encountered

- **Inline structural-sanity test (one-off):** attempted to add an inline vitest file at `tests/unit/_pop-check.tmp.test.js` for a richer mount-time DOM assertion (head/arms/power child counts, gradient referenced check). Vite's test transform failed on the underscore-prefixed temp path. Cleaned up immediately. The existing `it.each` smoke test already covers mount + `g#body path` non-null, and the structural guarantees come from the deterministic region partitioning (Plan 03) — no test loss. Recorded for future: keep ad-hoc tests in canonical test naming only.
- **GitNexus index stale notification (twice):** PostToolUse hook fired after both commits. Deferred re-index to a single run after the final SUMMARY/STATE commit.

## Verification Evidence

| Gate | Result |
|------|--------|
| Vitest (CharacterAvatar + scholarArchetypes + SkinRenderer.scholars) | **21 / 21 passed** (was 20/20 before; +1 new popularisierer smoke) |
| ESLint on CharacterAvatar.vue | **exit 0** |
| `bash scripts/check-forbidden-names.sh` (LEGAL-02 CI) | **exit 0** |
| `awk` Section 2.3 No-Go grep over popularisierer case (`Neil\|deGrasse\|Tyson\|StarTalk\|Cosmos.*show\|Cosmos.*logo\|signature.*vest\|chest.stripe\|three.stripe`) | **exit 1** (no hits) |
| `<text>` element grep in popularisierer case | **exit 1** (no hits — Pitfall 6 prevented structurally) |
| `grep "url(#popularisierer-projection-gradient)"` on CharacterAvatar.vue | **present in popularisierer Kosmos-Projektion circle** |
| `grep '<defs>'` + `grep 'id="popularisierer-projection-gradient"'` | both **present** |
| `npm run build` (vite) | **built in 267ms** + postbuild build-checks passed |

## Section 2.3 Self-Check

- [x] No `<text>` element with letter content (Pitfall 6 — no name-cue, no readable show-title)
- [x] No Cosmos/Show-logo references (No-Go line 127)
- [x] Vest pattern is 5 scattered generic dots, NOT a recognizable signature pattern (No-Go line 128)
- [x] Palette is magenta/violet/gold-driven, NOT skin-tone-driven (No-Go line 129)
- [x] Pose is standing/arms-open (2 outward Q-curve arm paths confirm — No-Go line 123 "kein hinter-Podium")
- [x] No "Neil"/"deGrasse"/"Tyson" tokens anywhere in popularisierer case (forbidden-names CI exit 0 confirms)
- [x] Generic kinnbart (3-point triangle) — not a recognizable real-person beard shape (No-Go line 105)
- [x] Comic-saturated palette per Section 2.3 line 118 (magenta primary, magenta glow, accent through pal.accent — Comic-Book-Stil, NOT exaggerated-realistic)

## Phase 152 Silhouette LOC Delta

| Plan | Commits | CharacterAvatar.vue change |
|------|---------|----------------------------|
| 03 | 8cb5c27 + cb46f8a | shared powerEffect group + theoretiker case (~+90 LOC) |
| 04 | bb4bd92 + 346b9b0 | dispatch :class extension + kosmologe case + thruster keyframe (~+97 LOC) |
| 05 | 49d8d29 + 4ad3d89 | <defs> + width + popularisierer case (+46 LOC) |
| **Total** | 6 commits | **520 -> 739 lines = +219 LOC net** in CharacterAvatar.vue across Phase 152 silhouette plans |

## Next Phase Readiness

- All three scholar archetypes (Theoretiker / Kosmologe / Popularisierer) now have working silhouettes in CharacterAvatar.vue. SVG-Freeze gate is unblocked from the **artifact** side.
- **Plan 06** (sensitivity-review + close-out) is the only remaining plan in Phase 152. It does NOT modify CharacterAvatar.vue — only writes new test files (scholarAnimations + scholarSvgSecurity already scaffolded in commit a9f00de) and produces `.planning/sensitivity-review/SIGNOFF.md`. CharacterAvatar.vue is now effectively LOCKED for the rest of v4.4.0.
- **Sensitivity-Review-Gate** (SVG-Freeze hard blocker) still requires manual review per ART_STYLE_GUIDE Section 5 — Plan 06 carries the human-checkpoint for this.

---
*Phase: 152-three-archetype-presets*
*Completed: 2026-04-25*

## Self-Check: PASSED

- [x] FOUND: `.planning/phases/152-three-archetype-presets/152-05-SUMMARY.md`
- [x] FOUND: `app/src/components/CharacterAvatar.vue`
- [x] FOUND: `app/tests/unit/CharacterAvatar.test.js`
- [x] FOUND commit `49d8d29` (Task 1: defs + width)
- [x] FOUND commit `4ad3d89` (Task 2: case popularisierer)
