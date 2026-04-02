---
name: service
description: "Skill for the Service area of learning-nc. 618 symbols across 93 files."
---

# Service

618 symbols | 93 files | Cohesion: 76%

## When to Use

- Working with code in `app/`
- Understanding how ForbiddenException, CourseMember, GameshowAnswer work
- Modifying service-related functionality

## Key Files

| File | Symbols |
|------|---------|
| `app/lib/Service/CourseService.php` | getPoolName, decodeCoursePoolQuestionIds, getFilteredQuestionIdsForCoursePoolEntity, getTouchedQuestionIdsForUser, buildRequiredProgressMap (+52) |
| `app/lib/Service/TrainingService.php` | shuffleAnswersForSession, getSessionQuestionsWithAnswers, logAuditEvent, getExamDeadlineAt, closeExpiredExamSessionIfNeeded (+32) |
| `app/lib/Service/LeagueService.php` | challengeOpponent, getCourseParticipants, buildParticipantRow, buildStandings, buildViewerPairings (+31) |
| `app/lib/Controller/CourseController.php` | destroy, removePool, listMembers, addMember, removeMember (+25) |
| `app/lib/Service/GameshowService.php` | setReady, submitAnswer, buildState, sprintScoring, eliminationScoring (+20) |
| `app/lib/Service/DuelService.php` | getInviteCandidates, createDuel, joinDuel, rematch, generateCode (+20) |
| `app/lib/Service/QuestionService.php` | hasPoolAccess, hasCoursePoolAccess, hasActiveExamOnPool, findByPool, find (+18) |
| `app/lib/Service/TranslationService.php` | canEditPool, verifyAnswerEditAccess, setAnswerTranslation, deleteAnswerTranslation, validateLang (+17) |
| `app/lib/Service/LeitnerService.php` | resolveContentLanguage, translateQueueItems, getSmartQueue, getDueQuestions, getRemediationQueue (+12) |
| `app/lib/Service/PoolService.php` | normalizeOptional, normalizeChapterOrder, applyPoolMetadata, create, update (+11) |

## Entry Points

Start here when exploring this area:

- **`ForbiddenException`** (Class) — `app/lib/Service/ForbiddenException.php:3`
- **`CourseMember`** (Class) — `app/lib/Db/CourseMember.php:15`
- **`GameshowAnswer`** (Class) — `app/lib/Db/GameshowAnswer.php:6`
- **`LeagueChallenge`** (Class) — `app/lib/Db/LeagueChallenge.php:7`
- **`Answer`** (Class) — `app/lib/Db/Answer.php:7`

## Key Symbols

| Symbol | Type | File | Line |
|--------|------|------|------|
| `ForbiddenException` | Class | `app/lib/Service/ForbiddenException.php` | 3 |
| `CourseMember` | Class | `app/lib/Db/CourseMember.php` | 15 |
| `GameshowAnswer` | Class | `app/lib/Db/GameshowAnswer.php` | 6 |
| `LeagueChallenge` | Class | `app/lib/Db/LeagueChallenge.php` | 7 |
| `Answer` | Class | `app/lib/Db/Answer.php` | 7 |
| `DuelSession` | Class | `app/lib/Db/DuelSession.php` | 6 |
| `AnswerTranslation` | Class | `app/lib/Db/AnswerTranslation.php` | 7 |
| `QuestionTranslation` | Class | `app/lib/Db/QuestionTranslation.php` | 7 |
| `PoolShare` | Class | `app/lib/Db/PoolShare.php` | 7 |
| `Pool` | Class | `app/lib/Db/Pool.php` | 7 |
| `AiChatMemory` | Class | `app/lib/Db/AiChatMemory.php` | 20 |
| `SupportTicket` | Class | `app/lib/Db/SupportTicket.php` | 7 |
| `Course` | Class | `app/lib/Db/Course.php` | 29 |
| `NotFoundException` | Class | `app/lib/Service/NotFoundException.php` | 3 |
| `LeagueResult` | Class | `app/lib/Db/LeagueResult.php` | 7 |
| `DuelAnswer` | Class | `app/lib/Db/DuelAnswer.php` | 6 |
| `LeagueSeason` | Class | `app/lib/Db/LeagueSeason.php` | 7 |
| `GameshowSession` | Class | `app/lib/Db/GameshowSession.php` | 6 |
| `GameshowPlayer` | Class | `app/lib/Db/GameshowPlayer.php` | 6 |
| `DuelInvite` | Class | `app/lib/Db/DuelInvite.php` | 7 |

## Execution Flows

| Flow | Type | Steps |
|------|------|-------|
| `FinishSeason → GetId` | cross_community | 8 |
| `Active → BuildParticipantRow` | cross_community | 8 |
| `Active → GetId` | cross_community | 8 |
| `Finish → BuildParticipantRow` | cross_community | 8 |
| `Finish → GetId` | cross_community | 8 |
| `Challenge → BuildParticipantRow` | cross_community | 8 |
| `Challenge → GetId` | cross_community | 8 |
| `Ready → FindByQuestionsAndLang` | cross_community | 8 |
| `Start → GetId` | cross_community | 7 |
| `Create → FindByCourseAndUser` | cross_community | 7 |

## Connected Areas

| Area | Connections |
|------|-------------|
| Db | 1 calls |
| Controller | 1 calls |

## How to Explore

1. `gitnexus_context({name: "ForbiddenException"})` — see callers and callees
2. `gitnexus_query({query: "service"})` — find related execution flows
3. Read key files listed above for implementation details
