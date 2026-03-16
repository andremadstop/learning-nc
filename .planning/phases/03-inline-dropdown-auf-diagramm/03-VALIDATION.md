---
phase: 3
slug: inline-dropdown-auf-diagramm
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-16
---

# Phase 3 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | vitest 4.x (installed Phase 1) |
| **Config file** | `app/vitest.config.js` |
| **Quick run command** | `npm test` |
| **Full suite command** | `npm test` |
| **Estimated runtime** | ~2 seconds |

---

## Sampling Rate

- **After every task commit:** Run `npm test`
- **After every plan wave:** Run `npm test`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 5 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 03-01-01 | 01 | 1 | DROP-02, DROP-03 | unit | `npm test` | ❌ W0 | ⬜ pending |
| 03-01-02 | 01 | 1 | DROP-01 | manual | browser (positioning) | — | ⬜ pending |
| 03-01-03 | 01 | 1 | DROP-01, DROP-02, DROP-03 | manual | browser checkpoint | — | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `app/tests/unit/pbqScoringMode.test.js` — stubs for DROP-02, DROP-03 (scoringSummary display logic)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Dropdown appears at node position | DROP-01 | Requires real DOM + getBoundingClientRect() | Click node in browser, verify dropdown appears above node |
| Outside-click / Escape closes picker | DROP-01 | Browser interaction | Press Escape, verify dropdown closes |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
