---
phase: 23
plan: 01
subsystem: nc-files-integration
tags: [files-api, obsidian, markdown, lernbot, phase23]
dependency_graph:
  requires: [22-01-PLAN.md]
  provides: [LernbotFileService, lernbot-files-api]
  affects: [Phase 24 Note-Generator, Phase 25 Lernplan]
tech_stack:
  added: [OCP\Files\IRootFolder, YAML frontmatter generation]
  patterns: [NC Files API write pattern, Obsidian-compatible Markdown]
key_files:
  created:
    - app/lib/Service/LernbotFileService.php
    - app/lib/Controller/LernbotFilesController.php
    - .planning/phases/23-nc-files-integration/23-01-PLAN.md
  modified:
    - app/appinfo/routes.php
decisions:
  - IRootFolder injected via autowire — no manual Application.php registration needed
  - Content passed verbatim — service does NOT sanitize body (Wiki-Links + Tags preserved)
  - writeNote is idempotent — putContent() overwrites on same filename = same topic
  - Subfolder created on demand inside writeNote — caller need not pre-create custom subfolders
  - YAML scalar quoting is conservative (single-quote on any special chars)
metrics:
  duration: 10min
  completed: 2026-03-21
  tasks_completed: 4
  files_created: 3
  files_modified: 1
---

# Phase 23 Plan 01: NC Files Integration Summary

**One-liner:** LernbotFileService writes Obsidian-compatible Markdown notes into NC user home /Learning/ via IRootFolder, with YAML frontmatter and two REST endpoints.

## What Was Built

### LernbotFileService (`app/lib/Service/LernbotFileService.php`)

Four public methods fulfilling all FILES-* requirements:

- **`ensureLearningFolder(string $userId): Folder`** (FILES-01, FILES-03) — creates `/Learning/`, `/Learning/Zusammenfassungen/`, `/Learning/Schwachstellen/` idempotently on every bot action.
- **`buildFrontmatter(array $meta): string`** (FILES-04) — generates YAML frontmatter block with ordered keys: `created`, `updated`, `source`, `topic`, `status`, `chapter`, `tags`. Defaults `created` and `updated` to ISO 8601 if not provided. Custom keys are appended after standard ones.
- **`writeNote(userId, subfolder, filename, bodyMarkdown, meta)`** (FILES-02, FILES-04, FILES-05) — creates or updates `.md` files. Body is written verbatim — Wiki-Links `[[...]]` and tags `#schwach` are preserved. Creates subfolder on demand. Enforces `.md` extension, rejects path traversal.
- **`listNotes(userId, subfolder): array`** (FILES-02) — lists `.md` files in `/Learning/` or a subfolder, returns `[name, path, modified]` sorted by mtime DESC. Returns `[]` gracefully if folder doesn't exist.

### LernbotFilesController (`app/lib/Controller/LernbotFilesController.php`)

- **`GET /api/lernbot/files`** — lists notes, optional `?subfolder=` query param. Rate-limited 60/min.
- **`POST /api/lernbot/note`** — creates or updates a note. Accepts JSON body with `subfolder`, `filename`, `body`, `meta`. Returns `{path, action: 'created'|'updated'}`. Rate-limited 20/min.
- Input validation: filename non-empty, no `/`, `\`, `..`. Subfolder sanitized against traversal.

### Routes (`app/appinfo/routes.php`)

Two routes added under `// Lernbot Files (Phase 23)` comment.

## Verification Results

- PHP syntax: all 3 new/modified files pass `php -l` in container
- Routes: 2 `lernbotFiles` entries confirmed in routes.php
- PHPStan: clean (pre-commit hook passed on all 3 commits)

## Decisions Made

1. **No manual DI registration** — NC Framework autowires `IRootFolder` by type in constructors; `Application.php` unchanged.
2. **Body verbatim** — `writeNote` does NOT sanitize `$bodyMarkdown`. Phase 24 (Note-Generator) embeds `[[Wiki-Links]]` and `#tags` — the service must not strip them.
3. **Idempotent by filename** — same `subfolder + filename` = same topic = overwrite. This satisfies FILES-02 ("update instead of duplicate") and NOTE-04.
4. **Conservative YAML quoting** — single-quotes any string with special YAML chars, ISO dates, or numeric-looking values to avoid parsing ambiguity in Obsidian.
5. **Subfolder auto-create** — `writeNote` creates the requested subfolder inside `/Learning/` if it doesn't exist, so Phase 24+ can write to custom subfolders without a separate setup call.

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check

- [x] `app/lib/Service/LernbotFileService.php` — FOUND
- [x] `app/lib/Controller/LernbotFilesController.php` — FOUND
- [x] `app/appinfo/routes.php` contains 2 lernbotFiles routes — FOUND
- [x] Commits efe4871, bec4979, 965d8ae — FOUND

## Self-Check: PASSED
