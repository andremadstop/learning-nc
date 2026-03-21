---
phase: 24
plan: 01
subsystem: note-generator
tags: [gemini, obsidian, markdown, lernbot, note-generation]
dependency_graph:
  requires: [phase-22-lernprofil, phase-23-nc-files-integration, phase-17-gemini-backend]
  provides: [note-generation-api, gemini-note-method]
  affects: [GeminiService, LernbotFileService, routes]
tech_stack:
  added: [NoteGeneratorService, NoteGeneratorController]
  patterns: [pool-access-check, gemini-internal-call, obsidian-frontmatter-strip, slug-stable-filename]
key_files:
  created:
    - app/lib/Service/NoteGeneratorService.php
    - app/lib/Controller/NoteGeneratorController.php
  modified:
    - app/lib/Service/GeminiService.php (added generateNote())
    - app/appinfo/routes.php (added noteGenerator#generate route)
decisions:
  - Reused existing LernbotFileService from Phase 23 (already complete, not a stub)
  - FOLDER_SUMMARIES constant was private in LernbotFileService — used string literal 'Zusammenfassungen'
  - generateNote() in GeminiService bypasses 500-char user input limit (internal call, not user input)
  - NoteGeneratorService strips any Gemini-generated frontmatter and replaces with authoritative meta
  - slugify() normalizes German umlauts (ä→ae etc.) for cross-platform filenames
  - Pool access check in Controller: ownership OR pool_share OR course membership/instructor
metrics:
  duration: 32min
  completed: 2026-03-21
  tasks_completed: 4
  files_created: 2
  files_modified: 2
---

# Phase 24 Plan 01: Note-Generator Summary

Gemini generates Obsidian-compatible topic summaries for weak pools; notes are saved as NC files with YAML frontmatter, wiki-links, and tags. Second call to same pool updates the existing note rather than creating a duplicate.

## What Was Built

### GeminiService::generateNote() (Task 4 — first due to dependency order)

New `public function generateNote(string $systemPrompt, string $userPrompt): string` on `GeminiService`.

- Bypasses user rate-limit and 500-char validation (internal/trusted caller)
- maxOutputTokens: 800, temperature: 0.5 (optimized for summaries, not chat)
- Writes audit log with `event_key = 'note_generation'`, `userId = 'system'`
- Throws `\RuntimeException` on failure — caller handles gracefully
- No PII in method

### NoteGeneratorService (Task 2)

`app/lib/Service/NoteGeneratorService.php` — core logic:

**`generateSummary(string $userId, int $poolId, ?int $courseId): array`**
1. Loads pool name + chapter_ref from DB
2. Gets error rate from LernprofilService::aggregateProfile()
3. Loads top 5 most-wrong question texts (JOIN user_answers + sessions + questions)
4. Determines content_language from IConfig user setting
5. Builds system prompt (Obsidian format, no PII)
6. Calls GeminiService::generateNote()
7. Strips any Gemini-generated frontmatter
8. Writes via LernbotFileService::writeNote() with authoritative YAML meta
9. Returns `{path, updated, content}`

**`updateExistingNote()`** — alias to generateSummary (NOTE-04: same filename = overwrites).

**Privacy**: Only pool_name, error_rate (float), and question texts go to Gemini. No userId, username, email.

### NoteGeneratorController (Task 3)

`app/lib/Controller/NoteGeneratorController.php`:
- `POST /api/notes/generate` — `@NoAdminRequired`, `#[UserRateLimit(limit: 3, period: 60)]`
- Validates `pool_id` (positive int required), optional `course_id`
- Pool access guard: ownership OR pool_share OR course membership/instructor role
- Returns 200 `{path, updated, content}`, 400 on bad input, 403 on access denied, 503 on Gemini down

### Route (Task 3)
```php
['name' => 'noteGenerator#generate', 'url' => '/api/notes/generate', 'verb' => 'POST'],
```

### LernbotFileService (Task 1 — pre-existing)
Phase 23 had already fully implemented `LernbotFileService.php` with `ensureLearningFolder()`, `writeNote()`, `buildFrontmatter()`, `listNotes()`. No changes needed.

## Generated Note Format

```markdown
---
created: 2026-03-21T15:30:00+01:00
updated: 2026-03-21T15:30:00+01:00
source: VirtuProf
topic: VLAN Konfiguration
status: '#schwach'
chapter: Netzwerk-Grundlagen
tags: [#schwach, #lernbot]
---

## Kernpunkte
- VLANs segmentieren Netzwerke auf Layer 2...
- Trunk-Ports übertragen tagged Frames mehrerer VLANs...

## Häufigster Fehler
Der häufigste Fehler ist das Vergessen des `encapsulation dot1Q` Befehls...

## Übungsempfehlung
Wiederhole die Konfiguration im Pool [[VLAN Konfiguration]]. Verknüpfe mit [[Netzwerk-Grundlagen]].

#schwach #lernbot
```

## Deviations from Plan

**1. [Rule 2 - Pre-existing] LernbotFileService already complete from Phase 23**
- Found during: Task 1 check
- Issue: Plan assumed LernbotFileService would be a stub — it was fully implemented
- Fix: Skipped stub creation, adapted NoteGeneratorService to use actual API (`writeNote($userId, $subfolder, $filename, $bodyMarkdown, $meta)`)
- No commit needed for Task 1

**2. [Rule 1 - Bug] LernbotFileService::FOLDER_SUMMARIES constant was private**
- Found during: PHPStan run after Task 2
- Issue: NoteGeneratorService referenced `LernbotFileService::FOLDER_SUMMARIES` which is `private const`
- Fix: Changed to string literal `'Zusammenfassungen'` in NoteGeneratorService private const
- Commit: d0832eb (fixed before commit)

**3. [Rule 3 - Blocking] LernprofilService not deployed to container**
- Found during: PHPStan (Phase 22 service missing from container)
- Fix: rsync'd full lib/Service/ + lib/Controller/ dirs to learning-dev and docker cp'd to container
- No code change, deployment fix only

## Self-Check: PASSED

- app/lib/Service/NoteGeneratorService.php: FOUND
- app/lib/Controller/NoteGeneratorController.php: FOUND
- app/lib/Service/GeminiService.php (generateNote): FOUND
- Route noteGenerator#generate: FOUND in routes.php
- Commits: 06127a1, d0832eb, 9142680 — all present in git log
