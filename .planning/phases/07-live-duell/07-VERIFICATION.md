---
phase: 07-live-duell
verified: 2026-03-18T12:00:00Z
status: human_needed
score: 9/10 must-haves verified
human_verification:
  - test: "Open http://learning-dev:8080, navigate to a course, click the 'Duell' tab. In a second browser window (incognito, different user), open the same course and click 'Duell'. Window 1: select a pool and create a duel. Window 2: enter the code and join. Both click 'Bereit!'. Play through 10 questions. Verify scores update correctly. Click Rematch."
    expected: "Full flow completes — create, join, ready, 10 questions with Wahr/Falsch buttons, per-question feedback showing exact points (+4/+3/+2/0/-1), final results screen, rematch starts new lobby."
    why_human: "Real-time short-polling behavior, UI phase transitions, and scoring display require two authenticated users in a live browser. Cannot verify setInterval/setTimeout behavior or exact point display programmatically."
  - test: "With 5/15/20 questions selected in the numQuestions dropdown, verify the duel ends after the correct number of questions and the progress bar shows the correct denominator."
    expected: "Duel ends after exactly 5 (or 15 or 20) questions. Progress bar shows e.g. '3 / 5'."
    why_human: "applyScoring hard-codes >= 10 and buildState hard-codes total_questions: 10 — non-default numQuestions may cause premature end or incorrect progress display. Needs live verification."
---

# Phase 7: Live-Duell Verification Report

**Phase Goal:** Zwei Benutzer können ein Echtzeit-Duell im Wahr/Falsch-Stil starten — 10 Fragen aus dem aktiven Pool, Steal-Mechanik für Punkte, eigener Nav-Eintrag.
**Verified:** 2026-03-18
**Status:** human_needed
**Re-verification:** No — initial verification

## Navigation Placement Note

The task prompt documents an intentional deviation: the "Duell" nav entry moved from App.vue top-nav to CourseDetail.vue sub-nav tabs (alongside Pools / Mein Fortschritt / Leaderboard). The Plan 03 SUMMARY claims App.vue was modified with a Duell tab, but the actual App.vue has no DuelMode import or nav button. The feature IS accessible via CourseDetail.vue for both instructors and students. This verification treats the CourseDetail placement as satisfying DUEL-05.

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | POST /api/duels creates a duel session and returns a 6-char code | VERIFIED | `DuelService::createDuel` generates code via `bin2hex(random_bytes(3))`, inserts session, returns state with code field |
| 2 | POST /api/duels/{code}/join associates opponent_uid, returns session state | VERIFIED | `DuelService::joinDuel` validates status='waiting', sets opponent_uid, updates status='ready' |
| 3 | POST /api/duels/{code}/ready flips creator_ready or opponent_ready | VERIFIED | `DuelService::setReady` sets correct flag, transitions to 'active' when both true |
| 4 | GET /api/duels/{code}/state returns full duel state for polling | VERIFIED | `DuelService::getState` updates last_poll, checks for timeout, returns full state including current_question |
| 5 | POST /api/duels/{code}/answer applies scoring matrix and advances question index | VERIFIED | `DuelService::applyScoring` implements exact matrix: +4/0, +3/+2, +3/+3, -1/-1 with 50ms tie threshold |
| 6 | POST /api/duels/{code}/rematch creates new session with new random questions | VERIFIED | `DuelService::rematch` creates new DuelSession with same pool/players, new code and questions |
| 7 | Duels with no poll activity >30s are marked expired; active player wins by forfeit | VERIFIED | `DuelService::getState` checks `$otherLastPoll < $cutoff` (30s), sets status='expired' |
| 8 | DuelMode renders all UI phases with Wahr/Falsch buttons | VERIFIED | `DuelMode.vue` has all 6 phases (join/lobby/question/feedback/finished/expired), Wahr/Falsch buttons call `onAnswer(true/false)` |
| 9 | Short polling runs every 500ms while duel active, stops on component destroy | VERIFIED | `startPolling()` uses `setInterval(this.pollState, 500)`, `destroyed()` calls `stopPolling()` |
| 10 | numQuestions selector (5/10/15/20) respected end-to-end | PARTIAL | Frontend passes `numQuestions` to backend, backend selects correct count — but `applyScoring` hard-codes `>= 10` and `buildState` hard-codes `total_questions: 10`. Non-default counts will end correctly (applyScoring checks array count in buildState) — but progress bar shows wrong denominator for non-10 counts. Needs human verification. |

**Score:** 9/10 truths verified (1 partial, needs human check)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/lib/Migration/Version002400Date20260318000000.php` | Creates oc_learning_duel_sessions table | VERIFIED | Full schema: 14 columns, unique index on code, idempotent hasTable guard |
| `app/lib/Migration/Version002500Date20260318000000.php` | Creates oc_learning_duel_answers table | VERIFIED | Full schema: 7 columns, composite index on duel_id+question_index |
| `app/lib/Db/DuelSession.php` | Entity for duel sessions | VERIFIED | Exists in app/lib/Db/ |
| `app/lib/Db/DuelSessionMapper.php` | QBMapper with findByCode + findExpiredActive | VERIFIED | Exists in app/lib/Db/ |
| `app/lib/Db/DuelAnswer.php` | Entity for duel answers | VERIFIED | Exists in app/lib/Db/ |
| `app/lib/Db/DuelAnswerMapper.php` | QBMapper with findByDuelAndQuestion | VERIFIED | Exists in app/lib/Db/ |
| `app/lib/Service/DuelService.php` | All duel business logic | VERIFIED | 371 lines, all 6 public methods (createDuel, joinDuel, setReady, getState, submitAnswer, rematch) implemented |
| `app/lib/Controller/DuelController.php` | HTTP layer for 6 endpoints | VERIFIED | 94 lines, all 6 methods with @NoAdminRequired, UserRateLimit attributes, 201/200/400 responses |
| `app/appinfo/routes.php` | 6 duel routes registered | VERIFIED | Lines 100-106: all 6 routes with correct verbs and URL patterns |
| `app/src/components/DuelMode.vue` | Full duel UI with all phases | VERIFIED | 1008 lines, all phases present, coursePools prop, numQuestions selector |
| `app/appinfo/info.xml` | Version 2.6.0 | VERIFIED | `<version>2.6.0</version>` at line 73 |
| `app/CHANGELOG.md` | v2.6.0 section | VERIFIED | Section present at line 5 with Live-Duell description |
| `app/src/App.vue` | Duell nav button + DuelMode import | NOT AS PLANNED | Plan 03 specified a top-level nav tab in App.vue. Actual App.vue has no DuelMode reference. DuelMode is instead wired via CourseDetail.vue (intentional deviation — see note above) |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `DuelController.php` | `DuelService.php` | DI constructor injection | VERIFIED | Constructor: `DuelService $service` injected, all methods delegate to `$this->service` |
| `DuelService.php` | `oc_learning_duel_sessions` | DuelSessionMapper QBMapper | VERIFIED | `$this->sessionMapper->findByCode()`, `insert()`, `update()` used throughout |
| `DuelService.php` | `oc_learning_duel_answers` | DuelAnswerMapper QBMapper | VERIFIED | `$this->answerMapper->findByDuelAndQuestion()`, `insert()`, `update()` used in submitAnswer |
| `routes.php` | `DuelController` | `duel#` prefix | VERIFIED | 6 routes with `duel#create`, `duel#join`, etc. registered |
| `CourseDetail.vue` | `DuelMode.vue` | import + v-if tab | VERIFIED | Line 593: `import DuelMode from './DuelMode.vue'`, line 475: `<DuelMode :coursePools="coursePools" @back="currentTab = 'pools'" />` |
| `DuelMode.vue` | `GET /api/duels/{code}/state` | setInterval 500ms polling | VERIFIED | `startPolling()` at line 466: `setInterval(this.pollState, 500)`, `pollState()` calls `axios.get(generateUrl('/apps/learning/api/duels/' + this.duelCode + '/state'))` |
| `DuelMode.vue` | `POST /api/duels/{code}/answer` | `onAnswer(correct)` method | VERIFIED | `onAnswer()` at line 397: `axios.post(generateUrl('/apps/learning/api/duels/' + this.duelCode + '/answer'), { answerCorrect: correct, answeredAt: Date.now() })` |
| `App.vue` | `DuelMode.vue` | main-nav Duell tab (plan spec) | NOT WIRED | App.vue was not modified to include DuelMode. DuelMode is reachable only via CourseDetail.vue. This is an intentional deviation per task notes. |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| DUEL-01 | 07-01, 07-03 | DB tables + 6 API endpoints | SATISFIED | Both migrations created, all 6 endpoints in DuelController wired via routes.php |
| DUEL-02 | 07-01, 07-02 | Scoring matrix (steal-mechanic) | SATISFIED | `applyScoring()` implements exact matrix: correct+first=+4, both correct=+3/+2, both wrong=-1, 50ms tie threshold |
| DUEL-03 | 07-02 | DuelMode.vue with all UI phases | SATISFIED | DuelMode.vue: join/lobby/question/feedback/finished/expired phases, Wahr/Falsch buttons, 500ms polling |
| DUEL-04 | 07-02, 07-03 | Short polling (500ms) + cleanup | SATISFIED | `startPolling()/stopPolling()` with setInterval/clearInterval, `destroyed()` hook cleans up |
| DUEL-05 | 07-03 | Own nav entry accessible to users | SATISFIED (deviation) | Moved from App.vue top-nav to CourseDetail.vue sub-nav tab "Duell" — visible to both instructors and students inside any course. Task prompt explicitly accepts this placement. |

Note: DUEL-01 through DUEL-05 are not defined in `.planning/REQUIREMENTS.md` (that file tracks the prior PBQ/OnVUE phase's requirements). These are phase-local requirements defined only in the PLAN frontmatter.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/lib/Service/DuelService.php` | 309 | `if ($nextIndex >= 10)` hard-coded constant | Warning | When numQuestions is 5/15/20, duel ends at wrong question count (always at index 10, not at actual question count). Affects feature correctness for non-default question counts. |
| `app/lib/Service/DuelService.php` | 341 | `'total_questions' => 10` hard-coded | Warning | Frontend progress bar always shows X/10 regardless of selected numQuestions. Misleading UI for 5/15/20-question duels. |
| `app/CHANGELOG.md` | 11 | "Duell navigation tab in App.vue for all authenticated users" | Info | CHANGELOG text is inaccurate — the tab is in CourseDetail.vue, not App.vue main nav. Minor documentation inaccuracy. |

### Human Verification Required

#### 1. End-to-End Duel Flow

**Test:** Open http://learning-dev:8080 in two browser windows as different users. Navigate to any course, click the "Duell" tab. Window 1: select pool, create duel, copy the 6-char code. Window 2: enter the code, click "Beitreten". Both click "Bereit!". Play through 10 questions clicking "Wahr" or "Falsch". Observe feedback phase (correct/wrong, exact points). Complete all 10 questions.

**Expected:** Results screen shows final scores, winner announcement works (Du hast gewonnen / Gegner gewinnt / Unentschieden), Rematch button creates new lobby. Back button closes DuelMode and returns to the Pools tab in CourseDetail.

**Why human:** Two-user real-time short-polling behavior cannot be verified statically.

#### 2. numQuestions Selector Correctness

**Test:** In the join phase, change the numQuestions dropdown to 5. Create a duel and play through it.

**Expected:** Duel ends after exactly 5 questions. Progress bar shows e.g. "3 / 5" (not "3 / 10").

**Why human:** Code has a known bug — `applyScoring` uses hard-coded `>= 10` and `buildState` returns hard-coded `total_questions: 10`. The applyScoring issue may cause the duel to run past 5 questions, stopping only at index 10. Actual behavior needs live confirmation.

#### 3. Scoring Matrix Verification

**Test:** In a live 2-user duel, deliberately test: (a) one user correct + other wrong, (b) both correct, (c) both wrong.

**Expected:** (a) correct user gets +4, wrong user gets 0; (b) faster user gets +3, slower gets +2; (c) both get -1. Points shown in feedback phase must match.

**Why human:** Scoring correctness depends on timestamp ordering from live browsers (Date.now()), not testable statically.

### Gaps Summary

No blocking gaps found — the core feature is fully implemented and wired. The navigation placement deviation (CourseDetail sub-nav vs App.vue top-nav) is documented and accepted per the task prompt.

Two warning-level issues exist in DuelService.php: `applyScoring` hard-codes `>= 10` and `buildState` hard-codes `total_questions: 10`. These only affect duels with non-default question counts (5/15/20). The default 10-question flow is unaffected. These should be fixed but are not blocking for the default use case.

All 5 DUEL requirements are satisfied. The backend (6 endpoints, scoring matrix, 30s timeout, rematch) and frontend (all 6 UI phases, 500ms polling, Wahr/Falsch buttons, feedback with exact points) are fully implemented and wired.

---

_Verified: 2026-03-18_
_Verifier: Claude (gsd-verifier)_
