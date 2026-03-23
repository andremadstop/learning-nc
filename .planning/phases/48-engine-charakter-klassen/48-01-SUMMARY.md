---
phase: 48-engine-charakter-klassen
plan: 01
subsystem: api
tags: [zeitreise, epochs, character-classes, museum, skill-check, qbmapper]

requires:
  - phase: 47-kampagnen-integration
    provides: StoryEngineService patterns, campaign infrastructure, characters.js dual-export
provides:
  - 7 epoch definitions (JS + PHP) with themeKey for CSS tokens
  - 4 character classes with epoch affinity system (bonus/neutral/penalty)
  - Museum facts for all 7 epochs (JS + PHP)
  - EpochProgress entity + mapper for persistence
  - DB migration for oc_learning_epoch_progress table
  - 8 REST API endpoints under /api/zeitreise/
  - HackThroughTimeService with skill-check + affinity logic
affects: [48-02-frontend, 49-epochen-themes, 50-kampagnen-retro, 51-kampagnen-modern]

tech-stack:
  added: []
  patterns: [epoch-affinity-modifier, parallel-js-php-data-definitions]

key-files:
  created:
    - app/data/epochs/epochs.js
    - app/data/epochs/museum.js
    - app/src/data/characterClasses.js
    - app/lib/Db/EpochProgress.php
    - app/lib/Db/EpochProgressMapper.php
    - app/lib/Migration/Version004500Date20260323000000.php
    - app/lib/Service/HackThroughTimeService.php
    - app/lib/Controller/HackThroughTimeController.php
  modified:
    - app/appinfo/routes.php

key-decisions:
  - "Parallel JS+PHP data definitions for epochs/museum/affinities — JS for frontend, PHP constants in service for backend"
  - "Affinity modifier as float (0.8/1.0/1.2) applied to question difficulty selection, pass threshold 3/5 for bonus vs 4/5 for neutral/penalty"
  - "poolFilter tag pattern epoch:ID for skill-check question filtering via pool name LIKE match"

patterns-established:
  - "Epoch affinity: getClassAffinity(classId, epochId) returns bonus/neutral/penalty, affinityModifier maps to 0.8/1.0/1.2"
  - "Zeitreise API prefix: /api/zeitreise/ with hackThroughTime# controller binding"

requirements-completed: [ENG-03, ENG-04, ENG-05, CHAR-01, CHAR-02]

duration: 6min
completed: 2026-03-23
---

# Phase 48 Plan 01: Engine + Charakter-Klassen Backend Summary

**7 epochs + 4 character classes with affinity system, museum facts, DB migration, and 8 REST endpoints under /api/zeitreise/**

## Performance

- **Duration:** 6 min
- **Started:** 2026-03-23T07:19:18Z
- **Completed:** 2026-03-23T07:25:22Z
- **Tasks:** 2
- **Files modified:** 9

## Accomplishments
- 7 IT-Security epochs defined in both JS (frontend) and PHP (backend) with consistent IDs and themeKeys
- 4 character classes (Phreaker, Script-Kiddie, Red Teamer, Quantum Defender) with epoch affinity system
- Museum facts with 3-5 historical events per epoch (28 facts total)
- 8 REST API endpoints for epoch listing, progress, museum, skill-check, and scoring
- DB migration with unique user+epoch index for progress persistence

## Task Commits

Each task was committed atomically:

1. **Task 1: Epoch data, character classes, museum facts, DB entity + migration** - `2767863` (feat)
2. **Task 2: HackThroughTimeService + Controller + Routes** - `9d5d773` (feat)

## Files Created/Modified
- `app/data/epochs/epochs.js` - 7 epoch definitions with metadata, themeKey, poolFilter
- `app/data/epochs/museum.js` - 28 historical facts across 7 epochs
- `app/src/data/characterClasses.js` - 4 classes with affinity/weak epoch mappings + getClassAffinity()
- `app/lib/Db/EpochProgress.php` - NC Entity with museum_viewed JSON helper
- `app/lib/Db/EpochProgressMapper.php` - QBMapper with findByUserAndEpoch, findAllByUser, findCompletedByUser
- `app/lib/Migration/Version004500Date20260323000000.php` - oc_learning_epoch_progress table with 2 indices
- `app/lib/Service/HackThroughTimeService.php` - 8 public methods + affinity logic + question fetching
- `app/lib/Controller/HackThroughTimeController.php` - 8 endpoints with auth checks + rate limits
- `app/appinfo/routes.php` - 8 new routes under /api/zeitreise/

## Decisions Made
- Parallel JS+PHP data definitions: epochs, museum facts, and class affinities are defined in both languages to avoid runtime cross-language calls
- Affinity modifier as float (0.8 bonus / 1.0 neutral / 1.2 penalty) controls question difficulty offset, while pass threshold is 3/5 for bonus vs 4/5 otherwise
- Pool filter uses epoch:ID tag pattern matched via LIKE against pool name/description (same approach as StoryEngineService)

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- All backend endpoints ready for frontend consumption (Plan 02: HackThroughTime.vue)
- epoch themeKey values ready for CSS token mapping (Phase 49)
- Museum facts and epoch definitions available for campaign JSON creation (Phases 50-51)

---
*Phase: 48-engine-charakter-klassen*
*Completed: 2026-03-23*
