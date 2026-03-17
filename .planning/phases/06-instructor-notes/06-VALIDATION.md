---
phase: 6
slug: instructor-notes
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-17
---

# Phase 6 — Validation Strategy

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | vitest 4.x |
| **Config file** | `app/vitest.config.js` |
| **Quick run command** | `npm test` |
| **Full suite command** | `npm test` |
| **Estimated runtime** | ~2 seconds |

## Sampling Rate

- **After every task commit:** `npm test`
- **After every plan wave:** `npm test`
- **Max feedback latency:** 5 seconds

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | Status |
|---------|------|------|-------------|-----------|-------------------|--------|
| 06-01-01 | 01 | 1 | NOTE-04 | unit (PHP) | manual grep | ⬜ pending |
| 06-01-02 | 01 | 1 | NOTE-01, NOTE-02, NOTE-03 | unit (JS) | `npm test` | ⬜ W0 |
| 06-02-01 | 02 | 2 | NOTE-01, NOTE-02 | manual | browser checkpoint | ⬜ pending |
| 06-02-02 | 02 | 2 | NOTE-03 | manual | browser checkpoint | ⬜ pending |

## Wave 0 Requirements

- [ ] `app/tests/unit/instructorNote.test.js` — stubs for NOTE-01, NOTE-02, NOTE-03 (visibility logic)

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| DB migration runs without data loss | NOTE-04 | Requires live DB | Run migration, verify existing questions intact |
| Note visible to student in TrainingMode | NOTE-03 | Requires NC app shell | Toggle note_visible=true, verify display in training |
| Note hidden when note_visible=false | NOTE-03 | Requires NC app shell | Toggle off, verify note not shown |

## Validation Sign-Off

- [ ] All tasks have automated verify or Wave 0 dependencies
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
