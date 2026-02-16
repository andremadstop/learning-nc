# Changelog

All notable changes to this project will be documented in this file.

## [1.2.4] - 2026-02-16

### Fixed
- **HIGH**: ExamMode batch submit — new `/api/training/submitBatch` endpoint replaces sequential per-question HTTP requests with a single batch request
- **MEDIUM**: Question validation enforces exactly one correct answer (was: allowed multiple)
- **MEDIUM**: UNIQUE constraint on `pool_shares(pool_id, shared_with, share_type)` prevents duplicate shares
- **MEDIUM**: Pool deletion now cleans up orphan `course_pools` records
- **LOW**: Analytics `recorded_at` timestamp now set on record creation

### Technical
- 52 API endpoints (up from 51), new migration V600

## [1.2.3] - 2026-02-16

### Fixed
- **HIGH**: JSON import orphan questions — answers validated before question creation (no more orphan questions on empty answer text)
- **HIGH**: Course deletion cleanup — `course_pools` and `course_members` now explicitly deleted before course record
- **HIGH**: Student progress access — students can now view their own course progress (not just instructors)
- **MEDIUM**: N+1 query in QuestionService — batch-loads answers with `WHERE question_id IN (...)` instead of per-question queries
- **MEDIUM**: CSV import false positive correct-index — numeric values now checked against actual answer count range
- **MEDIUM**: addMember user validation — `IUserManager::userExists()` check prevents adding non-existent users to courses

## [1.2.2] - 2026-02-16

### Fixed
- **HIGH**: Schema drift — `image_path` and `lang` columns missing from questions table in Migration V200
- **HIGH**: Instructor Dashboard unreachable — added Course List / Dashboard sub-navigation for instructors
- **MEDIUM**: CSV import rejected correct_answer index 7-8 (limit was 6, now matches max 8 answers)
- **MEDIUM**: JSON import accepted empty answer texts — now validates and rejects
- **MEDIUM**: Course-to-Pool navigation assumed owner permissions on error — now defaults to read-only
- **MEDIUM**: ShareController leaked exception messages to client — now returns generic errors
- **LOW**: QuestionForm only loaded questions with exactly 4 answers — now handles 2-8 answers
- **LOW**: DELETE endpoints returned 204 with response body — changed to 200
- **LOW**: Added `@nextcloud/l10n` as explicit dependency in package.json

## [1.2.1] - 2026-02-16

### Fixed
- **CRITICAL**: Schema drift — 4 tables missing from migrations (pool_shares, question_translations, answer_translations, analytics). New migration V350 created
- **CRITICAL**: Migration 400 hardcoded `oc_` table prefix — now uses dynamic `$this->db->getPrefix()`
- **CRITICAL**: Migration 400 index name mismatch (`learn_ua_session_question_uniq` → `learn_ua_sq_uniq`)
- **HIGH**: ExamMode scoring — server-side question limiting instead of client-side slicing
- **HIGH**: addPool IDOR — ownership/share check before adding pool to course
- **HIGH**: Course progress frontend/backend data format mismatch
- **HIGH**: "Add Pool" modal empty — merges own + shared pools
- **MEDIUM**: setImagePath now allows shared-pool editors (not just owner)
- **MEDIUM**: QuestionList poolId watcher — reloads on prop change
- **MEDIUM**: Pool search includes shared pools
- **MEDIUM**: ShareService rejects group shares (not yet implemented)
- **MEDIUM**: ImageController delete order — DB reference cleared before file deletion
- **MEDIUM**: Enroll group-check reordered — membership check first
- **LOW**: Enroll returns 403 for group authorization errors (was 400)
- **LOW**: LeitnerService N+1 — batch query for answers
- **LOW**: CourseService.findAll N+1 — batch pool/member counts

## [1.2.0] - 2026-02-15

### Added
- **Course Management** — Instructors can create courses, assign question pools, enroll students, and track progress (15 new API endpoints)
- **Instructor Dashboard** — Overview of all courses with enrollment stats and student progress
- **Role System** — Automatic instructor/student detection based on Nextcloud group membership
- **Exam Mode** — Timed exams with configurable question count and strict scoring
- **Swipe Mode** — Touch-friendly swipe-based flashcard review (mobile-optimized)
- **Analytics Dashboard** — Detailed per-pool statistics with accuracy trends and study history
- **Question Search** — Full-text search across all question pools
- **Multi-Language Support** — Translate questions and answers into any language (6 translation endpoints)
- **Language Filter** — Filter questions by translation language during training

### Changed
- **UI Overhaul** — Centered layout, wider grids, consistent spacing, @nextcloud/vue components throughout
- **PoolList** — Redesigned with card layout, search bar, and better empty states
- **TrainingMode** — Streamlined answer flow, clearer score display
- **LeitnerMode** — Simplified box visualization, improved due-card handling
- **ImportDialog** — Cleaner preview table, better error messages
- **ShareDialog** — Autocomplete user search, permission badges
- **QuestionForm** — Inline answer editing, drag reorder

### Fixed
- Training sessions now include answers per question (not just question IDs)
- Image upload service handles missing files gracefully
- Leitner answer endpoint validates pool access

### Security
- 12 Codex audit findings resolved (input validation, access control, error handling)
- All course endpoints enforce ownership/enrollment checks
- Translation endpoints verify pool access before allowing modifications

### Technical
- 51 API endpoints (up from 35), 13 database tables (up from 10)
- 2 new database migrations (course tables, question search index)
- 6 new Vue components: CourseList, CourseDetail, InstructorDashboard, ExamMode, SwipeMode, AnalyticsDashboard
- 4 new PHP services: CourseService, RoleService + enhanced QuestionService, TrainingService

## [1.0.0] - 2026-02-13

### Added
- **Pool Management** — Create, edit, delete question pools with descriptions
- **Multiple Choice Questions** — Questions with 2-6 answer options, explanations, difficulty levels
- **Training Mode** — Quiz sessions with immediate feedback, score tracking
- **Leitner Spaced Repetition** — 5-box system with intervals (instant, 1 day, 3 days, 7 days, 14 days)
- **Leitner Dashboard** — Box visualization, due question counts, mastery stats, accuracy tracking
- **Pool Sharing** — Share pools with users (read or edit permissions)
- **CSV Import** — Bulk import questions from CSV (flexible format, header detection)
- **JSON Import** — Bulk import questions from JSON files
- **Dashboard Widget** — Nextcloud Dashboard widget showing due questions per pool
- **Mobile Responsive** — Touch-friendly design with responsive breakpoints
- **Error Handling** — Error banners with retry, loading/empty states throughout
- **Session History** — Training session tracking with completion stats
- **Analytics** — Per-pool analytics with JSON metrics

### Security
- All queries parameterized (QBMapper)
- Share-aware access control on all endpoints
- Session ownership verification on training submissions
- Pool access checks on Leitner operations
- Translation endpoint access control

### Technical
- Nextcloud 29-31 compatible
- PHP 8.1+ required
- PostgreSQL 13+ or MySQL 8+
- Vue 2.7 frontend with @nextcloud/vue components
- 35 API endpoints, 10 database tables
- 3 database migrations
