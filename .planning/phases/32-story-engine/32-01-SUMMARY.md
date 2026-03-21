---
phase: 32
plan: 01
subsystem: story-engine
tags: [backend, php, story-rpg, campaign, skill-check, progress]
dependency_graph:
  requires: [phase-31-wissensturm, learning_questions, learning_answers, learning_pools]
  provides: [story_engine_api, story_progress_db, campaign_json_schema]
  affects: [routes.php, Application.php DI, appinfo]
tech_stack:
  added: [StoryEngineService, StoryController, StoryProgress, StoryProgressMapper, Version004200]
  patterns: [QBMapper, NC Controller pattern, UserRateLimit attributes, campaign JSON schema]
key_files:
  created:
    - app/lib/Migration/Version004200Date20260321200000.php
    - app/lib/Db/StoryProgress.php
    - app/lib/Db/StoryProgressMapper.php
    - app/data/campaigns/grosser_ausfall.json
    - app/lib/Service/StoryEngineService.php
    - app/lib/Controller/StoryController.php
  modified:
    - app/appinfo/routes.php
decisions:
  - Campaign JSON validated structurally on load — all scene next-pointers checked, malformed JSON returns 500 not 500 panic
  - pool_filter implemented as LIKE match on pool name/description — no extra DB column needed for Phase 32
  - Character difficulty modifier implemented as random-result offset slice, not separate query — pragmatic for Phase 32
  - campaignId validated with strict regex [a-z0-9_\-]{1,64} to prevent path traversal
  - Coop user IDs stored as JSON array in single column — sufficient for Phase 32 voting (Phase 33 implements majority logic in frontend)
  - skill_check questions pre-loaded in scene response to avoid extra round-trip from frontend
  - Campaign dir resolved with dual-path fallback (repo layout vs container layout)
metrics:
  duration: 89 minutes
  completed: 2026-03-21
  tasks_completed: 4
  files_created: 6
  files_modified: 1
---

# Phase 32 Plan 01: Story-Engine Backend Summary

**One-liner:** PHP Story-RPG backend with campaign JSON loader, skill-check engine, branching scene graph, and persistent per-user progress — 8 REST endpoints serving the Abenteuer v6.0 frontend.

## What Was Built

### DB Layer (Task 1)
- **Version004200** migration creates `learning_story_progress` table with 10 columns: user_id, campaign_id, current_scene_id, character_class, choices_json, score, status, coop_user_ids, created_at, updated_at
- Indexes on `(user_id, campaign_id)` and `(user_id, status)` for efficient lookup
- **StoryProgress** entity with `getChoicesDecoded()` and `getCoopUserIdsDecoded()` helpers
- **StoryProgressMapper** with `findByUserAndCampaign`, `findAllByUser`, `findInProgressByUser`, `findByCampaignAndStatus`

### Campaign JSON (Task 2)
- **grosser_ausfall.json** — complete Campaign 1 "Der große Ausfall" with:
  - 8 scenes: intro, 2 routing branches, VLAN scene (with simulation marker), wireless, finale (with simulation marker), 2 epilogs
  - 3 NPCs: dr_weber, jens_bug, nova with emoji avatars
  - 4 character class definitions with skill_bonus/penalty pool arrays
  - Per-choice `character_adjustments` with difficulty_modifier (-1/0/+1)
  - Success/partial/fail branch targets for every choice
  - `pool_filter` fields: routing, troubleshooting, switching, vlan, wireless, security

### Service Layer (Task 3)
- **StoryEngineService** (~400 lines) with:
  - `listCampaigns()` — glob scan of `app/data/campaigns/*.json`
  - `loadCampaign(id)` — load + structural validation (missing fields, dead scene references)
  - `startCampaign()` — create/reset progress record, return first scene
  - `getScene()` — return current scene with pre-loaded skill-check questions
  - `makeChoice()` — process choice with optional inline single-question evaluation
  - `submitSkillAnswer()` — evaluate single answer, advance to next scene
  - `submitSkillBatch()` — evaluate full question batch, apply pass_threshold, determine success/partial/fail outcome
  - `getSkillCheckQuestions()` — fetch pool-filtered questions with character difficulty offset
  - `listUserProgress()` — all user campaigns with campaign title enrichment
  - `getCharacterClass()` — read character class from active progress
  - `evaluateSingleAnswer()` — check answer correctness + return correct_answer_id for feedback
  - `buildSceneResponse()` — strip internal routing fields, pre-load questions, resolve NPC meta
  - campaignId path-traversal guard via strict regex

### Controller + Routes (Task 4)
- **StoryController** with 8 endpoints, all `@NoAdminRequired` + `UserRateLimit`:
  - `GET  /api/story/campaigns` (30/min)
  - `GET  /api/story/progress` (30/min)
  - `POST /api/story/campaigns/{id}/start` (10/min — restart guard)
  - `GET  /api/story/campaigns/{id}/scene` (60/min)
  - `GET  /api/story/campaigns/{id}/scene/{sceneId}/questions/{choiceId}` (60/min)
  - `POST /api/story/campaigns/{id}/choice` (30/min)
  - `POST /api/story/campaigns/{id}/answer` (30/min)
  - `POST /api/story/campaigns/{id}/batch` (20/min)
- Input validation: batch size ≤20, JSON decode check, null userId guard → 401

## Deviations from Plan

### Auto-added: `listUserProgress()` and `getCharacterClass()` on service (Rule 2)
- **Found during:** Task 4 (controller implementation)
- **Issue:** Controller called two service methods not defined in the plan
- **Fix:** Added both methods to StoryEngineService before committing controller
- **Files modified:** app/lib/Service/StoryEngineService.php

No other deviations — plan executed as designed.

## Self-Check: PASSED

All 6 created files confirmed present on disk. All 4 task commits verified in git log:
- `4ec0e0e` feat(32-01): DB migration + entity + mapper
- `18caa68` feat(32-02): campaign JSON grosser_ausfall
- `520f4dc` feat(32-03): StoryEngineService
- `a7b61d9` feat(32-04): StoryController + routes + service helpers
