# Changelog

All notable changes to this project will be documented in this file.

## [3.7.0] - 2026-04-02

### Added
- **Exam Date Countdown Widget**: Instructors set exam dates per course. Students see a live countdown on the NC Dashboard ("Prüfung in X Tagen"). Widget hidden when no exam date is set.
- **Metacognition Hints**: Leitner mode shows "Box 2 · Fällig seit 3 Tagen" or "Letzte Antwort falsch" beneath each card, helping learners understand why a card appears.
- **Campaign Selection per Course**: Instructors can select which adventure campaigns are available (PATCH API + UI). "Top 5 empfohlen" button for curated scenarios (SolarWinds, WannaCry, Log4Shell, Phishing Friday, Ransomware).
- **Placement SVG Topologies**: 4 reusable network topology backgrounds (basic, server, wan, dmz) for PBQ placement questions. Selectable via `config.scenario_type`.
- **CLI Terminal Feedback**: PBQ CLI simulator now responds to commands with domain-aware error messages (Cisco IOS: "% Unrecognized command", Linux: "command not found", Windows, SQL, generic). Powered by `cliStateMachine.js`.

### Changed
- **Smart Queue as Lernraum Entry**: Smart Queue hero card is the primary entry point in student Lernraum view, showing due card count across all courses.
- **Mode Visibility per Role**: Training mode tab hidden for students. Instructors can toggle individual modes on/off via `mode_config` panel.
- **True/False → Single Choice**: All `true_false` pool types migrated to `single` with two options. Editor no longer offers Wahr/Falsch as a distinct type. Idempotent DB migration.
- **Badge streak_30 → streak_14**: Renamed for 4-week bootcamp compatibility. DB migration renames existing awards.

### Fixed
- **Accessibility**: ArenaSelector uses ARIA radiogroup pattern with `aria-checked` states. PBQ components (CLI, Placement) have keyboard navigation (Tab, Enter, Escape). ExamMode timer has `aria-live` announcements for phase changes (safe → warning → danger → expired).
- **E2E Tests**: Onboarding dismiss helper supports German labels ("Tour überspringen", "Loslegen") and waits for splash phase (6s timeout).
- **Umlaut Cleanup**: 17 ASCII umlaut occurrences fixed across 12 Vue components and 2 l10n files.

### Removed
- **Zeitreise/HackThroughTime**: Dead code removed — 8 API routes, HackThroughTimeController, HackThroughTimeService, epoch-tokens.css (1,230 lines). DB table `learning_epoch_progress` preserved for data safety.

### Security
- **DSGVO Help Page**: NC Help & Privacy link wired to app's Datenschutzerklärung with 7 GDPR categories. Impressum accessible via NC settings.

## [3.6.0] - 2026-03-30

### Added
- **VirtuProf Fullscreen**: Dedicated fullscreen learning assistant with top-level navigation entry, dismissal UX (X-button, ESC, swipe-down), and synchronized chat state between bubble, sidebar, and fullscreen view.
- **Course Narrative Portfolio**: AI-generated personalized course-end reflection using Gemini, with snapshot caching and graceful fallback when AI is not configured.
- **ICS Calendar Feed**: Token-based public endpoint serving Leitner repetition VEVENTs for Box 3-5 due dates. Subscribe via webcal:// or copy URL.
- **EncryptionService & AuditService**: Reusable backend services for encrypted profile storage (AES-256-CBC) and moderation audit logging.
- **Vault Import Command**: `occ learning:import-vault` rewritten for idempotent imports with `--dry-run`, `--pattern`, `--exclude`, and Obsidian syntax cleanup.
- **New Badge Triggers**: Five new trigger-based badges for simulator practice, weekend learning, swarm contributions, trouble fixing, and quick exam completion.

### Changed
- **Mega-Tab Navigation**: Course navigation consolidated from 16 individual tabs into 5 mega-tabs (Lernraum, Teilnehmer, Wettbewerb, Kommunikation, Verwaltung). CourseDetail.vue reduced from 3874 to 759 lines.
- **Badge System Simplified**: 10 active badges with 25 legacy badges preserved via is_legacy flag (not deleted, not awarded going forward).
- **Privacy Model Expanded**: Privacy metadata now covers 7 GDPR-mandated categories (learning, ai, social, audit, gamification, assessment, external).
- **Course Summary Expanded**: Completion view combines mastery, badges, trouble spots, snapshot state, narrative reflection, and ICS subscription.

### Fixed
- **Umlaut Cleanup in UI Strings**: t() strings normalized from ASCII fallbacks to proper German umlauts.
- **Navigation Wiring**: View-key mismatches during mega-tab conversion corrected.

### Security
- **Gemini Prompt Hardening**: NFKC normalization, multi-turn system prompt refresh, and 26 injection-pattern filters.
- **Encrypted Telos Storage**: Sensitive profile fields encrypted at rest via Nextcloud ICrypto.
- **Moderation Audit Trail**: Every swarm contribution approval/rejection logged with full attribution.

## [3.4.0] - 2026-03-28

### Changed — UX-Navigation Struktur
- **Instructor Tab Groups**: 17 instructor tabs organized into 5 logical groups (Lernraum, Teilnehmer, Kommunikation, Wettbewerb, Verwaltung) with visual separators.
- **Abenteuer Standalone**: Adventure mode promoted from Arena sub-mode to its own CourseDetail tab, independently gatable via mode_config.
- **Arena Submode Gating**: Each Arena mode (Duel, Gameshow, Oldschool) individually hideable per course rules. Arena tab disappears when all sub-modes disabled.

### Changed — Code-Hygiene & Settings
- **Settings Split**: Instructors now see two sub-tabs in Settings: "Kurs-Verwaltung" (admin) and "Meine Einstellungen" (personal). Students see only personal settings.
- **Zeitreise Removed**: Removed 1270+ lines of dead Zeitreise/HackThroughTime frontend code (component, characters, navigation, mode_config key).
- **German Labels**: All UI-visible labels now go through t() translation. ModeIdentityBanner labels localized.

### Added — Simulator-Praxis-Sessions
- **Practicum Engine**: Pure-JS state machine for guided session management with localStorage persistence and browser-reload recovery.
- **11 Practicum Sessions**: Real-world IT scenarios across all 7 simulators (42 steps total). Firewall, DNS, and Routing get 2 sessions each.
- **PracticumRunner UI**: New "Praxis" tab in every simulator with step-by-step instructions, context explanations, progress bar ("Schritt X von Y"), and score summary.

### Added — Student Dashboard
- **Heute Screen**: New default landing page for students with SmartQueue widget (due cards count + "Jetzt lernen"), Daily Challenge card, streak display, and daily progress.
- **Global Feed**: Aggregates announcements from all enrolled courses chronologically with course-name badges and pagination.
- **Pools Navigation**: Direct "Pools" tab in student main navigation — one click to PoolList.

### Added — DevCloud Integration & Leitner
- **Talk Room Link**: Instructors can set a Talk room token per course. Clickable link appears in course header, opens NC Talk in new tab.
- **Materials for Students**: Students now see the Materialien tab (read-only) when a course has a material folder set.
- **Buddy Matching**: New "Lernpartner" tab shows who can help with your topics and whom you can help, based on Telos help_offer/help_wanted data.
- **Course-Aware Tools**: Werkzeuge tab filters simulators based on active course's enabled_tools. No course active = all tools visible.
- **Sprint Intervals**: Instructors can activate sprint mode per course (4h/12h/1d/2d instead of 1d/3d/7d/14d Leitner intervals) for intensive courses.

### Fixed
- **Badge Duplicate Crash**: Session completion no longer fails with 400 when badge already exists. Fixed catch to use OCP\DB\Exception (NC 30 wraps Doctrine exceptions).

## [3.0.0] - 2026-03-24

### Added — Story RPG "Abenteuer" Mode
- **Story Engine**: Full campaign-based RPG learning mode with branching narratives, skill checks, and character classes (Architect, Security, SysAdmin, Helpdesk). 20 campaigns across CompTIA Network+, Security+, CySA+, Linux+, and A+.
- **KI-Erzähler**: Gemini-powered dynamic narrator with freetext player actions, NPC dialog generation, and role-based prompts. Fallback to static text when AI unavailable.
- **Skill Checks**: In-story quiz phases with 3 questions per check, batch submission, pass/fail branching to different scenes.
- **20 Campaigns**: Real-world IT incident scenarios — SolarWinds, WannaCry, Log4Shell, Colonial Pipeline, Equifax, plus 6 Security+ exam-oriented campaigns (Phishing, Zero Trust, Crypto, Compliance, IR, Cloud) and an AI Security campaign.
- **Coop Mode**: Infrastructure for multiplayer campaign sessions (UI placeholder, backend ready).

### Added — "Zeitreise" Hack Through Time
- **7 Epoch Campaign System**: Phone Phreaking (1960s), WarGames (1980s), The Worm (1990s), Bobby Tables (2000s), Shadow Brokers (2010s), Supply Chain (2020s), Quantum Dawn (Future).
- **Period-Accurate CSS Themes**: Each epoch has its own visual theme (terminal-green, amber-on-black, retro-web, etc.).
- **CHRONOS Guide**: AI narrator character that guides players through hacking history.
- **Museum Facts**: Historical sidebars with real dates, events, and technical details.
- **Character Classes**: Epoch-specific roles (Phreaker, Hacker, Script Kiddie, Analyst, etc.).

### Added — Visual Identity System
- **Design Tokens**: CSS custom property layer (`--lnc-*`) with dark/light scopes and motion utilities.
- **13 Character Registry**: SVG silhouette avatars with emotion states (idle, celebrate, alert) for all NPCs.
- **Campaign Intro Animation**: Full-screen intro with title, difficulty badge, and character reveal.
- **Dialogue Stage**: NPC dialog component with portrait, speech bubble, and emotion tags.
- **Paper & Circuits Narrative Skin**: Dark theme for adventure mode with subtle circuit-board patterns.

### Added — RAG & Document Intelligence (v4.1)
- **Document Upload & Extraction**: Instructors can link Nextcloud folders as course material, scan for files, and extract text from PDF/Markdown.
- **Chunking Pipeline**: Automatic text chunking with keyword extraction for semantic search.
- **Multi-Source RAG Context**: VirtuProf answers now cite course materials with `[Quelle: filename, Kap. X]` format.
- **4000-Token Budget**: Smart context window management prioritizing relevant chunks.

### Added — Oldschool Board Games (v5.0)
- **Lernwürfel**: "Mensch ärgere dich nicht" inspired board game with dice rolls and question challenges.
- **Wissensturm**: Trivial Pursuit tower game with category-based progression.

### Added — Personal Learning Bot (v4.0)
- **GeminiService**: 5-layer security stack (API key isolation, input sanitization, output filtering, rate limiting, audit logging).
- **VirtuProf Chat**: AI-powered learning assistant with chat-first UI, language auto-detection, and chat memory.
- **Note Generator**: Gemini-based topic summaries saved to Nextcloud Files.
- **Lernprofil**: Passive user learning profile with weekly study plan generation.
- **Auto-Triggers**: Exam auto-notes, wrong-answer threshold alerts, weekly plan background job.
- **Ticket Triage**: AI-powered support ticket classification and draft FAQ responses.
- **AI Consent**: Opt-in consent overlay before first AI interaction, GDPR-compliant.

### Added — Tools
- **Subnet Calculator**: Browser-based IPv4 subnet calculator with CIDR/mask input, binary display, and VLSM planner.
- **Subnetting Question Pool**: 50+ subnet calculation practice questions.

### Added — Quality & Testing
- **ESLint**: Vue/JS linting with `no-v-html` error rule, 0 errors enforced.
- **Pre-Push Hook**: 4-gate quality check (Security scan, ESLint, Vitest 67 tests, PHPStan Level 5).
- **Playwright Browser Tests**: 67 automated checks covering navigation, campaigns, time travel, tools, VirtuProf, security. 52 PASS / 11 FAIL / 4 SKIP.
- **PHPUnit**: 12 service-level unit tests (Training, Leitner, Analytics, Course).
- **Test Strategy**: 4-gate pyramid documented in CLAUDE.md.

### Fixed
- Campaign skill-checks now always use pre-loaded questions from scene response (no separate API call).
- Campaign start always creates fresh session (no stale resume from previous play).
- Missing Abenteuer/Oldschool/Zeitreise toggles in course mode settings.
- WannaCry campaign: corrected from email dropper to SMB worm narrative.
- PBQ subtype auto-detection from config structure when `pbq_subtype` not set.
- Empty skill-check pools handled gracefully (skip to next scene instead of crash).
- Narrative garbage fallback when Gemini returns malformed text.
- AI rate-limits on VirtuProf available()/status() endpoints.
- ESLint: empty catch blocks, v-if with v-for separation, escaped quotes in templates.

### Security
- Gemini language mirroring prevention (no prompt echo).
- Story mode injection guards on freetext actions.
- Story mode rate-limits (10 starts/min, 60 scenes/min).
- Pre-push security scan for hardcoded secrets.

## [2.7.0] - 2026-03-21

### Added
- **Gameshow Sprint Mode**: 2-5 players compete simultaneously — fastest correct answer scores highest (500 + time bonus). Live leaderboard with crown icon after each question. 15 questions per round with podium animation (gold/silver/bronze).
- **Gameshow Elimination Mode**: 3-5 players start with 3 lives. Wrong answer costs 1 life (heart-break animation). Last player standing wins. Sudden death when 2 players remain.
- **N-Player Session Backend**: New `gameshow_sessions`, `gameshow_players`, `gameshow_answers` tables. Supports 2-5 simultaneous players with 500ms short-polling.
- **Arena Tab**: Duell and Gameshow merged into a single "Arena" tab with mode selection cards (Duell 1v1, Sprint 2-5, Elimination 2-5).
- **Spectacle Animations**: Spotlight effect, dramatic 2s reveal pause, screen shake on wrong answers, pulsing borders on tension, confetti on victories. All respect `prefers-reduced-motion`.
- **VirtuProf Showmaster**: 6 gameshow trigger scripts — round announcements, standings commentary, answer reactions, elimination farewells.
- **Training + Wahr/Falsch Merge**: TrainingMode now supports both Multiple-Choice and True/False with swipe animations. SwipeMode.vue removed.
- **Session Robustness**: Abort button during play, disconnect detection (30s), localStorage session recovery on page reload, stale session cleanup (5min all-inactive → expired).
- **Gameshow XP Integration**: Full XP from gameshow scores like other learning modes. Session history viewable.
- **Course Lobby**: Instructors can start gameshow sessions visible to all course members.

### Fixed
- **Duel auto-start**: Duel starts immediately when opponent joins — no "Ready" click needed.
- **Duel invite self-destruct**: Fixed feedback loop where `presetDuelCode` clearing reset the duel to join screen.
- **Duel poll timeout**: Reset poll timestamps on session activation to prevent premature expiry.
- **Gameshow lobby expiry**: Timeout check now only runs during active games, not waiting lobby.
- **Select text visibility**: `color: !important` + `line-height` fix for NC theme overrides in Chrome/Firefox.
- **Empty pool duel**: Reject duel creation when pool has 0 questions (was silently creating broken session).
- **Boolean migration**: Changed `notnull: true` to `notnull: false` for boolean columns (NC migration framework incompatibility).

## [2.6.1] - 2026-03-20

### Added
- **VirtuProf Avatar Rework**: Redesigned professor avatar — reads behind a book with hover gaze effect, wave arm on click (auto-hides after 1.2s), and celebratory particles. Question mark on body improves discoverability. Particles and wave arm hidden by default.
- **Per-Question Language Switcher**: `QuestionLanguageSwitcher.vue` — inline language selector on each question card in all learning modes; switches between available translation languages without leaving the mode.
- **Arabic Content Language**: Arabic (`ar`) added as a supported content language. Translation rows allowed, RTL supported via logical CSS properties across all learning modes.
- **Pool Content Language Display**: Pool cards in PoolList now show available content languages as badges.

### Fixed
- **DB Audit (v003600)**: Added missing indices on `oc_learning_duel_answers`; fixed column type mismatch on `duel_answers.answer` (was TEXT, now consistent with schema).
- **Duel select visibility**: Selected text in pool/question dropdowns in DuelMode now visible via `appearance:none` override.
- **Duel invite null poolId**: `poolId` now resolved from course context when null on duel invite; translation chunk query and avatar badge also fixed.
- **German translation "Training"**: Was incorrectly translated as "Ausbildung" — corrected to "Training".
- **RTL/Arabic layout**: Replaced physical CSS properties (`margin-left`, `padding-right`, etc.) with logical equivalents (`margin-inline-start`, `padding-inline-end`) in all learning mode components.
- **PoolService cleanup**: Typed class fields, removed duplicate `NotFoundException` import.
- **LeitnerService**: Documented intentionally empty catch block to suppress false-positive static-analysis warnings.
- **Controller hardening**: Added missing `?string $userId` nullable declarations, `#[UserRateLimit]` on write endpoints, removed deprecated NC API calls.
- **getTablePrefix migrations**: All migrations updated to use `$this->db->getPrefix()` instead of hardcoded `oc_`.
- **i18n**: Added missing German translations for `LeagueTab`, `CourseDetail`, and VirtuProf UI strings. Fixed "Saving..." translation key.

### Changed
- VirtuProf FAQ labels wrap correctly on long strings.
- Question language switcher chrome reduced in size for better inline display.
- Translation import deduplicates answer rows to prevent constraint violations on re-import.
- Pool language visibility threshold relaxed (minimum pool count reduced from 5 to 1).

---

## [2.6.0] - 2026-03-19

### Added

#### Liga-System
- **LeagueTab.vue**: New league standings tab in CourseDetail. Students see their tier, XP, and rank within the course league. Instructors see full standings with promotion/relegation zones.
- **LeagueService.php + LeagueController.php**: Backend for season creation, XP-based ranking, automatic promotion/relegation at season end.
- **2 new DB tables**: `oc_learning_league_seasons` and `oc_learning_league_results` (Migrations 002700 + 002800).
- **League API**: `GET /api/courses/{courseId}/league`, `GET /api/courses/{courseId}/league/season`, season end trigger.

#### VirtuProf — AI Tutor Avatar
- **VirtuProf.vue + VirtuProfAvatar.vue + VirtuProfBubble.vue**: Interactive tutor avatar integrated into all learning modes and App.vue. Provides contextual hints, FAQ answers, and support ticket submission. State managed via `VirtuProfController.php` + `GET/POST /api/virtuprof/state`.
- **Support Tickets**: Students submit support tickets through VirtuProf (Migration 003100, `SupportTicketController.php`). Admin inbox in AdminSettings.vue.
- **VirtuProf Ticket-Routing**: Tickets can be routed to course instructors (subject questions) or admins (technical issues). Category selector in ticket form; `category`, `routing_target_type`, `routing_course_id` stored per ticket.
- **Instructor Requests Tab**: Course instructors see a new "Anfragen" tab in CourseDetail showing tickets routed to them, with inline answer form.
- **Admin Ticket Filter**: Admin support ticket list shows only admin-routed tickets (technical/usage), not course-instructor tickets.
- **VirtuProf Language Toggle**: Switch VirtuProf language independently of the UI locale.

#### Performance-Based Questions (PBQ)
- **4 interactive PBQ subtypes**: `PbqDropdown.vue` (inline dropdown on topology diagram), `PbqPlacement.vue` (drag-to-place), `PbqCli.vue` (simulated CLI), `PbqCable.vue` (cable patching).
- **PbqRenderer.vue**: Orchestrator component routing to the correct subtype, with submit/skip actions.
- **CLI State Machine** (`cliStateMachine.js`): Pure ES module with `DOMAIN_SCHEMAS` and dynamic `getPrompt()` — drives realistic CLI simulation in PbqCli.
- **SVG Topology Renderer** (`NetworkTopologySvg.vue` + `networkTopologyIcons.js`): Renders network diagrams from `topology-config` JSON with DEVICE_ICONS library.
- **Multi-Panel Layout** (`PbqMultiPanel.vue`): Split-panel PBQ layout combining scenario text, topology diagram, and interactive question panel.
- **PbqAuthorTool.vue**: Visual editor for instructors to author PBQ configurations. Live preview via PbqRenderer, clipboard-as-bridge to QuestionForm.
- **DB migration**: `pbq_subtype` (VARCHAR 50) and `pbq_config` (TEXT JSON) columns added to `oc_learning_questions`.
- **ExamMode PBQ support**: PBQ questions sorted first, `PbqRenderer` integrated, `pbqAnswers` state managed, batch submit handles PBQ scoring.
- **JSON import**: `importPbqItem()` handles `pbq` question type in `ImportController`.

#### Instructor Notes
- **Instructor note fields**: `instructor_note` (TEXT) and `note_visible` (BOOLEAN) added to questions (Migration 003200). Instructors toggle note visibility per question.
- **NcNoteCard display**: Instructor notes shown in TrainingMode, LeitnerMode, SwipeMode, and ExamMode when `note_visible` is true.
- **QuestionForm editor**: Textarea for `instructor_note` with `note_visible` toggle checkbox.

#### Mehrsprachigkeit (Content Language)
- **Content language setting**: `content_language` app-level setting to define the default question language.
- **TranslationDialog.vue** (enhanced): Translation workflow now integrated into Training, Leitner, Swipe, Exam, Duel, and SmartQueue — language switcher triggers on-the-fly question/answer translation.
- **Russian translations**: `l10n/ru.js` + `l10n/ru.json` added.

#### Pool & Question Metadata
- **Pool metadata**: `chapter_ref` and `exam_ref` fields added to `oc_learning_pools`. Searchable from PoolList.
- **Question metadata v1**: `difficulty`, `exam_objective` fields added to `oc_learning_questions` (Migration 002900). Used for exam blueprint sampling (30/40/30 difficulty buckets).

#### Instructor Course Controls
- **Dozentensteuerung**: `required` and `enforced` flags + chapter-filter in CourseService (Migration 003000). Instructors restrict which pools/chapters are active per course.
- **`resolveCoursePoolContext()`**: New helper applying chapter-level curriculum scope and per-question pause overrides across all learning modes (Training, Leitner, Swipe, Exam, Duel).
- **Kursregeln Tab**: Instructors enable/disable individual learning modes (Leitner, Swipe, Exam, Duell, Liga) per course; disabled modes hidden from student tab bar.
- **Chapter Heatmap**: Instructor tab showing per-chapter correct-answer rate with color-coded quality badges.
- **Weak Questions Tab**: Instructor tab listing lowest-accuracy questions with per-question pause toggle.
- **Announcements Tab**: Instructors post time-limited course announcements; students see active announcements in their pools tab.
- **Live Prüfungs-Slot**: Instructor opens a supervised exam window (duration + scope mode); students see countdown banner.
- **CourseDetail student tabs**: Replaced generic "Pools" tab with Training|Leitner|Wahr/Falsch|Exam tabs for students.

#### Live-Duell
- **Live-Duell**: Two authenticated users challenge each other to a real-time True/False quiz using short polling (500ms interval).
- **DuelMode.vue**: Join → lobby → question → feedback → results phases; 30-second inactivity timeout (inactive player forfeits).
- **"Duell" navigation tab**: In App.vue for all authenticated users — back button returns to Pools (instructor) or Courses (student).
- **Duel Invites**: Instructors and students invite course members to duels; invite list with accept/decline/cancel actions.
- **6 new API endpoints**: `POST /api/duels`, join, ready, state, answer, rematch.
- **2 new DB tables**: `oc_learning_duel_sessions` and `oc_learning_duel_answers` with scoring matrix (correct+first=+4, both correct=+3/+2, both wrong=−1).

#### ICS Calendar Integration
- **Calendar Token**: Per-user ICS subscription token. `GET /api/v1/user/calendar-token` returns token + URL; `POST /api/v1/user/calendar-token/regenerate` invalidates old token. Stored in NC preferences.
- **Public ICS Feed**: `GET /api/v1/calendar/{token}.ics` — unauthenticated endpoint for calendar subscriptions; token-to-user reverse lookup via `oc_preferences`.
- **Calendar Sync in Personal Settings**: ICS URL field with one-click copy and regenerate button.
- **ICS UID domain fix**: `UID` fields use actual NC hostname instead of literal `@nextcloud`.

#### AI Enhancements
- **AI Explain button**: `POST /api/ai/explain` schedules a text2text task explaining why an answer is correct/incorrect. "Explain this" button appears after answering in LeitnerMode and TrainingMode (only when AI is configured).
- **At-Risk CSV Export**: `GET /api/courses/{courseId}/at-risk/export/csv` — CSV download of at-risk students (Name, Risk Level, Reasons, Accuracy, Last Active). Export button in course progress tab.

#### Other
- **Batch Pool Assignment**: Add-pool modal in CourseDetail supports multi-select (checkboxes) — add multiple pools in one click.

### Fixed
- **Ticket authorization**: `instructorList` endpoint now enforces instructor-of-course check; previously any authenticated user could read course tickets.
- **Instructor ticket answers**: Non-admin instructors can now answer tickets routed to them (previously admin-only).
- **Duel blinking buttons + broken rematch**: UI state machine fix for duel phase transitions.
- **CourseService heatmap/dashboard**: Corrected column names and join order in heatmap and instructor dashboard queries.
- **PostgreSQL selectDistinct bug**: Fixed in CourseService for enrolled-student queries.

### Changed
- **ExportController**: Replaced anonymous class with `DataDownloadResponse` for ICS responses. `buildIcsBody()` shared between authenticated and token-based endpoints.
- **AIService::getTaskStatus()**: Now includes `output` (raw text) alongside `questions` array — needed for explain results.
- **ICS token security**: Token switched from plaintext to HMAC-SHA256 (`base64url(userId).HMAC`); validated with `hash_equals()`.
- **CourseController**: `#[UserRateLimit]` added to all 8 write endpoints (update, destroy, addPool, updatePool, removePool, addMember, removeMember, enroll).
- **DB tables**: Now 17 total (added league_seasons, league_results, duel_sessions, duel_answers, support_tickets, instructor_note columns, PBQ columns, pool/question metadata columns).

---

## [2.5.2] - 2026-03-16

### Added
- **ICS Calendar Feed**: `GET /api/leitner/schedule.ics` — iCal export of all due Leitner items, grouped by pool, for the next 30 days. Compatible with any calendar app (Apple Calendar, Thunderbird, Google Calendar via URL). Overdue items appear as today's events.

### Fixed
- **Dashboard unique student count**: `GET /api/instructor/dashboard` now returns `unique_student_count` via `COUNT(DISTINCT user_id)` — prevents multi-enrollment double-counting.
- **Notification icon absolute URL**: `Notifier.php` wraps `imagePath()` with `getAbsoluteURL()` — fixes icon display in mobile/desktop clients.
- **LeitnerController null safety**: `stats` and `due` endpoints return `{"error":"poolId is required"}` instead of HTTP 500 when called without `poolId`.

## [2.5.1] - 2026-03-12

### Added
- **Exam hotkeys**: In Exam mode, `1-8` selects answer options and `Enter` confirms/advances depending on question type.

### Changed
- **Swipe mode UX**: Improved mobile touch targets (larger answer buttons), clearer start/retry/submission states, and improved next-action button behavior on small screens.
- **Daily Challenge refresh behavior**: Pool list now auto-refreshes completed challenges when the UTC reset countdown reaches zero to avoid stale post-midnight UI state.

## [2.5.0] - 2026-03-11

### Added
- **Daily missions**: mission progress model plus claim endpoint (`GET /api/v1/missions`, `POST /api/v1/missions/{missionKey}/claim`).
- **Mission XP claims table**: Migration `Version002000` adds `learning_user_mission_claims` with one-claim-per-mission-per-day guarantee.
- **Streak freeze tokens**: weekly reset token support in streak calculation (`streak_freeze_tokens`, `last_freeze_reset_week` in `learning_user_stats`).
- **Badge tiers**: new bronze/silver/gold/platinum tiers for session count, mastery count, and streak milestones.
- **Analytics UI updates**: Daily mission cards with claim action and freeze-token visibility.

### Changed
- **Streak handling**: streak computation can bridge one missed day when freeze token is available; token is consumed on activity-side calls.
- **App version**: bumped to `2.5.0`.

## [2.4.0] - 2026-03-11

### Added
- **Exam status endpoint**: `GET /api/training/session/{sessionId}` for authoritative remaining-time/completion state polling.
- **Admin Audit API + UI**: `GET /api/settings/admin/audit` and "Recent Audit Events" block in admin settings.
- **Review workflow API**: `PUT /api/pools/{id}/review` and `PUT /api/questions/{id}/review` with review states `draft|reviewed|published`.
- **Blueprint exam selection**: Exam mode now samples by difficulty buckets (target 30/40/30 easy/medium/hard) with fallback fill.
- **Migration V001900**: New `learning_audit_events` table, review columns on pools/questions, and additional query indexes.
- **Deploy verification helper**: `examples/verify_deploy_integrity.sh` for migration/route integrity checks.
- **E2E scaffold**: Playwright config and initial exam-mode smoke test.
- **Parallel-tab soft-lock (frontend)**: Secondary tabs become read-only while an exam is active in another tab.

### Fixed
- **Duplicate answer race guard**: Added max-result constraints in duplicate-answer checks to reduce write contention under retries.
- **Exam synchronization robustness**: UI now polls server status and transitions cleanly when session is completed remotely.

### Changed
- **App version**: bumped to `2.4.0`.

## [2.3.0] - 2026-03-10

### Added
- **Admin Settings Page**: NC-native settings under Administration > Learning — toggle Daily Challenge globally, set default language, configure max import size (1-10 MB), toggle Gamification (XP/Badges/Streaks)
- **Personal Settings Page**: NC-native settings under Personal > Learning — toggle Daily Challenge per user, set UI language (System/DE/EN), toggle notifications
- **Settings API**: `GET/PUT /api/settings/admin` and `GET/PUT /api/settings/personal` endpoints
- **Gamification Toggle**: XpService, BadgeService, StreakService respect `gamification_enabled` app config
- **Configurable Import Size**: ImportController reads `max_import_size_mb` from app config instead of hardcoded 2 MB

### Fixed
- **i18n Consistency**: Translated all remaining hardcoded English strings — pool creation dates, training question counter, difficulty badges (Easy/Medium/Hard), Leitner box indicator, search result pool names
- **Daily Challenge Global/User Toggle**: UserStateController checks both global (`daily_challenge_enabled`) and per-user (`daily_challenge`) setting before showing challenges

### Changed
- **Webpack**: Multi-entry build with separate bundles for admin-settings and personal-settings
- **info.xml**: Registered AdminSettings, AdminSection, PersonalSettings via `<settings>` block

## [2.2.0] - 2026-03-09

### Fixed
- **Course Pool Access**: Course members and instructors can now access pools assigned to their courses. Previously only pool owners and explicitly shared users had access, causing "Pool not found" errors for enrolled students.

### Changed
- **PoolService**: Extracted `findPoolRow()` helper, added `hasCoursePoolAccess()` fallback in `find()` method
- **5 Services updated**: LeitnerService, PoolService, QuestionService, TrainingService, TranslationService — all `hasPoolAccess()` methods now check course membership as a third access path

### Technical
- 5 changed files, 165 insertions, 12 deletions
- No database migration needed — uses existing `learning_course_pools` and `learning_course_members` tables
- Course pool access grants read-only permission (`permission: 'read'`)

## [2.1.0] - 2026-02-26

### Added
- **Free Text Questions**: New `open` question type — users type free-text answers instead of selecting from choices. Fuzzy matching (case-insensitive, Levenshtein distance ≤2 for short answers, substring matching for long answers)
- **CSV/JSON Export**: Download question pools as CSV or JSON files. Roundtrip-compatible with import — export then re-import produces identical questions
- **ExportController.php**: New controller with `exportCsv()` and `exportJson()` endpoints, `DataDownloadResponse` for browser download
- **XP Streak Multipliers**: Tier-based XP bonuses replace old linear formula — 1.5x at 3-day streak, 2x at 7-day, 3x at 30-day streak
- **XP Multiplier Badge**: Visible multiplier indicator (1.5x / 2x / 3x) in Pool List when streak bonus is active
- **Open Question Import**: CSV format `question,model_answer,open` and JSON `"type": "open"` with single model answer
- **Open Question in all modes**: Training, Leitner, Smart Queue, Exam (textarea + batch submit), Daily Challenge — SwipeMode filters out open questions

### Changed
- **XpService**: `getStreakMultiplier()` and `applyMultiplier()` replace linear `1.0 + (streak * 0.01)` formula
- **LeitnerService**: All XP awards (correct answer, mastery bonus, daily goal) now use streak multiplier via XpService
- **UserStateController**: `state()` response includes `xp_multiplier` field; `answerChallenge()` applies streak multiplier to challenge XP
- **QuestionForm.vue**: New "Free text" answer type option with model answer textarea
- **QuestionList.vue**: "Freitext" badge for open questions, model answer display, Export CSV/JSON dropdown
- **ImportDialog.vue**: Help text updated with open-question format examples for both CSV and JSON
- **SwipeMode.vue**: Filters out open questions (not compatible with swipe/tap interaction)
- **routes.php**: 2 new export routes (71 total)

### Technical
- 1 new file (`ExportController.php`), 22 changed files
- No database migration needed — `question_type` VARCHAR(20) already supports `open`, `answer_ids` TEXT stores JSON
- `QuestionService::isOpenAnswerCorrect()` static helper used by TrainingService, LeitnerService, and UserStateController
- Rate limit on export endpoints: 10 requests per 60 seconds

## [2.0.0] - 2026-02-26

### Added
- **Role-Based Navigation**: Students start at Courses view (no Pools tab), instructors keep both tabs. App title simplified to "Lernen" for students
- **Onboarding Hint System**: 7 dismissible info cards (localStorage-based) explaining Smart Queue, Leitner boxes, Daily Goal, Daily Challenge, and welcome messages for both roles
- **hintMixin.js**: Reusable Vue mixin for localStorage-backed hint dismiss/check across all components
- **Mode Descriptions**: One-line explanation shown below the active mode selector (Training, Leitner, Wahr/Falsch, Exam, Stats, Manage)
- **Pool-from-Course Navigation**: "Back to Course" button when opening a pool from course context (instead of losing context by switching to Pools view)

### Changed
- **App.vue**: Mode selector filtered by role — students see only Train/Leitner/Wahr-Falsch/Exam (no Stats, no Manage)
- **PoolList.vue**: Create Pool button, Share/Edit/Delete actions hidden for students. Better empty state text
- **CourseList.vue**: Student empty state now explains that the instructor enrolls them
- **LeitnerMode.vue**: Box labels improved — "Neu — taeglich wiederholen", "Lernphase — nach 1 Tag", "Bekannt — nach 3 Tagen", "Gut — nach 7 Tagen", "Gemeistert — nach 14 Tagen"
- **SmartQueue.vue**: Better empty state ("Open a pool and start the Leitner system"), ready state shows priority sorting info
- **PoolList.vue**: "Jetzt Lernen" → "Start learning", "Trouble Spots" → "Trouble spots" (consistency)
- **de.json**: ~60 new German translation keys for all hints, mode descriptions, empty states, and v1.8/v1.9 UI strings

## [1.9.0] - 2026-02-26

### Added
- **AI Question Generator**: Paste text (lecture notes, textbook excerpts) and let AI generate multiple-choice questions. Three-step workflow: input → AI generation → editable preview → bulk import into pool
- **AIService.php**: NC TaskProcessing API (NC 30+) with TextProcessing fallback (NC 29) — runtime detection, no app-level API key management
- **AIController.php**: 4 new endpoints — `GET /api/ai/available`, `POST /api/ai/generate`, `GET /api/ai/status/{taskId}`, `POST /api/ai/import/{taskId}`
- **AIGenerator.vue**: Full wizard with textarea input, question count slider (5-30), language selector, polling progress, editable preview with select/deselect all, inline editing of questions and answers
- **At-Risk Early Warning**: Instructors see which students are falling behind on the Course Progress tab. Rule-based risk scoring with 5 weighted signals (inactivity >7d, accuracy <50%, box-1 stall >60%, lost streak, <3 sessions in 14d)
- **At-Risk API**: `GET /api/courses/{courseId}/at-risk` — returns students with risk_level (high/medium), risk_reasons array, last_active, accuracy
- **Remediation Queue**: Focused practice on hardest questions (≥3 wrong answers, <30% accuracy, box ≤2). "Trouble Spots" button on Pool List with item count
- **Remediation API**: `GET /api/leitner/remediation` (items), `GET /api/leitner/remediation/count` (lightweight count)
- **Daily Challenge**: One random question per day from user's pools with +15 XP bonus for correct answers. Deterministic selection via `crc32(userId + date)` — same question per user per day
- **Daily Challenge API**: `GET /api/v1/daily-challenge`, `POST /api/v1/daily-challenge/answer`
- **Migration V001600**: Adds `last_challenge_date` (VARCHAR 10) and `last_challenge_correct` (BOOLEAN) to `learning_user_stats`

### Changed
- **QuestionList.vue**: "Generate with AI" button visible when AI provider is configured (checks `/api/ai/available`)
- **PoolList.vue**: "Trouble Spots" button (orange, warning style) and inline Daily Challenge card with answer submission
- **SmartQueue.vue**: New `mode` prop (`queue` | `remediation`) with conditional API endpoint, titles, and empty states
- **App.vue**: Wires `openRemediation` event from PoolList to SmartQueue with `mode="remediation"`, new `smartQueueMode` state
- **CourseDetail.vue**: At-Risk section on Progress tab — collapsible card grid with risk badges and reason tags
- **routes.php**: 9 new routes (ai: 4, leitner: 2, user_state: 2, course: 1) — 69 total

### Security
- **AI Rate Limits**: `generate` endpoint limited to 5/min, `import` limited to 10/min
- **AI Input Validation**: Text length 50-50000 chars, word count capped at 3000 for LLM input
- **AI User Isolation**: Task status checks verify `userId` matches task owner
- **At-Risk Access Control**: Endpoint restricted to course instructors via `validateInstructor()`

## [1.8.0] - 2026-02-26

### Added
- **Smart Queue**: Cross-pool "Jetzt Lernen" button — fetches all due Leitner cards across every pool, sorted by box (lowest first) then overdue time. One click to review everything
- **Smart Queue API**: `GET /api/leitner/queue` (items) and `GET /api/leitner/queue/count` (lightweight count for button badge)
- **SmartQueue.vue**: New review component with pool name badges, progress bar, per-pool result breakdown
- **Daily Goal**: Configurable daily review target (default: 20 cards) with circular SVG progress ring on Pool list
- **Daily Goal API**: `PUT /api/v1/user/settings` for persistent goal setting (5-200 range), `daily_progress` block in user state endpoint
- **Daily Goal XP Bonus**: +10 XP awarded when daily goal is reached for the first time each day
- **Level-Up Celebration**: Animated overlay when user levels up — star zoom, golden glow, auto-dismiss after 2.5s
- **LevelUpOverlay.vue**: New component with `prefers-reduced-motion` support, integrated into TrainingMode, LeitnerMode, SmartQueue
- **Level detection in responses**: `level_before`/`level_after` returned by `answerQuestion()` and `completeSession()`
- **Migration V001500**: Adds `daily_goal` column to `learning_user_stats`

### Changed
- **PoolList.vue**: Smart Queue button and Daily Goal ring displayed above search bar
- **UserStateController**: `state()` now includes `daily_progress` with `cards_reviewed_today`, `daily_goal`, `goal_reached`, `sessions_today`
- **routes.php**: 3 new routes (queue, queueCount, updateSettings) — 57 total

## [1.7.0] - 2026-02-26

### Added
- **Instructor Intelligence**: Three new features that make the instructor view useful
- **Progress Table Enhancement**: XP, Level, Last Active columns with sortable headers and display names — replaces N+1 queries with batch aggregation
- **Course Leaderboard**: New `GET /api/courses/{courseId}/leaderboard` endpoint with privacy-aware responses (students see limited fields, instructors see full data)
- **Student Detail View**: New `GET /api/courses/{courseId}/students/{studentId}` endpoint — XP, badges, streak, Leitner boxes per pool, recent sessions (instructor-only)
- **StudentDetail.vue**: New component with XP bar, badge grid, Leitner box visualization, session history
- **Leaderboard Tab**: Visible for both instructors and students; ranked by XP with medal indicators for top 3
- **Display Names**: All course views now show Nextcloud display names instead of raw user IDs (privacy improvement)
- **Composite DB Indices**: Migration V001400 adds `(user_id, pool_id, box)`, `(user_id, pool_id, completed_at)`, `(course_id, role)` for new query patterns
- **Utility functions**: `src/format.js` with `formatXp()` and `formatRelativeDateString()` used across all course components

### Changed
- **CourseService**: Refactored `getCourseProgress()` from N×4 queries per student per pool to 4 batch queries total — O(students × pools) → O(4)
- **CourseDetail.vue**: Tab selector now visible for students too (Pools + Leaderboard); instructor tabs expanded to 4 (+ Leaderboard)
- **App.vue**: Added `selectedStudent` state for drill-down navigation from leaderboard/progress to student detail

### Security
- **IDOR Protection**: `studentDetail()` explicitly verifies requesting user is instructor of the specific course
- **Privacy**: Student leaderboard view strips sensitive fields (streak, sessions, last_activity_date)
- **Rate Limits**: New endpoints have `UserRateLimit(30/60s)` to prevent scraping

## [1.6.0] - 2026-02-26

### Added
- **ConsistencyCheckJob**: Daily background job that reconciles `learning_user_stats` against source-of-truth tables — self-healing for XP, level, sessions, mastered counts, and streaks
- **Composite Leitner indices**: `(user_id, box)` and `(user_id, next_review)` on `learning_leitner_items` for faster aggregations and due-question queries (Migration Version001300)

### Fixed
- **HIGH**: `LeitnerService::answerQuestion()` now wraps box update, stats update, XP increment, and badge award in a single DB transaction — prevents partial writes on crashes
- **HIGH**: Optimistic concurrency control on Leitner item update (`WHERE box = :oldBox AND correct_count = :old AND incorrect_count = :old`) — detects and rejects concurrent modifications including Box-5→5 lost updates
- **HIGH**: Box-5 mastery bonus (+25 XP, +1 mastered, badge check) now only awarded on actual promotion (Box <5→5), not on repeated correct answers in Box 5 — prevents XP/stats inflation
- **HIGH**: Badge notifications and Activity events now dispatched after transaction commit — prevents ghost notifications on rollback
- **MEDIUM**: Demotion fallback (`updateUserStats()`) moved after Leitner item update — recalc now sees correct box value instead of overcounting
- **MEDIUM**: `syncLevel()` deferred to after transaction commit — eliminates lock contention under concurrent reviews
- **MEDIUM**: Cache invalidation moved after transaction commit (was inside write sequence, could serve stale data on rollback)
- **LOW**: Multi-select answer validation uses batch `IN` query instead of N+1 per-answer queries

### Performance
- Leitner box aggregations (stats, mastery counts) use composite index instead of full table scan
- Due-question queries benefit from `(user_id, next_review)` index — faster Leitner review loading
- ConsistencyCheckJob processes max 500 users per run, oldest-updated first — all users eventually reconciled
- ConsistencyCheckJob logs progress and catches per-user errors without aborting the batch

## [1.5.0] - 2026-02-26

### Added
- **Consolidated User State API**: `GET /api/v1/user/state` — single endpoint replaces 4 separate calls (XP, streak, badges, progress)
- **Denormalized `learning_user_stats` table**: O(1) reads for XP, level, streak, sessions, mastered counts (Migration Version001200)
- **Background Notification Job**: `NotificationJob` (TimedJob every 4h) replaces widget-triggered notifications
- **Activity-App Integration**: Badge unlock events appear in Nextcloud Activity stream with toggle in Activity settings
- **Timezone-aware badges**: Night Owl and Early Bird badges respect user's Nextcloud timezone setting
- **XpService**: Dedicated service for XP calculation, session XP increment, level computation
- **Response caching**: User state endpoint cached for 30s per user via ICacheFactory
- **Composite index** on `learning_sessions(user_id, completed_at)` for faster XP/streak queries
- **`time_limit_seconds` column** on `learning_sessions` for Speed Demon badge support

### Changed
- **BadgeService** refactored from 430 to ~200 lines — XP/streak methods extracted to XpService
- **Dashboard Widget** is now pure read-only (~140 lines) — no longer sends notifications as side-effect
- **AnalyticsDashboard.vue**: 4 API calls consolidated to 1, with fallback to legacy endpoints for rolling deploys
- **countUp.js**: Respects `prefers-reduced-motion` — immediately shows final value without animation
- **BadgeUnlock.vue**: Added `aria-live="polite"` region for screenreader announcements
- **LeitnerService**: Box-5 promotion updates denormalized `total_mastered` counter
- **TrainingService**: Session completion updates denormalized stats via XpService

### Fixed
- **HIGH**: Leitner XP now synced to `learning_user_stats` — correct answers award 5 XP, Box-5 mastery awards 25 XP bonus
- **HIGH**: Race condition in stats UPSERT — all INSERT paths wrapped in `UniqueConstraintViolationException` catch with UPDATE retry
- **HIGH**: SQL injection in `incrementSessionXp` — `$currentStreak` now uses `createNamedParameter()` instead of string concatenation
- **HIGH**: NotificationJob memory exhaustion — `fetchAll()` replaced with streaming `fetch()` loop; N+1 due-count queries replaced with single batch query
- **HIGH**: Level-race in XpService — stale `getTotalXpFromStats()` read eliminated; `syncLevel()` is monotonic (`AND current_level < :newLevel`) to prevent concurrent stale-level overwrites
- **HIGH**: Stats INSERT fallback uses `updateUserStats()` full recalc — preserves historical XP, sessions, and mastered counts for first-write users
- **HIGH**: `total_mastered` demotion — Box 5→1 transition now decrements `total_mastered` counter with `updateUserStats()` fallback for missing stats rows
- **MEDIUM**: User state cache invalidated on every Leitner answer (not just correct), after share-badge awards, and at END of `completeSession()` (after all badge/streak writes)
- **MEDIUM**: Migration backfill is per-step idempotent — each INSERT...SELECT skips existing users via `NOT IN` clause (safe for partial re-runs)
- **MEDIUM**: Migration backfill includes Leitner-only users (no completed sessions but have reviewed cards)
- **MEDIUM**: Frontend fallback triggers on any error (not just 404) for rolling-deploy resilience
- **LOW**: Removed dead `$totalMastered` variable in LeitnerService (computed but never used)

### Performance
- Dashboard load: 4 XHR → 1 XHR for gamification data
- XP calculation: O(n) SQL aggregate → O(1) stats table read
- Notifications: synchronous widget side-effect → async background job
- NotificationJob: filters to users active in last 30 days (skips dormant accounts on large instances)

## [1.4.0] - 2026-02-25

### Added
- **Achievements / Badges**: 14 badges across 6 categories (sessions, performance, mastery, streak, social, fun)
- **XP & Levels**: Experience points calculated from sessions, Leitner reviews, accuracy bonuses, and streak multiplier
- **Badge Unlock Overlay**: Animated full-screen overlay with confetti when earning a badge
- **Analytics Dashboard**: Achievements grid with earned/locked status, badge progress bars, XP level bar
- **Session Juice**: Animated score count-up (countUp.js), Personal Best detection, improvement vs. average display
- **XP display**: "+X XP" shown on all results screens (Training, Exam, Swipe, Leitner)
- **Nextcloud Notifications**: Badge unlock notifications, streak-at-risk warnings, due card reminders
- **Badge progress tracking**: API endpoint showing progress towards unearned badges
- New DB table `learning_user_badges` (Migration Version001100)
- 2 new API endpoints: `GET /api/badges`, `GET /api/badges/progress`

### Changed
- Training complete response now includes `newly_earned_badges`, `xp_earned`, `is_personal_best`, `improvement`, `average_accuracy`
- Leitner answer response now includes `newly_earned_badges` when card reaches Box 5
- Share creation now triggers badge check for "Sharing is Caring"
- Streak endpoint now triggers streak badge checks
- Dashboard widget sends streak warning and due reminder notifications

## [1.3.4] - 2026-02-19

### Security
- **CRITICAL**: Close training-session oracle — startSession(exam) auto-completes open training sessions on same pool
- **CRITICAL**: Defense-in-depth in submitAnswer/submitBatch — suppress correct_answer fields via cross-session check
- **HIGH**: Strip explanation field from Question API during active exam to prevent indirect answer leakage
- Rate-limits on ShareController and TranslationController write endpoints

### Fixed
- Leitner feedback shows hint when correct answers are suppressed during active exam

## [1.3.3] - 2026-02-19

### Fixed
- **MEDIUM**: Two-step confirmation when adding pools to courses — select then confirm to prevent accidental bulk-adds
- Highlighted pool selection in CourseDetail pool-add modal

## [1.3.2] - 2026-02-19

### Security
- **CRITICAL**: Strip `is_correct` from Question read APIs during active exam session (hasActiveExamOnPool guard)
- **HIGH**: Suppress Leitner correct answer details during active exam
- Rate-limits on Pool, Question, Image, Course create endpoints

### Fixed
- **MEDIUM**: answer_id nullable migration for multi-select on fresh install
- **MEDIUM**: getSessionReview ArgumentCountError on idempotent exam completion
- **MEDIUM**: Pool delete IDOR — ownership check before deleting related course_pools
- **MEDIUM**: is_correct boolean coercion using `filter_var`
- **LOW**: TrainingMode progress divide-by-zero guard

### Removed
- Pool.visibility dead code from jsonSerialize()

## [1.3.1] - 2026-02-19

### Security
- **CRITICAL**: Exam answer oracle prevention — session mode system blocks cheating via training API
- **HIGH**: Rate-limits on all write endpoints (Pool 30/60s, Question 60/60s, Import 5/60s, Training 120/60s, Leitner 120/60s, Share 20/60s)
- **HIGH**: Hard cap of 200 answers per submitBatch with session validation
- **MEDIUM**: Share user existence check and self-share prevention
- **MEDIUM**: Import body size limit (2 MB) and strict boolean coercion

### Added
- Multi-select question support across all training modes (Training, Leitner, Exam, Swipe)
- Dynamic answer fields (2-8) in QuestionForm with add/remove buttons

### UX/A11y
- Keyboard-accessible exam cards with tabindex, role=button, keydown handlers
- Keyboard support and 40px touch targets on exam nav-dots
- Replace native confirm/alert with NcDialog + NcNoteCard patterns
- Leitner due banner replaced with explicit NcButton

## [1.2.5] - 2026-02-18

### Fixed
- **HIGH**: PostgreSQL boolean cast in TrainingService — `filter_var` instead of `(bool)` cast
- **HIGH**: Leitner server-side answer validation instead of trusting client `is_correct`
- **MEDIUM**: FK constraints migration for course_pools/course_members with ON DELETE CASCADE
- **MEDIUM**: JSON import enforces exactly 1 correct answer per question
- **MEDIUM**: CourseDetail handles progress response format correctly
- **MEDIUM**: QuestionList pagination (50/page) with backend support
- **LOW**: Delete endpoints return 204 No Content consistently

### Added
- Snake timer SVG border animation in ExamMode with 3 color phases and pulse effect

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

