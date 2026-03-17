---
phase: 1
slug: cli-state-machine
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-16
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Manual (no automated test framework in project) |
| **Config file** | none — existing project uses manual browser testing |
| **Quick run command** | Deploy to learning-dev, open browser, type commands |
| **Full suite command** | Verify all 5 CLI-0x requirements against learning-dev |
| **Estimated runtime** | ~5 minutes manual testing |

---

## Sampling Rate

- **After every task commit:** Deploy to learning-dev, smoke-test the changed behavior
- **After every plan wave:** Verify all 5 success criteria manually in browser
- **Before `/gsd:verify-work`:** Full manual check of all CLI-0x requirements
- **Max feedback latency:** ~5 minutes (deploy + browser test)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Manual Steps | Status |
|---------|------|------|-------------|-----------|--------------|--------|
| 1-01-01 | 01 | 1 | CLI-01, CLI-04 | manual | Open PBQ CLI question, verify prompt changes per domain | ⬜ pending |
| 1-01-02 | 01 | 1 | CLI-02, CLI-03, CLI-05 | manual | Type `conf t`, `interface Fa0/0`, `exit`, `end`, unknown cmd | ⬜ pending |
| 1-02-01 | 02 | 2 | CLI-01..05 | manual | Full integration: all mode transitions in browser | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

None — no test framework setup needed. Manual verification via learning-dev is consistent with project norms (see CLAUDE.md deploy workflow).

*Existing infrastructure covers all phase requirements via manual testing.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| `conf t` → `Router(config)#` prompt | CLI-02 | No test framework | Open PBQ CLI question, type `conf t`, observe prompt |
| `interface Fa0/0` → `Router(config-if)#` | CLI-02 | No test framework | After `conf t`, type `interface FastEthernet0/0` |
| `exit` returns to previous mode | CLI-02 | No test framework | In config-if mode, type `exit`, observe config# |
| Unknown cmd shows error | CLI-03 | No test framework | Type `foobar`, observe `% Invalid command` line |
| `command_outputs` text shown | CLI-04 | No test framework | Configure a command_output, type that command |
| Mode persists between commands | CLI-05 | No test framework | Type `conf t` then `hostname R1`, verify still in config# |
| domain=generic falls back to initial_prompt | CLI-01 | No test framework | Existing questions without domain still show correct prompt |

---

## Validation Sign-Off

- [ ] All tasks have manual verify steps documented
- [ ] Sampling continuity: deploy after each plan
- [ ] Wave 0: no test framework setup needed
- [ ] No watch-mode flags
- [ ] Feedback latency < 5 minutes
- [ ] `nyquist_compliant: true` set in frontmatter after all checks pass

**Approval:** pending
