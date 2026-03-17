---
phase: 5
slug: pbq-author-tool
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-17
---

# Phase 5 — Validation Strategy

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
| 05-01-01 | 01 | 1 | AUTHOR-01, AUTHOR-02 | unit | `npm test` | ⬜ W0 |
| 05-01-02 | 01 | 1 | AUTHOR-01, AUTHOR-02 | unit | `npm test` | ⬜ pending |
| 05-01-03 | 01 | 1 | AUTHOR-01 | manual | browser checkpoint | ⬜ pending |
| 05-02-01 | 02 | 2 | AUTHOR-03 | manual | browser checkpoint | ⬜ pending |
| 05-02-02 | 02 | 2 | AUTHOR-02 | manual | browser checkpoint | ⬜ pending |

## Wave 0 Requirements

- [ ] `app/tests/unit/pbqAuthorTool.test.js` — stubs for AUTHOR-01, AUTHOR-02 (config generation logic)

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Type selector + form fields render | AUTHOR-01 | Vue component rendering | Open author tool in browser |
| Live preview updates on input | AUTHOR-03 | Reactive rendering | Change field, verify preview updates |
| JSON copy/insert into QuestionForm | AUTHOR-02 | UI interaction | Click copy, paste into question form |

## Validation Sign-Off

- [ ] All tasks have automated verify or Wave 0 dependencies
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
