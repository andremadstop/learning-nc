# Learning - Nextcloud App

Spaced Repetition Learning with Leitner System for Nextcloud.

![Nextcloud](https://img.shields.io/badge/Nextcloud-29--31-blue)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple)
![License](https://img.shields.io/badge/License-AGPL--3.0-green)

## Features

- **Question Pools** — Organize questions into themed pools
- **Multiple Choice** — Questions with 2-8 answers, explanations, difficulty levels
- **Leitner System** — 5-box spaced repetition with automatic scheduling
- **Training Mode** — Quick quiz sessions with immediate feedback
- **Exam Mode** — Timed exams with configurable question count and batch submission
- **Swipe Mode** — Touch-friendly swipe-based flashcard review
- **Course Management** — Instructors create courses, assign pools, track student progress
- **Pool Sharing** — Share with users (read-only or edit permissions)
- **CSV/JSON Import** — Bulk import questions from files
- **Multi-Language** — Translate questions and answers into any language
- **Search** — Full-text search across all question pools
- **Analytics** — Per-pool statistics with accuracy trends
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
cd learning/app
npm install
npm run build
```

Then enable the app:
```bash
php occ app:enable learning
```

## Usage

See the full [User Guide](app/README.md) for detailed instructions on:

- Creating and managing question pools
- Importing questions via CSV/JSON
- Using Leitner spaced repetition
- Setting up courses as an instructor
- Sharing pools with other users

## Repository Structure

```
app/            # The Nextcloud app (this gets installed)
  appinfo/      # App metadata and routes
  lib/          # PHP backend (Controllers, Services, Mappers, Migrations)
  src/          # Vue.js frontend source
  js/           # Built frontend (webpack output)
  css/          # Stylesheets
  img/          # Icons and images
  l10n/         # Translations
  templates/    # PHP templates
docker-compose.yml  # Local development environment
```

## Development

```bash
# Start local dev environment
docker-compose up -d

# Install frontend dependencies
cd app && npm install

# Development build with watch
npm run dev

# Production build
npm run build
```

## Requirements

- Nextcloud 29, 30, or 31
- PHP 8.1 or higher
- PostgreSQL 13+ or MySQL 8+

## License

AGPL-3.0 — see [LICENSE](app/LICENSE)
