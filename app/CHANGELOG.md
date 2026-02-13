# Changelog

All notable changes to this project will be documented in this file.

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
