---
phase: 18
plan: 01
subsystem: ai-rag
tags: [rag, gemini, virtuprof, context, privacy]
dependency_graph:
  requires: [17-02]
  provides: [rag-context-builder, gemini-rag-integration]
  affects: [VirtuProfController, GeminiService]
tech_stack:
  added: [RagContextService]
  patterns: [RAG context injection, token budget trimming, backward-compatible extension]
key_files:
  created:
    - app/lib/Service/RagContextService.php
  modified:
    - app/lib/Service/GeminiService.php
    - app/lib/Controller/VirtuProfController.php
decisions:
  - "RAG context passed as plain array (not typed DTO) to keep DI simple and maintain backward compat"
  - "Token budget uses strlen/4 approximation — fast, no external library, sufficient for 4000-token guard"
  - "Questions trimmed from the END (least relevant) first — first N questions most representative of pool"
  - "RagContextService uses raw IDBConnection queries (not service layer) to avoid access-control entanglement"
  - "Controller builds context only when at least one context param is non-null — no overhead on legacy calls"
metrics:
  duration: 175s
  tasks_completed: 3
  files_created: 1
  files_modified: 2
  completed_date: "2026-03-21"
---

# Phase 18 Plan 01: RAG Context Builder Summary

**One-liner:** RagContextService loads pool questions + Leitner stats + course name + last wrong answer, capped at 4000 tokens, injected into GeminiService system prompt via VirtuProfController.

## What Was Built

### RagContextService.php (new)

Service at `app/lib/Service/RagContextService.php` implementing `buildContext(userId, poolId, courseId, lastWrongQuestionId): array`.

Loads four data categories via direct IDBConnection queries:
- Pool name + up to 15 questions with all answers (RAG-01)
- Last wrong question + correct answer text (RAG-02)
- Course name + Leitner box distribution box_1..box_5 + total (RAG-03)
- Token budget enforced by trimming questions until strlen(json) / 4 <= 4000 (RAG-04)

Privacy: No userId, username or email in the returned payload — only content data.

### GeminiService.php (extended)

- `chat()` signature extended to `chat(string $rawInput, string $userId, array $ragContext = [])` — fully backward compatible
- New `buildRagSystemAddendum(array $ragContext): string` formats context as compact text block
- `buildSystemPrompt()` appends RAG addendum when non-empty
- Empty `$ragContext` = no change to system prompt (zero impact on existing behaviour)

### VirtuProfController.php (extended)

- `chat()` accepts three new optional POST params: `poolId`, `courseId`, `lastWrongQuestionId`
- `RagContextService` injected via constructor (NC auto-wires via DI)
- Context built only when at least one param is non-null
- All existing callers without params continue to work unchanged

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check: PASSED

| Check | Result |
|-------|--------|
| app/lib/Service/RagContextService.php | FOUND |
| app/lib/Service/GeminiService.php | FOUND |
| app/lib/Controller/VirtuProfController.php | FOUND |
| commit 1050ff3 (RagContextService) | FOUND |
| commit e1f83f2 (GeminiService extension) | FOUND |
| commit fa71c2a (Controller wiring) | FOUND |
