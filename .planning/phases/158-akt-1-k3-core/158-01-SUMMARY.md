---
phase: 158-akt-1-k3-core
plan: "01"
subsystem: campaigns
tags: [ghostline, content, tdd, k3, lpic, grep]
dependency_graph:
  requires: []
  provides:
    - app/data/campaigns/ghostline_act1.json
    - app/tests/unit/ghostlineGraph.test.js
  affects: []
tech_stack:
  added: []
  patterns:
    - Pattern B anti-skip gate (terminal → unconditional → gate node → flagged → ending)
    - TERM-01 three-variant valid_commands (bare / single-quote / double-quote)
key_files:
  created:
    - app/data/campaigns/ghostline_act1.json
    - app/tests/unit/ghostlineGraph.test.js
  modified: []
decisions:
  - "Test file placed at app/tests/unit/ (not app/src/utils/__tests__/) — vitest include pattern is tests/unit/**/*.test.js; plan path would be silently uncollected"
  - "K3-04 anti-escape: gate node zero-ungated-exits assertion validated by negative run (bypass edge → K3-04 RED, revert → all GREEN)"
metrics:
  duration: "~15min"
  completed: "2026-06-30"
  tasks_completed: 2
  tasks_total: 2
  files_created: 2
  files_modified: 0
---

# Phase 158 Plan 01: ghostline_act1 Smoke + Structural Validator Summary

One-liner: 4-node K3 smoke campaign (Pattern B grep gate) + 12-assertion Vitest validator that proves both structure correctness and K3-04 anti-escape gate integrity.

## What Was Built

### app/tests/unit/ghostlineGraph.test.js

Vitest structural validator for `ghostline_act1.json`. 12 assertions covering:

| Group | Assertions |
|-------|-----------|
| Basic parse/schema | loads without error, campaign_id present |
| Start/ending | exactly 1 start:true, ≥1 is_ending:true, ≥1 type="ending" |
| Node types | all types ∈ {story, simulator, bot_correction, terminal, ending} |
| Act integrity | every node in exactly one act; all act node_ids reference real nodes |
| Edge integrity | all from/to reference existing node IDs |
| TERM-01 | ≥3 valid_commands per terminal, non-empty hint, exactly 1 required:true |
| K3-04 | terminal has ≥1 unconditional exit; gate node has ≥1 flagged exit AND zero ungated exits |

The K3-04 "zero ungated exits" assertion is the critical discriminator — it catches bypass edges that the "≥1 gated exit" check alone misses (CampaignGraphService only filters the gated edge when the flag is unset; an ungated edge survives and lets the player escape).

### app/data/campaigns/ghostline_act1.json

4-node smoke campaign:

```
a1_start (story, start:true)
  └─ e_start_to_grep1 (unconditional)
       ↓
a1_k3_grep1 (terminal, pass_flag: k3_grep1_passed)
  └─ e_grep1_to_gate (unconditional — Pattern B leg 1)
       ↓
a1_k3_grep1_gate (story — Pattern B gate node)
  └─ e_gate_to_end (conditions.requires_flag: k3_grep1_passed — Pattern B leg 2)
       ↓
a1_k3_end (type:ending, is_ending:true, effects: [set_flag claimed_ghost_box])
```

TERM-01 quote variants on a1_k3_grep1:
- `grep ^T ghost_journal.txt` — required:true
- `grep '^T' ghost_journal.txt` — required:false
- `grep "^T" ghost_journal.txt` — required:false

## Vitest Output (GREEN — final run)

```
 ✓ ghostline_act1.json structure > loads and parses without error
 ✓ ghostline_act1.json structure > has exactly 1 node with start:true
 ✓ ghostline_act1.json structure > has at least 1 node with is_ending:true
 ✓ ghostline_act1.json structure > has at least 1 node with type === "ending"
 ✓ ghostline_act1.json structure > uses only valid node types
 ✓ ghostline_act1.json structure > assigns every node to exactly one act
 ✓ ghostline_act1.json structure > all acts[] node_ids reference existing nodes
 ✓ ghostline_act1.json structure > all edge from/to values reference existing node IDs
 ✓ ghostline_act1.json structure > TERM-01: every terminal node has ≥3 valid_commands entries
 ✓ ghostline_act1.json structure > TERM-01: every terminal node has a non-empty hint
 ✓ ghostline_act1.json structure > TERM-01: every terminal node has exactly 1 valid_commands entry with required:true
 ✓ ghostline_act1.json structure > K3-04: every terminal has ≥1 unconditional exit, gate node has ≥1 correctly-flagged exit, and ZERO ungated exits (anti-escape)

 Test Files  1 passed (1)
       Tests  12 passed (12)
   Duration  2.36s
```

## K3-04 Negative Run (bypass validation)

Temporarily added ungated edge `e_gate_bypass_TEST_ONLY` from `a1_k3_grep1_gate` to `a1_k3_end`. K3-04 test specifically failed:

```
✗ K3-04: every terminal has ≥1 unconditional exit ... (anti-escape)
→ Gate node "a1_k3_grep1_gate" has 1 ungated exit(s): [e_gate_bypass_TEST_ONLY].
  Any ungated exit creates a bypass. All exits from a gate node must carry
  conditions.requires_flag.: expected 1 to be +0
```

11 other tests remained green — only K3-04 fired. Bypass edge reverted; all 12 green confirmed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Test file relocated from plan path to vitest-collected path**
- **Found during:** Task 1 setup
- **Issue:** Plan specified `app/src/utils/__tests__/ghostlineGraph.test.js` but vitest config `include: ['tests/unit/**/*.test.js']` would silently skip that path — "runs GREEN" success criterion unachievable
- **Fix:** Placed test at `app/tests/unit/ghostlineGraph.test.js` matching the project-wide convention (all 20+ existing unit tests live there)
- **Files modified:** `app/tests/unit/ghostlineGraph.test.js` (created at corrected path)
- **No other files touched** (vitest.config.js not modified — that would be scope drift)

## Commits

| Hash | Message |
|------|---------|
| 961c39c | test(158-01): add failing structural validator for ghostline_act1 |
| e117a9b | feat(158-01): add ghostline_act1 4-node smoke campaign (Pattern B + TERM-01) |

## Self-Check: PASSED

Files exist:
- FOUND: app/tests/unit/ghostlineGraph.test.js
- FOUND: app/data/campaigns/ghostline_act1.json

Commits exist:
- FOUND: 961c39c
- FOUND: e117a9b

ESLint: 0 errors on ghostlineGraph.test.js
Vitest: 12/12 passed
PHP files touched: none
Vue files touched: none
