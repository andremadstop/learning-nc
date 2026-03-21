---
phase: 26
plan: 01
subsystem: virtu-prof
tags: [chat-memory, persistence, gemini, privacy]
dependency_graph:
  requires: [Phase 19 (Chat-UI), Phase 22 (Lernprofil), GeminiService]
  provides: [AiChatMemoryService, persistent-chat-context, clear-history-endpoint]
  affects: [VirtuProfController, GeminiService, VirtuProf.vue, VirtuProfBubble.vue]
tech_stack:
  added: [AiChatMemoryService, AiChatMemory entity, AiChatMemoryMapper]
  patterns: [QBMapper pattern, memory compression via Gemini, graceful fallback]
key_files:
  created:
    - app/lib/Service/AiChatMemoryService.php
    - app/lib/Db/AiChatMemory.php (committed in Phase 27 job 80f5da0)
    - app/lib/Db/AiChatMemoryMapper.php (committed in Phase 27 job 80f5da0)
    - app/lib/Migration/Version004000Date20260321000000.php (committed in Phase 27 job 80f5da0)
  modified:
    - app/lib/Service/GeminiService.php (new $memoryEntries param + buildMemoryAddendum)
    - app/lib/Controller/VirtuProfController.php (inject AiChatMemoryService, load/save memory)
    - app/appinfo/routes.php (GET + DELETE /api/virtu-prof/chat-history)
    - app/src/components/VirtuProf.vue (loadChatHistory, clearChatHistory, action handler)
    - app/src/components/VirtuProfBubble.vue (clear button + CSS)
    - app/l10n/de.json (Clear chat history translation)
decisions:
  - "Memory entries are injected as system prompt addendum (not Gemini conversation history API) — avoids billing implications of multi-turn context and stays within existing callGeminiApi() architecture"
  - "loadMemory() returns only 10 most recent entries to Gemini to prevent token overflow; DB stores up to 50"
  - "Compression via generateNote() endpoint — reuses existing trusted-caller path that bypasses user rate limits"
  - "Summary entries are stored with role=summary but filtered from getChatHistory response (internal compression detail)"
  - "DB files (migration/entity/mapper) were committed by Codex in 80f5da0 as part of Phase 27 scaffolding — adopted as-is"
metrics:
  duration_seconds: 8594
  completed_date: "2026-03-21"
  tasks: 6
  files_created: 4
  files_modified: 6
---

# Phase 26 Plan 01: VirtuProf Chat-Memory Summary

**One-liner:** Persistent per-user chat context via `learning_ai_chat_memory` table, injected into Gemini system prompt as previous-conversation addendum, capped at 50 entries with Gemini-based compression.

## What Was Built

### DB Layer (MEM-01)
- `learning_ai_chat_memory` table: `id`, `user_id`, `role` (user/assistant/summary), `message` (TEXT), `created_at` (BIGINT)
- Index on `(user_id, created_at)` for per-user ordered queries
- `AiChatMemory` entity + `AiChatMemoryMapper` with `findRecentByUser`, `findOldestByUser`, `countByUser`, `deleteByIds`, `deleteAllByUser`

### AiChatMemoryService (MEM-01, MEM-02, MEM-03)
- `loadMemory(userId)`: returns last 10 entries (oldest-first) as `[{role, message}]` array
- `saveExchange(userId, userMsg, assistantMsg)`: inserts user + assistant rows, then enforces 50-entry cap
- `enforceCapIfNeeded()`: compresses oldest 10 entries into a summary row via `GeminiService::generateNote()`, falls back to static string if Gemini fails
- `clearMemory(userId)`: deletes all entries for user (MEM-04)
- `loadRecentEntries(userId, limit)`: returns AiChatMemory entities for the history endpoint

### GeminiService::chat() (MEM-02)
- Added optional `$memoryEntries` 4th parameter
- New `buildMemoryAddendum()` method appends "Previous conversations" section to the system prompt
- Entries truncated to 200 chars, labelled `[User]`, `[VirtuProf]`, `[Earlier Summary]`
- Addendum instructs Gemini "do not repeat explanations already given"

### VirtuProfController (MEM-01, MEM-02, MEM-04)
- `AiChatMemoryService` injected via constructor (NC DI autowires it)
- `chat()`: loads memory before Gemini call, saves exchange on successful non-fallback response
- `getChatHistory()`: `GET /api/virtu-prof/chat-history` — returns last 20 entries (role + text)
- `clearChatHistory()`: `DELETE /api/virtu-prof/chat-history` — rate-limited 5/min

### Frontend (MEM-01, MEM-04)
- `VirtuProf.vue`: `loadChatHistory()` called in `mounted()` when AI is enabled; populates `chatMessages` from server
- `VirtuProf.vue`: `clearChatHistory()` calls DELETE endpoint, resets `chatMessages = []`
- `VirtuProfBubble.vue`: "Clear chat history" link appears below chat input when messages exist
- German translation added: "Chatverlauf löschen"

## Deviations from Plan

### Pre-existing work by Codex (not a fix — adoption)

**DB files committed in Phase 27 scaffolding (80f5da0)**
- Found during: Task 1
- Situation: `AiChatMemory.php`, `AiChatMemoryMapper.php`, `Version004000Date20260321000000.php` were already in git, committed as Phase 27 preparation by Codex. The files matched what this plan intended.
- Action: Adopted as-is. My Write tool created the files with identical content, but git already had the committed version. No re-commit needed for Task 1.

### No other deviations — plan executed as written.

## Success Criteria Verification

| Criterion | Status |
|-----------|--------|
| MEM-01: Chat context persists in DB, loads on mount | PASS |
| MEM-02: Bot receives previous conversations in each Gemini call | PASS |
| MEM-03: Max 50 entries, compression on overflow | PASS |
| MEM-04: DELETE /api/virtu-prof/chat-history clears all memory | PASS |

## Self-Check: PASSED

All 4 created/adopted files exist on disk. All 3 phase-26 commits present in git log:
- `09ff759`: backend (AiChatMemoryService + GeminiService + VirtuProfController)
- `25c4241`: routes (GET + DELETE /api/virtu-prof/chat-history)
- `2d9f80d`: frontend (loadChatHistory, clearChatHistory, clear button)
