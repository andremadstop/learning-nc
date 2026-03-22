---
phase: 44-design-token-system-narrative-skin
plan: 02
subsystem: ui
tags: [css-skins, backdrop-filter, glass-effect, narrative-typography, paper-circuits]

requires:
  - phase: 44-design-token-system-narrative-skin
    plan: 01
    provides: "Global --lnc-* CSS token layer with dark/light scopes and motion utilities"
provides:
  - "Paper & Circuits narrative skin scoped under [data-lnc-skin='paper-circuits']"
  - "Glass panel effect with backdrop-filter on scene and NPC dialog"
  - "Dual typography: serif for narrative, sans-serif for system labels"
  - "Circuit line gradient decorator on scene cards"
affects: [45-charakter-system, 46-ui-komponenten, 47-kampagnen-integration]

tech-stack:
  added: []
  patterns: ["data-lnc-skin attribute for skin scoping", "backdrop-filter glass panel pattern", "Dual font-stack (narrative serif + system sans-serif)"]

key-files:
  created: []
  modified:
    - app/css/style.css
    - app/src/components/AbenteuerMode.vue

key-decisions:
  - "Mapped plan's generic class names to actual AbenteuerMode classes (ab-scene-inner, ab-npc-dialog, ab-choice-card, ab-skill-badge)"
  - "No Google Fonts import - Crimson Pro aspirational with Georgia/Times fallback"
  - "ab-scene-progress used as system text element alongside ab-skill-badge"

patterns-established:
  - "Skin scoping: [data-lnc-skin='name'] on component root for visual theme variants"
  - "Glass panels: rgba background + backdrop-filter blur + soft border"
  - "Circuit decorator: ::before pseudo-element with gradient line at top of cards"

requirements-completed: [DS-04]

duration: 3min
completed: 2026-03-22
---

# Phase 44 Plan 02: Paper & Circuits Narrative Skin Summary

**Paper & Circuits skin with glass panels (backdrop-filter), serif/sans-serif dual typography, circuit-line decorators, and cyan glow hover -- scoped exclusively to AbenteuerMode via data-lnc-skin attribute**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-22T21:40:22Z
- **Completed:** 2026-03-22T21:43:22Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- Added 10 CSS rule blocks for Paper & Circuits skin, all scoped under [data-lnc-skin="paper-circuits"]
- Glass panels with backdrop-filter blur on scene container (.ab-scene-inner) and NPC dialog (.ab-npc-dialog)
- Serif font-stack (Crimson Pro/Georgia/Times) for narrative and NPC text, sans-serif for system labels
- Circuit line gradient decorator (cyan-amber-cyan) on scene cards via ::before pseudo-element
- Cyan glow on choice card hover using existing --lnc-shadow-glow token
- Deployed and verified on learning-dev container

## Task Commits

Each task was committed atomically:

1. **Task 1: Paper & Circuits Skin CSS + AbenteuerMode Integration** - `14a700e` (feat)
2. **Task 2: Visual Verification** - auto-approved (deploy + automated check)

## Files Created/Modified
- `app/css/style.css` - Added "Narrative Skins" section with Paper & Circuits rules (10 selectors, ~55 lines)
- `app/src/components/AbenteuerMode.vue` - Added data-lnc-skin="paper-circuits" attribute on root element

## Decisions Made
- Adapted plan's class names to actual AbenteuerMode classes: .ab-scene-card -> .ab-scene-inner, .ab-dialogue-box -> .ab-npc-dialog, .ab-dialogue-text -> .ab-npc-text, .ab-choice-btn -> .ab-choice-card, .ab-skill-label -> .ab-skill-badge
- Used .ab-scene-progress as additional system text target (uppercase cyan label)
- Skipped .ab-system-msg and .ab-continue-indicator selectors as those classes don't exist in the component

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Adapted CSS selectors to actual class names**
- **Found during:** Task 1
- **Issue:** Plan referenced .ab-scene-card, .ab-dialogue-box, .ab-dialogue-text, .ab-choice-btn, .ab-skill-label which don't exist in AbenteuerMode.vue
- **Fix:** Used actual classes: .ab-scene-inner, .ab-npc-dialog, .ab-npc-text, .ab-choice-card, .ab-skill-badge
- **Files modified:** app/css/style.css
- **Verification:** All selectors match existing template elements
- **Committed in:** 14a700e

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** Selector mapping was necessary for skin to actually work. No scope creep.

## Issues Encountered
- CSS not deployed by deploy-dev.sh to container (only JS bundle was copied). Fixed by manual rsync + docker cp.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Paper & Circuits skin is live on AbenteuerMode
- Phase 44 complete - both plans (token layer + narrative skin) delivered
- Ready for Phase 45 (Charakter-System) which can build on the token + skin foundation

---
*Phase: 44-design-token-system-narrative-skin*
*Completed: 2026-03-22*
