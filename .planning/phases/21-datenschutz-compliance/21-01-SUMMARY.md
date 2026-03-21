---
phase: 21
plan: 01
subsystem: privacy-compliance
tags: [dsgvo, gdpr, opt-in, ai, virtuprof, admin-settings]
dependency_graph:
  requires: [Phase 17 GeminiService, Phase 18 RagContextService, Phase 19 Chat-UI]
  provides: [PRIV-01 consent flow, PRIV-02 admin toggle, PRIV-03 docs, PRIV-04 audit, PRIV-05 DPA hint]
  affects: [VirtuProfBubble.vue, VirtuProf.vue, VirtuProfController.php, AdminSettings.vue]
tech_stack:
  added: []
  patterns: [localStorage consent flag, Vue prop-driven conditional UI, PHP docblock audit trail]
key_files:
  created: []
  modified:
    - app/src/components/VirtuProf.vue
    - app/src/components/VirtuProfBubble.vue
    - app/lib/Controller/VirtuProfController.php
    - app/lib/Service/GeminiService.php
    - app/lib/Service/RagContextService.php
    - app/src/components/AdminSettings.vue
    - app/appinfo/info.xml
    - README.md
decisions:
  - localStorage for consent (not IConfig) — avoids API roundtrip, consent is per-browser per-user, acceptable for opt-in UX
  - Expose ai_enabled via existing /api/virtuprof/state endpoint — no new endpoint needed, consistent with how frontend loads VirtuProf state
  - info.xml privacy element uses thirdPartyLibraries + privacyPolicies — matches Nextcloud app store schema
  - @privacy-audit docblocks in PHP — code-level audit trail, serves as implementation contract
metrics:
  duration: 414s
  tasks_completed: 5
  files_modified: 8
  completed_date: "2026-03-21"
---

# Phase 21 Plan 01: Datenschutz & Compliance Summary

**One-liner:** DSGVO-compliant AI feature with localStorage opt-in consent, admin toggle surfaced in UI, privacy docs in info.xml and README, and Google DPA link in AdminSettings.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1+2 | PRIV-01+02: Opt-in consent + ai_enabled guard | bf2f239 | VirtuProf.vue, VirtuProfBubble.vue, VirtuProfController.php |
| 3 | PRIV-03: Privacy documentation | b10952d | README.md, app/appinfo/info.xml |
| 4 | PRIV-04: Privacy audit docblocks | 523402a | GeminiService.php, RagContextService.php |
| 5 | PRIV-05: DPA hint in AdminSettings | dc35576 | AdminSettings.vue |

## What Was Built

### PRIV-01: User Opt-in Consent (VirtuProf.vue + VirtuProfBubble.vue)

- `handleChatSend()` in VirtuProf.vue checks `aiChatConsent` before sending any message
- If no consent: buffers the message in `pendingChatMessage`, sets `showAiConsentDialog = true`, opens bubble
- VirtuProfBubble.vue renders a consent overlay with "I agree" / "Cancel" buttons when `showConsentDialog` prop is true
- On accept: consent stored in `localStorage` key `learning:ai_chat_consent` = `accepted`, buffered message sent
- On decline: pending message cleared, dialog closed
- Consent persists across page reloads (localStorage) — dialog never shown again on the same browser

### PRIV-02: Frontend hides chat when AI disabled (VirtuProf.vue + VirtuProfBubble.vue + VirtuProfController.php)

- VirtuProfController::getState() now returns `ai_enabled` (boolean) in the state response
- VirtuProf.vue reads `response.data?.ai_enabled` in loadState(), stores in `aiEnabled` data property
- `aiEnabled` prop passed to VirtuProfBubble
- VirtuProfBubble wraps the entire chat section in `v-if="aiEnabled"` — when false, chat input is invisible
- Backend still returns `{"error": "AI feature disabled"}` for direct API calls when `ai_enabled=no`

### PRIV-03: Privacy Documentation

- `app/appinfo/info.xml`: added `<privacy>` element with `<thirdPartyLibraries>` (Google Gemini API) and `<privacyPolicies>` (EN + DE)
- `README.md`: added "Privacy & AI" section with table of what IS and IS NOT sent to Gemini, admin controls, rate limiting, and links to Google Privacy Policy + DPA

### PRIV-04: No PII in LLM Context

**Audit findings (code-level verification):**
- `RagContextService::buildContext()`: userId used only as DB query parameter, never returned in context array. Output contains: pool_name, pool_questions (text only), leitner_stats (numeric), course_name, last_wrong (question text + answer text). No userId, email, display name.
- `GeminiService::buildSystemPrompt()`: receives $ragContext — confirmed no PII fields. Language name (e.g. "German") derived from ISO code, not user attribute. userId only appears in writeAuditLog() which writes to local DB, not Gemini API.
- Added `@privacy-audit` docblocks to both methods documenting included/excluded fields.

### PRIV-05: Google DPA hint in AdminSettings

- Added "VirtuProf AI Assistant" section to AdminSettings.vue above the Save button
- Section contains:
  - `NcNoteCard` info box with DPA hint and link to https://cloud.google.com/terms/data-processing-addendum
  - `ai_enabled` toggle (switch style, loads from and saves to API)
  - Gemini API Key password input (masked, placeholder shows "(key saved)" when key exists)
- Load/save wired to existing `/api/settings/admin` GET/PUT endpoints (backend was already ready)

## Verification Results

- `GET /api/virtuprof/state` returns `ai_enabled: false` (correct, not yet configured)
- `POST /api/virtu-prof/chat` with `ai_enabled=no` returns `{"error": "AI feature disabled"}` (correct 503)
- Frontend build: `webpack compiled with 2 warnings` (size warnings only, no errors)
- PHP pre-commit: `PHPStan clean` for all changed files

## Decisions Made

1. **localStorage for consent** — Per-browser, per-user; no API roundtrip; resets if user clears browser data (acceptable for opt-in UX, not a security control)
2. **ai_enabled in existing state endpoint** — Avoids adding a new endpoint; VirtuProf already loads state on mount
3. **info.xml `<privacy>` element** — Uses Nextcloud app store schema for privacy disclosure
4. **@privacy-audit docblocks** — Code-level audit trail that serves as implementation contract for future code reviewers

## Deviations from Plan

None — plan executed exactly as written. Tasks 1 and 2 were implemented together in one commit since they are tightly coupled (both involve VirtuProfBubble.vue changes).

## Self-Check: PASSED

All 8 modified files exist on disk. All 4 commits found in git log:
- bf2f239 feat(21-01): PRIV-01+02 AI opt-in consent + frontend ai_enabled guard
- b10952d feat(21-01): PRIV-03 privacy documentation in README + info.xml
- 523402a feat(21-01): PRIV-04 privacy audit docblocks on GeminiService + RagContextService
- dc35576 feat(21-01): PRIV-05 AI settings + Google DPA hint in AdminSettings
