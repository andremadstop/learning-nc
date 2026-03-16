---
phase: 2
slug: svg-topology-renderer
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-16
---

# Phase 2 — Validation Strategy

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
| 02-01-01 | 01 | 1 | SVG-01 | unit | `npm test` | ❌ W0 | ⬜ pending |
| 02-01-02 | 01 | 1 | SVG-02 | unit | `npm test` | ❌ W0 | ⬜ pending |
| 02-01-03 | 01 | 1 | SVG-01, SVG-02 | unit | `npm test` | ❌ W0 | ⬜ pending |
| 02-02-01 | 02 | 2 | SVG-03 | manual | browser | — | ⬜ pending |
| 02-02-02 | 02 | 2 | SVG-04 | manual | browser | — | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `app/tests/unit/networkTopologyIcons.test.js` — stubs for SVG-01, SVG-02 (DEVICE_ICONS + renderNode)
- [ ] `app/tests/unit/networkTopologySvg.test.js` — stubs for SVG-01 (schema parsing, link rendering)

*Existing vitest infrastructure from Phase 1 covers setup — only test stubs needed.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Hotspot click position after CSS scaling | SVG-03 | Requires real browser + getScreenCTM() | Load topology in browser, click node, verify overlay position matches node center |
| PbqPlacement topology mode renders correctly | SVG-04 | Full Vue component tree + NC app shell | Open PBQ placement question with topologyConfig, verify SVG renders and hotspots work |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
