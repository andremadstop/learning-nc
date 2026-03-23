---
phase: 47-kampagnen-integration
plan: 01
subsystem: ui
tags: [vue, css-animation, svg, campaign-data, json, character-system]

requires:
  - phase: 45-charakter-system
    provides: characters.js registry with 13 figures and getCharacter API
  - phase: 46-ui-komponenten
    provides: CampaignCard and DialogueStage components with --lnc-* tokens
provides:
  - CampaignIntro.vue animation component for campaign start sequence
  - workplace_npcs data in 6 campaign JSONs mapping workplace figures to campaigns
affects: [47-02 AbenteuerMode wiring, campaign rendering, character integration]

tech-stack:
  added: []
  patterns: [campaign icon type mapping, workplace NPC data layer, CSS keyframe intro sequence]

key-files:
  created:
    - app/src/components/CampaignIntro.vue
  modified:
    - app/data/campaigns/solarwinds.json
    - app/data/campaigns/wannacry.json
    - app/data/campaigns/log4shell.json
    - app/data/campaigns/colonial_pipeline.json
    - app/data/campaigns/equifax.json
    - app/data/campaigns/a_plus_erster_tag.json

key-decisions:
  - "Used workplace_npcs field name instead of npcs to avoid collision with existing story NPC objects"
  - "Used actual characters.js IDs (sven_berater, klaus_dau, etc.) instead of plan shorthand names for getCharacter() compatibility"

patterns-established:
  - "Campaign icon type mapping: shield (security breach), terminal (exploit), server (infra), brain (AI), lock (threat)"
  - "workplace_npcs array pattern: [{character_id, role_in_story, default_emotion}] for workplace figure campaign assignments"

requirements-completed: [KI-01, KI-03]

duration: 4min
completed: 2026-03-23
---

# Phase 47 Plan 01: Kampagnen-Integration Summary

**CampaignIntro.vue with 5 SVG icon types and CSS keyframe intro sequence, plus workplace NPC assignments in 6 campaign JSONs**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-23T05:45:04Z
- **Completed:** 2026-03-23T05:49:00Z
- **Tasks:** 2
- **Files modified:** 7

## Accomplishments
- All 6 target campaign JSONs now have workplace_npcs arrays mapping characters.js figures to campaign-specific roles
- CampaignIntro.vue renders campaign-themed intro with SVG icon, title fade-in, difficulty badge (8KB, 307 lines)
- Full prefers-reduced-motion support with static fallback and 1s delayed @done emit

## Task Commits

Each task was committed atomically:

1. **Task 1: Add workplace NPC entries to 6 campaign JSONs** - `e78aa18` (feat)
2. **Task 2: Create CampaignIntro.vue animation component** - `6402e98` (feat)

## Files Created/Modified
- `app/src/components/CampaignIntro.vue` - Campaign intro animation with 5 SVG icon types, CSS keyframes, reduced-motion support
- `app/data/campaigns/solarwinds.json` - Added workplace_npcs: sven_berater as external security consultant
- `app/data/campaigns/wannacry.json` - Added workplace_npcs: klaus_dau as email attachment opener
- `app/data/campaigns/log4shell.json` - Added workplace_npcs: tim_azubi as vulnerable dependency introducer
- `app/data/campaigns/colonial_pipeline.json` - Added workplace_npcs: dr_hartmann as ransom-paying CEO
- `app/data/campaigns/equifax.json` - Added workplace_npcs: frau_weber as ignored vulnerability warner
- `app/data/campaigns/a_plus_erster_tag.json` - Added workplace_npcs: uschi as helpless-but-essential secretary

## Decisions Made
- **workplace_npcs instead of npcs**: All 13 campaign JSONs already have an `npcs` object with story-specific NPCs (e.g., dr_vasquez, marcus_chen). Adding a new array called `npcs` would overwrite this data. Used `workplace_npcs` to cleanly separate workplace character assignments from story NPCs.
- **Actual characters.js IDs**: Plan used shorthand names (berater, dau, azubi, chef, dsgvo) but characters.js defines `sven_berater`, `klaus_dau`, `tim_azubi`, `dr_hartmann`, `frau_weber`. Used real IDs for `getCharacter()` compatibility in CampaignIntro and AbenteuerMode.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Renamed npcs field to workplace_npcs to avoid data collision**
- **Found during:** Task 1 (Add workplace NPC entries)
- **Issue:** All campaign JSONs already have a top-level `npcs` object with story NPCs. Using the same key would overwrite existing campaign data.
- **Fix:** Used `workplace_npcs` as the field name for workplace character assignments
- **Files modified:** All 6 campaign JSONs
- **Verification:** Confirmed both `npcs` (story) and `workplace_npcs` (workplace figures) coexist in each file
- **Committed in:** e78aa18

**2. [Rule 1 - Bug] Corrected character_id values to match characters.js registry**
- **Found during:** Task 1 (Add workplace NPC entries)
- **Issue:** Plan specified shorthand IDs (berater, dau, azubi, chef, dsgvo) that don't exist in characters.js. `getCharacter('berater')` would return FALLBACK_CHARACTER.
- **Fix:** Used actual registry IDs: sven_berater, klaus_dau, tim_azubi, dr_hartmann, frau_weber, uschi
- **Files modified:** All 6 campaign JSONs
- **Verification:** All IDs match characters.js CHARACTERS object keys
- **Committed in:** e78aa18

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 bug)
**Impact on plan:** Both fixes necessary for data integrity and runtime correctness. No scope creep.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- CampaignIntro.vue ready for integration into AbenteuerMode (Plan 02)
- workplace_npcs data available for character rendering in campaign scenes
- Plan 02 should reference `workplace_npcs` field name (not `npcs`) when wiring character avatars

---
*Phase: 47-kampagnen-integration*
*Completed: 2026-03-23*
