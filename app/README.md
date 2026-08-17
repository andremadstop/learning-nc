# Learning - Nextcloud App

Spaced Repetition Learning with Leitner System for Nextcloud.

![Nextcloud](https://img.shields.io/badge/Nextcloud-29--31-blue)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple)
![License](https://img.shields.io/badge/License-AGPL--3.0-green)

## Features

### Learning Modes
- **Smart Queue** — Cross-pool "Jetzt Lernen" button reviews all due cards from every pool in one session, sorted by priority
- **Leitner System** — 5-box spaced repetition with automatic scheduling (1d, 3d, 7d, 14d intervals); optional Sprint mode (4h, 12h, 1d, 2d) for intensive courses
- **Training Mode** — Quick quiz sessions with immediate feedback
- **Exam Mode** — Timed exams with configurable question count and snake timer
- **Wahr/Falsch** — Touch-friendly swipe-based true/false flashcard review
- **Trouble Spots** — Focused practice on your hardest questions (3+ wrong, <30% accuracy)
- **Daily Challenge** — One random question per day with bonus XP reward
- **Simulator Practicum** — Guided step-by-step sessions for all 7 network simulators with real-world scenarios, progress tracking, and score summaries
- **Student Dashboard** — "Heute" landing page with due cards, daily challenge, streak, global course feed, and direct pool navigation

### Question Types
- **Multiple Choice** — Questions with 2-8 answers, explanations, difficulty levels, multi-select support
- **Free Text** — Open-ended questions where users type answers, matched against model answers with fuzzy matching (case-insensitive, typo-tolerant via Levenshtein distance)
- **AI Question Generator** — Paste text (lecture notes, textbook excerpts) and let AI generate multiple-choice questions with editable preview before import

### Gamification
- **XP & Levels** — Experience points from sessions, reviews, accuracy bonuses
- **14 Badges** — Achievements across 6 categories (sessions, performance, mastery, streak, social, fun)
- **XP Streak Multipliers** — Tier-based bonuses: 1.5x at 3-day streak, 2x at 7-day, 3x at 30-day
- **Daily Goal** — Configurable daily review target with visual progress ring and XP bonus
- **Daily Missions** — Fresh bonus-XP objectives each day (master cards, start sessions, clear trouble spots)
- **Streak Freeze Tokens** — One free token per week to protect streak when you miss a day
- **Level-Up Celebration** — Animated overlay when reaching a new level

### Course Management
- **Courses** — Instructors create courses, assign pools (single or batch), enroll students
- **Buddy Matching** — Students see who can help with their topics and whom they can help, based on Telos profile data
- **Talk Room Link** — Instructors link a Nextcloud Talk room per course; clickable shortcut in course header
- **Course Materials** — Students see course documents (read-only) from linked Nextcloud folders
- **Course-Aware Tools** — Werkzeuge tab filters available simulators based on active course settings
- **Leaderboard** — Ranked by XP with medal indicators and student detail drill-down; refreshes on tab switch and member changes
- **Student Progress** — Per-student XP, badges, streak, Leitner boxes per pool, session history
- **My Progress Tab** — Students can view their own detailed progress inside any enrolled course
- **At-Risk Warning** — Instructors see which students are falling behind with risk scores, reasons, and CSV export

### Data & Sharing
- **Pool Sharing** — Share with users (read-only or edit permissions)
- **CSV/JSON Import** — Bulk import questions from files
- **CSV/JSON Export** — Download question pools for backup or sharing (roundtrip-compatible with import)
- **Multi-Language** — Translate questions and answers into any language
- **Search** — Full-text search across all question pools

### Integration
- **Dashboard Widget** — See due questions from the Nextcloud Dashboard
- **ICS Calendar Subscription** — Subscribe to your due cards as a calendar feed (personal token URL, works with Thunderbird, iOS, Android)
- **AI Explanations** — 💡 button after each answer explains correct/wrong choices; uses Nextcloud AI provider; blocked during exams
- **Activity Integration** — Badge unlocks appear in Nextcloud Activity stream
- **Analytics** — Per-pool statistics with accuracy trends
- **Mobile Friendly** — Responsive touch-optimized design

## Installation

### From Nextcloud App Store (Recommended)

1. Go to **Apps** in your Nextcloud admin panel
2. Search for "Learning"
3. Click **Download and enable**

### Manual Installation

```bash
cd /path/to/nextcloud/custom_apps/
git clone https://codeberg.org/andremadstop/learning-nc.git learning
cd learning
npm install
npm run build
```

Then enable the app:
```bash
php occ app:enable learning
```

## Usage

### Creating a Pool

1. Open the **Learning** app from the navigation
2. Click **New Pool**
3. Enter a name and optional description
4. Add questions with multiple choice answers

### Importing Questions

1. Open a pool and go to the question list
2. Click the **Import** button
3. Choose CSV or JSON format
4. Paste data or upload a file
5. Preview and confirm import

**CSV Format (Multiple Choice):**
```csv
question,answer1,answer2,answer3,correct,explanation
What is 2+2?,3,4,5,2,Basic math
```

**CSV Format (Free Text):**
```csv
question,model_answer,open
What is the capital of France?,Paris,open
```

**JSON Format:**
```json
[
  {
    "text": "What is 2+2?",
    "answers": [
      {"text": "3", "is_correct": false},
      {"text": "4", "is_correct": true},
      {"text": "5", "is_correct": false}
    ],
    "explanation": "Basic math"
  },
  {
    "text": "What is the capital of France?",
    "type": "open",
    "answers": [
      {"text": "Paris", "is_correct": true}
    ]
  }
]
```

### Exporting Questions

1. Open a pool and go to the question list
2. Click the **Export** dropdown (next to Import)
3. Choose **CSV** or **JSON**
4. The file downloads automatically

Exported files are roundtrip-compatible — you can re-import them into any pool.

### Leitner Spaced Repetition

1. Open a pool and switch to **Leitner Mode**
2. Click **Initialize** to add all questions to Box 1
3. Review due questions daily
4. Correct answers move questions to the next box
5. Incorrect answers move questions back to Box 1

**Box Intervals:**
| Box | Review After |
|-----|-------------|
| Box 1 | Immediately |
| Box 2 | 1 day |
| Box 3 | 3 days |
| Box 4 | 7 days |
| Box 5 | 14 days (mastered) |

### Course Management (for Instructors)

1. Switch to the **Courses** tab
2. Click **New Course** and add a title and description
3. Assign question pools to the course
4. Add students by Nextcloud username
5. Track student progress from the **Instructor Dashboard**

Students can self-enroll in courses and see their progress across all assigned pools.

### Sharing Pools

1. Click the share icon on any pool
2. Search for a Nextcloud user
3. Choose permission level (Read or Edit)
4. Shared pools appear in the "Shared with me" tab

### OCC Commands

#### `learning:import-vault` — Import Obsidian Vault as RAG Knowledge Base

Import Markdown files from an Obsidian vault into the RAG chunk database for AI-powered explanations.

```bash
php occ learning:import-vault --path=/data/my-vault --course-id=20
```

- Recursively processes all `*.md` files
- Cleans Obsidian syntax (frontmatter, wikilinks, callouts, image embeds)
- Splits into ~500-token chunks with sentence-boundary awareness
- Idempotent: re-running deletes previous vault chunks for the same course
- Existing CourseDocument-based chunks are preserved

#### `learning:uninstall` — Remove all app data from the database

Nextcloud does not drop an app's migration-created tables on removal, and the only hook apps get
(`<repair-steps><uninstall>`) also fires on a plain *disable* — so an automatic drop would let one
stray click destroy every course. This command is the explicit alternative.

```bash
php occ learning:uninstall                               # dry run — prints the plan, changes nothing
php occ learning:uninstall --execute --keep-certificates # remove everything except issuer/certificates
php occ learning:uninstall --execute --drop-certificates # remove everything
php occ app:remove learning                              # then remove the app itself
```

- Dry run by default; `--execute` is required to change anything
- Drops all `learning_*` tables (including legacy names from earlier renames) and clears the rows
  this app leaves in `migrations`, `appconfig`, `preferences`, `jobs`, `notifications`, `activity`
  and `activity_mq`
- Deletes the `migrations` rows **before** dropping tables — leaving them behind makes a later
  reinstall skip every migration and boot broken
- Tables carrying the `learning_` prefix that this version does not know about are reported, not touched
- Refuses to run when issuer/certificate data exists unless you choose `--keep-certificates` or
  `--drop-certificates`: the Ed25519 issuer key is unrecoverable, and losing it makes every
  certificate ever issued permanently unverifiable
- Course documents, videos and images live in the users' own files and are never touched

## Requirements

- Nextcloud 29, 30, or 31
- PHP 8.1 or higher
- PostgreSQL 13+ or MySQL 8+

## Development

```bash
# Clone into custom_apps
cd /path/to/nextcloud/custom_apps/
git clone https://codeberg.org/andremadstop/learning-nc.git learning

# Install dependencies
cd learning
npm install

# Development build with watch
npm run dev

# Production build
npm run build
```

## License

AGPL-3.0 — see [LICENSE](LICENSE)
