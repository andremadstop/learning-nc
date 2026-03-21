---
phase: 31
plan: 01
subsystem: frontend
tags: [wissensturm, gameshow, board-game, vue, multiplayer]
dependency_graph:
  requires: [phase-28-brettspiel-backend, phase-29-oldschool-menu]
  provides: [wissensturm-ui, trivial-pursuit-mode]
  affects: [CourseDetail.vue, OldschoolSelector.vue]
tech_stack:
  added: []
  patterns: [short-polling, canvas-confetti, prefers-reduced-motion, css-keyframes]
key_files:
  created:
    - app/src/components/WissensturmMode.vue
  modified:
    - app/src/components/CourseDetail.vue
decisions:
  - Auto-roll dice then select category in single UX step (roll is a backend requirement but invisible to user)
  - Pool cycling: if fewer than 5 pools, cycle through them for all 5 color slots
  - Poll interval 1000ms (vs 500ms in GameshowMode) — board-game turns are slower, reduces server load
  - Block fall animation scoped to feedback phase via CSS descendant selector to avoid reanimating on poll
metrics:
  duration: 310s
  completed: 2026-03-21
  tasks_completed: 1
  files_created: 1
  files_modified: 1
---

# Phase 31 Plan 01: Wissensturm Summary

WissensturmMode.vue implements a Trivial Pursuit–style tower-building multiplayer game where players pick categories, answer questions, and stack colored blocks — first to collect all 5 unique colors wins.

## What Was Built

**WissensturmMode.vue** (~700 LOC, Vue 2.7 with `<style scoped>`):

Phases: `join → lobby → game → feedback → finished | expired`

- **Join phase**: Pool/max-players selection, join-by-code input, session recovery via `localStorage`
- **Lobby phase**: Code sharing, ready system, short polling
- **Game phase**:
  - Tower visualization — 5 colored blocks per player stacked bottom-to-top using `flex-column-reverse`; empty slots shown as dashed placeholders to illustrate goal height
  - 5 category buttons (colors: `#2196f3 #e53935 #4caf50 #ffc107 #9c27b0`) mapped to first 5 course pools with cycling if fewer pools available
  - Active player badge + turn indicator; non-active players see a loading spinner
  - Category select triggers `POST /api/gameshow/{code}/roll` then `POST /api/gameshow/{code}/category` in sequence
  - Question rendered after category selection with colored category badge
  - Steal notification banner when `steal_pending` flag is set in board_state
- **Feedback phase**: Correct/incorrect card with steal label; wrong answer shows block-fall note
- **Finished phase**: Winner card with trophy emoji, final tower state, confetti burst
- **Expired phase**: Disconnect message

**CSS animations** (both respect `prefers-reduced-motion`):
- `@keyframes block-slide-in` — new block drops from above into tower position
- `@keyframes block-fall` — losing block falls and rotates out (triggered in `.wt-feedback-wrong` via CSS descendant)

**CourseDetail.vue**: replaced placeholder `<div v-else-if="oldschoolSubMode === 'wissensturm'">` with `<WissensturmMode>` component, added import and component registration.

## API Calls Made

| Method | Endpoint | When |
|--------|----------|------|
| POST | `/api/gameshow` | Create session (mode=wissensturm) |
| POST | `/api/gameshow/{code}/join` | Join by code |
| POST | `/api/gameshow/{code}/ready` | Mark ready |
| GET | `/api/gameshow/{code}/state` | Poll (1s interval) |
| POST | `/api/gameshow/{code}/roll` | Advance phase to 'question' |
| POST | `/api/gameshow/{code}/category` | Select pool + load question |
| POST | `/api/gameshow/{code}/answer` | Submit answer |

All endpoints existed from Phase 28 backend — no new backend code needed.

## Deviations from Plan

**1. [Rule 2 - Missing Feature] Auto-roll in category selection**
- **Found during:** Task 1, implementing onSelectCategory
- **Issue:** Backend requires `rollDice` call to advance `board_state.phase` from `'roll'` to `'question'` before `selectCategory` is valid; the spec said only 5 category buttons but didn't mention this backend constraint
- **Fix:** `onSelectCategory()` calls `POST /roll` first (invisibly), then `POST /category`; errors like "already rolled" or "question phase" are silently swallowed since they indicate the phase is already advanced
- **Files modified:** WissensturmMode.vue (`onSelectCategory` method)

None other — plan executed as specified otherwise.

## Self-Check: PASSED

- WissensturmMode.vue: FOUND at `app/src/components/WissensturmMode.vue`
- CourseDetail.vue wiring: FOUND (import + component registration + template replacement)
- Commit 338b7d4: FOUND in git log
