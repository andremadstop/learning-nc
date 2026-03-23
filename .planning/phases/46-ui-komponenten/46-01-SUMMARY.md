---
phase: 46-ui-komponenten
plan: 01
subsystem: ui
tags: [vue, campaign-card, dialogue, character-avatar, design-tokens]

requires:
  - phase: 45-charakter-system
    provides: CharacterAvatar.vue component and characters.js registry
provides:
  - CampaignCard.vue — dark gradient campaign selection card with character portrait
  - DialogueStage.vue — NPC dialog presenter with portrait/emotion and narrator mode
affects: [47-integration, abenteuer-mode]

tech-stack:
  added: []
  patterns: [character-palette-driven inline styles, dual-mode component (dialog/narrator)]

key-files:
  created:
    - app/src/components/CampaignCard.vue
    - app/src/components/DialogueStage.vue
  modified: []

key-decisions:
  - "Emotion labels mapped to German inline (no i18n dep for internal labels)"
  - "Character glow color used as gradient accent corner on CampaignCard"

patterns-established:
  - "Palette-driven inline styles: use getCharacter().palette for dynamic color binding"
  - "Dual-mode components: isNarrator boolean switches template via v-if, not separate components"

requirements-completed: [UI-01, UI-02]

duration: 2min
completed: 2026-03-23
---

# Phase 46 Plan 01: UI Components Summary

**CampaignCard with dark gradient + CharacterAvatar portrait and DialogueStage with speaker/narrator dual mode**

## Performance

- **Duration:** 2 min
- **Started:** 2026-03-23T05:34:46Z
- **Completed:** 2026-03-23T05:36:19Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- CampaignCard.vue renders dark gradient cards with character portrait, difficulty badge, and focus area tags
- DialogueStage.vue presents NPC dialog with portrait + emotion tag pill or full-width narrator narrative box
- Both components use --lnc-* design tokens and respect prefers-reduced-motion

## Task Commits

Each task was committed atomically:

1. **Task 1: CampaignCard.vue** - `aa7676a` (feat)
2. **Task 2: DialogueStage.vue** - `0be9899` (feat)

## Files Created/Modified
- `app/src/components/CampaignCard.vue` - Campaign selection card with dark gradient, CharacterAvatar, difficulty badge, @select event
- `app/src/components/DialogueStage.vue` - NPC dialog stage with portrait/emotion mode and full-width narrator mode

## Decisions Made
- Emotion labels mapped to German inline (ruhig, nachdenklich, erklaerend, etc.) — no i18n dependency needed for these internal labels
- Character glow color used as gradient accent corner on CampaignCard for visual identity tie-in

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Both components ready for integration into AbenteuerMode.vue
- CampaignCard emits @select for campaign selection flow
- DialogueStage accepts speakerId/emotion/isNarrator props matching existing campaign data shapes

---
*Phase: 46-ui-komponenten*
*Completed: 2026-03-23*
