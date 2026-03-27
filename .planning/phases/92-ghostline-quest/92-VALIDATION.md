---
phase: 92
slug: ghostline-quest
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-03-27
---

# Phase 92 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Vitest (JS Unit) + PHPStan Level 5 (PHP Static) + ESLint |
| **Config file** | `app/vitest.config.js`, `app/phpstan.neon` |
| **Quick run command** | `cd app && npx vitest run --reporter=verbose` |
| **Full suite command** | `cd app && npx vitest run && npx eslint --ext .js,.vue src/` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `cd app && npx vitest run --reporter=verbose`
- **After every plan wave:** Run full suite + PHPStan
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 92-01-01 | 01 | 1 | GHOST-01 | unit | `npx vitest run terminal-puzzle SimulatorShell` | W0 (created in task) | pending |
| 92-01-02 | 01 | 1 | GHOST-01 | lint+static | `npx eslint --ext .js,.vue src/ && PHPStan` | N/A | pending |
| 92-01-03 | 01 | 1 | GHOST-04 | static | `PHPStan analyse DauBotService.php` | N/A | pending |
| 92-02-01 | 02 | 2 | GHOST-01,02,03,04 | structural | Python verify script (JSON structure, all 7 tool types, pacing) | N/A | pending |
| 92-02-02 | 02 | 2 | ALL | manual | Browser E2E playthrough | N/A | pending |

*Status: pending / green / red / flaky*

---

## Wave 0 Requirements

- [ ] `app/tests/unit/terminal-puzzle.test.js` — stubs for TerminalPuzzle component (created by Plan 01 Task 1 as part of TDD)

Plan 01 Task 1 creates terminal-puzzle.test.js as part of TDD workflow (test-first). Campaign-level validation (JSON structure, tool type coverage, pacing rules) is handled by Plan 02's inline Python verify script rather than separate Wave 0 test stubs — this is appropriate because campaign JSON is declarative data, not testable logic.

*Existing Vitest infrastructure covers framework — only terminal-puzzle test file needed.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Ghostline visual glitch effects | GHOST-01 | CSS animations not testable in jsdom | Open campaign, verify glitch effects active during Ghostline scenes |
| Story engagement / pacing | GHOST-02 | Subjective quality | Play through campaign, verify rhythm Story->Quiz->Simulator->Story |
| Simulator embedded in quest scene | GHOST-04 | Requires full browser rendering | Complete a simulator challenge within campaign flow |
| Campaign auto-discovery | GHOST-01 | Requires running StoryEngineService | Verify campaign appears in campaigns list after deploy |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 15s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
