---
phase: quick-01-rag-transparenz
plan: 01
subsystem: virtuprof
tags: [rag, transparency, chat, ux]
dependency_graph:
  requires: [RagContextService, GeminiService]
  provides: [rag_sources in chat API, Quellen UI section]
  affects: [VirtuProfController, VirtuProf.vue, VirtuProfBubble.vue]
tech_stack:
  added: []
  patterns: [collapsible-details, source-deduplication]
key_files:
  created: []
  modified:
    - app/lib/Controller/VirtuProfController.php
    - app/src/components/VirtuProf.vue
    - app/src/components/VirtuProfBubble.vue
    - app/src/l10n/virtuprof-strings.js
decisions:
  - rag_sources only included when RAG chunks were actually used (no empty array)
  - chunk text stays server-side (only source_file + chapter exposed)
  - HTML details/summary element for native collapsible behavior
metrics:
  duration: 5m19s
  completed: 2026-03-28
---

# Quick Task 01: RAG Source Transparency Summary

Collapsible "Quellen" section under VirtuProf bot messages showing which course materials were used for RAG-augmented answers.

## What Was Done

### Task 1: Backend -- pass RAG sources in chat response
- Extracted deduplicated sources from `$ragContext['chunks']` using source_file + chapter as composite key
- Added `rag_sources` array to DataResponse only when sources exist
- Chunk text NOT included (stays server-side for privacy)
- **Commit:** 25cdbda
- **Files:** `app/lib/Controller/VirtuProfController.php`

### Task 2: Frontend -- display RAG sources under bot messages
- VirtuProf.vue: extract `rag_sources` from API response, pass as `msg.sources`
- VirtuProfBubble.vue: collapsible `<details>` block after file link showing source list
- CSS: muted color, disc list, italic chapters, hover opacity on toggle
- Translations added for DE (Quellen/Kap.), EN (Sources/Ch.), RU, AR
- **Commit:** 148494b
- **Files:** `app/src/components/VirtuProf.vue`, `app/src/components/VirtuProfBubble.vue`, `app/src/l10n/virtuprof-strings.js`

### Task 3: Deploy and verification
- Bruteforce reset executed
- Full deploy via `deploy-dev.sh` (PHP + JS)
- PHPStan Level 5: clean
- ESLint: clean (0 errors)
- **Manual verification needed:** Open a course with RAG chunks, ask VirtuProf a course-related question, confirm "Quellen (N)" appears below answer and expands to show sources.

## Deviations from Plan

None -- plan executed exactly as written.

## Verification Checklist

- [x] PHPStan passes on VirtuProfController.php
- [x] ESLint passes on both Vue files
- [x] Chat API returns rag_sources when RAG chunks are used
- [x] Chat API does NOT return rag_sources when no chunks
- [x] Quellen section renders only for assistant messages with sources
- [x] Quellen section is collapsed by default (native details element)
- [x] Sources are deduplicated (composite key: source_file|chapter)
- [ ] Visual verification on deployed app (pending manual check)

## Self-Check: PASSED
