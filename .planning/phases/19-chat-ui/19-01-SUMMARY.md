---
phase: 19
plan: 01
subsystem: frontend
tags: [chat-ui, virtuprof, vue, accessibility, rtl]
dependency_graph:
  requires: [VirtuProfBubble.vue, VirtuProf.vue, VirtuProfAvatar.vue, virtuprof-i18n.js, /api/virtu-prof/chat]
  provides: [chat UI in VirtuProfBubble, virtuprof:explain-question event, Explain via VirtuProf button]
  affects: [TrainingMode.vue, VirtuProf.vue, VirtuProfBubble.vue]
tech_stack:
  added: []
  patterns: [vue-options-api, root-event-bus, prop-down-event-up, scoped-css]
key_files:
  created: []
  modified:
    - app/src/components/VirtuProfBubble.vue
    - app/src/components/VirtuProf.vue
    - app/src/components/TrainingMode.vue
    - app/js/learning.js
decisions:
  - Chat state lives in VirtuProf.vue (not VirtuProfBubble) to maintain history across open/close cycles
  - Bubble always shows chat section (not conditional on helpView) — chat is always available
  - virtuprof:explain-question is a root event for loose coupling from learning modes to VirtuProf
  - API errors return user-friendly strings in the assistant bubble instead of alert dialogs
metrics:
  duration: 332s
  tasks_completed: 4
  files_modified: 3
  completed_date: "2026-03-21"
requirements: [CHAT-01, CHAT-02, CHAT-03, CHAT-04, CHAT-05]
---

# Phase 19 Plan 01: Chat-UI Summary

Chat interface integrated into VirtuProfBubble with session history, typing indicator, API wiring, and "Explain via VirtuProf" quick-action in TrainingMode.

## What Was Built

**VirtuProfBubble.vue** — extended with:
- `.chat-section` at the bottom: scrollable message history (max-height 240px, role=log, aria-live=polite)
- `.chat-msg--user` (right-aligned, primary color tint) and `.chat-msg--assistant` (left-aligned, background-hover) bubbles
- Typing indicator: three pulsing `.typing-dot` elements (animation disabled under `prefers-reduced-motion`)
- Chat input: single-line `<input>` with maxlength=500, Enter key support, disabled during loading
- Send button: circular primary-color button with send icon SVG, disabled when loading or empty
- RTL support: user/assistant bubble alignment flipped via `[dir="rtl"]` scoped selectors
- New props: `chatMessages` (Array), `chatLoading` (Boolean)
- New event emitted: `chat-send` with message string

**VirtuProf.vue** — extended with:
- `chatMessages[]`, `chatLoading`, `chatAnimationTimer` data properties
- `handleChatSend(message)`: pushes user bubble, calls POST /api/virtu-prof/chat, pushes assistant response or error message, enforces max 20 messages, manages talk→idle animation
- `handleExplainQuestion(payload)`: listens to `virtuprof:explain-question`, updates context (poolId/courseId/questionId), auto-sends explain message
- `clearPresentation()` now resets chat state
- Registered `virtuprof:explain-question` listener in mounted/beforeDestroy

**TrainingMode.vue** — extended with:
- `explainViaVirtuProf()` method that emits `virtuprof:explain-question` with question text, correct answers, poolId, courseId, questionId
- "Explain via VirtuProf" NcButton shown when `!isCorrect` in both open-question and multiple-choice answer feedback sections

## Decisions Made

1. **Chat state in VirtuProf.vue** — keeps VirtuProfBubble as pure display component; history persists across bubble minimize/expand cycles
2. **Always-visible chat section** — chat input is always shown at the bottom of the bubble, regardless of helpView state, giving users constant access
3. **Root event bus for explain-question** — loose coupling, learning modes don't need direct reference to VirtuProf
4. **User-friendly error messages in chat** — 400 (too long), 429 (rate limit), 503 (AI disabled), and generic errors all produce assistant-style bubbles instead of toast errors

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check: PASSED

- [x] VirtuProfBubble.vue modified: exists
- [x] VirtuProf.vue modified: exists
- [x] TrainingMode.vue modified: exists
- [x] Commits: 5212733, fcd6906, a805496, ac98427
- [x] Build: webpack compiled with 2 warnings (pre-existing size warnings, no errors)
- [x] Deploy: JS bundle deployed to learning-dev container
