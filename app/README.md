# Learning - Nextcloud App

Spaced Repetition Learning with Leitner System for Nextcloud.

![Nextcloud](https://img.shields.io/badge/Nextcloud-29--31-blue)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple)
![License](https://img.shields.io/badge/License-AGPL--3.0-green)

## Features

- **Question Pools** — Organize questions into themed pools
- **Multiple Choice** — Questions with 2-6 answers, explanations, difficulty levels
- **Leitner System** — 5-box spaced repetition with automatic scheduling
- **Training Mode** — Quick quiz sessions with immediate feedback
- **Pool Sharing** — Share with users (read-only or edit permissions)
- **CSV/JSON Import** — Bulk import questions from files
- **Dashboard Widget** — See due questions from the Nextcloud Dashboard
- **Mobile Friendly** — Responsive touch-optimized design

## Installation

### From Nextcloud App Store (Recommended)

1. Go to **Apps** in your Nextcloud admin panel
2. Search for "Learning"
3. Click **Download and enable**

### Manual Installation

```bash
cd /path/to/nextcloud/custom_apps/
git clone https://github.com/andremadstop/learning-nc.git learning
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

**CSV Format:**
```csv
question,answer1,answer2,answer3,correct,explanation
What is 2+2?,3,4,5,2,Basic math
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
  }
]
```

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

### Sharing Pools

1. Click the share icon on any pool
2. Search for a Nextcloud user
3. Choose permission level (Read or Edit)
4. Shared pools appear in the "Shared with me" tab

## Requirements

- Nextcloud 29, 30, or 31
- PHP 8.1 or higher
- PostgreSQL 13+ or MySQL 8+

## Development

```bash
# Clone into custom_apps
cd /path/to/nextcloud/custom_apps/
git clone https://github.com/andremadstop/learning-nc.git learning

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
