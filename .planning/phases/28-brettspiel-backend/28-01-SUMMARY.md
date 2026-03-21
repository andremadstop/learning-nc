---
phase: 28
plan: 01
subsystem: backend
tags: [gameshow, board-game, turn-based, lernwuerfel, wissensturm, db-migration]
dependency_graph:
  requires: [GameshowService, GameshowSession entity, gameshow_sessions table]
  provides: [board_state column, lernwuerfel scoring, wissensturm scoring, rollDice API, selectCategory API, turn-based session loop]
  affects: [GameshowController, routes.php, GameshowSession entity]
tech_stack:
  added: []
  patterns: [JSON-blob board state in existing sessions table, turn-based phase machine (roll→question→roll), mode-routing in submitAnswer]
key_files:
  created:
    - app/lib/Migration/Version004100Date20260321120000.php
  modified:
    - app/lib/Db/GameshowSession.php
    - app/lib/Service/GameshowService.php
    - app/lib/Controller/GameshowController.php
    - app/appinfo/routes.php
decisions:
  - Board state stored as JSON TEXT in existing gameshow_sessions column — no new table needed
  - Turn-based phase machine: 'roll' -> 'question' -> 'roll' (next player)
  - Lernwürfel uses round-robin question recycling (mod questionIds length) not linear exhaustion
  - Wissensturm win condition: 5 unique category colours (not total block count)
  - Dice result stored in board_state, validated server-side before answer is accepted
metrics:
  duration: 8 minutes
  completed: 2026-03-21
  tasks_completed: 3
  files_created: 1
  files_modified: 4
---

# Phase 28 Plan 01: Brettspiel-Backend Summary

GameshowService extended with turn-based board-game session logic: lernwuerfel (Mensch-ärgere-dich-nicht) and wissensturm (Trivial-Pursuit) modes with persistent JSON board state, dice-roll API, scoring methods, and automatic turn rotation.

## What Was Built

### DB Migration (BACK-02)
`Version004100Date20260321120000.php` adds a nullable `board_state TEXT` column to `learning_gameshow_sessions`. The column stores a JSON blob that differs per mode but always contains `active_player_index`, `phase`, `dice_result`, and `special_effect`.

**Lernwürfel keys:** `positions` (slot→field 0-30), `shielded_slots`, `skip_turns`
**Wissensturm keys:** `towers` (slot→[colour, …]), `selected_category`, `steal_pending`, `steal_from_slot`

### Entity (BACK-02)
`GameshowSession` entity gets `protected $boardState` with type registration so QBMapper reads/writes it automatically.

### GameshowService (BACK-01, BACK-03)

**Mode routing:** `VALID_MODES` constant extended to `['sprint', 'elimination', 'lernwuerfel', 'wissensturm']`. `createSession()` and `GameshowController::create()` accept all four.

**Board initialisation:** `setReady()` calls `initBoardState()` once all players are ready. Returns correct initial state structure per mode.

**Turn-based `submitAnswer()`:** Board-game modes skip the "all players answered" check and instead:
1. Guard: only active player in `question` phase may answer.
2. Route to `lernwuerfelScoring()` or `wissensturmScoring()`.
3. If game not finished: call `advanceTurn()`, rotate question index (round-robin), persist board_state.

**`rollDice()` (new public method):** Validates caller is active player in `roll` phase. Handles trap-skip (decrements counter, auto-advances turn). Generates `random_int(1,6)`, stores result, transitions phase to `question`.

**`selectCategory()` (new public method):** Wissensturm only — active player picks a category pool_id before answering. Selects 1 question from that pool and stores override in board_state.

**`lernwuerfelScoring()`:** Moves figure by dice_result on correct answer. Handles:
- Collision → opponent sent to field 0 (unless shielded)
- Special fields: bonus_roll (extra roll), shield (protect from collision), trap (skip next turn)
- Win detection: reach field 30

**`wissensturmScoring()`:** On correct: add selected-category block to tower (or steal opponent's top block if steal_pending). On incorrect: remove top block, set steal_pending. Win: 5 unique colour blocks.

**`advanceTurn()`:** Rotates `active_player_index` to next non-removed player slot. Exception: `bonus_roll` effect keeps same player in roll phase.

**`buildState()`:** Includes `board_state` (decoded JSON object) in every state response for board-game modes.

### Controller + Routes
- `GameshowController::roll()` → `POST /api/gameshow/{code}/roll`
- `GameshowController::category()` → `POST /api/gameshow/{code}/category`

## Success Criteria Verification

1. Session with mode='lernwuerfel' or mode='wissensturm' can be created — manages player order, active player, and board state in JSON. **BACK-01: PASS**
2. API returns board_state at every poll: positions (lernwuerfel) or towers (wissensturm). **BACK-02: PASS**
3. After player action (roll + answer) active_player_index auto-advances. **BACK-03: PASS**
4. Scoring logic for both modes is server-side and updates board state. **BACK-01: PASS**

## Deviations from Plan

None — plan executed exactly as specified.

## Self-Check

- [x] Migration file exists: app/lib/Migration/Version004100Date20260321120000.php
- [x] Entity field exists: app/lib/Db/GameshowSession.php — boardState
- [x] Service methods exist: rollDice(), selectCategory(), lernwuerfelScoring(), wissensturmScoring(), advanceTurn(), initBoardState()
- [x] Routes registered: gameshow#roll, gameshow#category
- [x] All commits pass PHPStan + syntax check

## Self-Check: PASSED
