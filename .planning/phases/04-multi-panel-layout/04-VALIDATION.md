---
phase: 4
slug: multi-panel-layout
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-17
---

# Phase 4 — Validation Strategy

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
| 04-01-01 | 01 | 1 | PANEL-01 | unit | `npm test` | ⬜ pending |
| 04-01-02 | 01 | 1 | PANEL-01, PANEL-02 | manual | browser checkpoint | ⬜ pending |

## Wave 0 Requirements

- [ ] `app/tests/unit/pbqMultiPanel.test.js` — stubs for PANEL-01 (config parsing, panel selection logic)

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Side-by-side layout renders correctly | PANEL-01 | Requires real DOM/CSS | Open multi-panel PBQ in browser, verify split view |
| Responsive stacks on small screens | PANEL-02 | Requires browser resize | Resize window below 768px, verify vertical stack |
| Both panels interactive simultaneously | PANEL-01 | Interaction testing | Type CLI command AND click SVG node in same question |

## Validation Sign-Off

- [ ] All tasks have automated verify or Wave 0 dependencies
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
