---
phase: 158-akt-1-k3-core
plan: "02"
subsystem: campaigns
tags: [ghostline, content, k3, lpic, grep, sed, unix-history, pattern-b]
dependency_graph:
  requires:
    - app/data/campaigns/ghostline_act1.json (4-node smoke from 158-01)
    - app/tests/unit/ghostlineGraph.test.js (validator from 158-01)
  provides:
    - app/data/campaigns/ghostline_act1.json (9-node full K3 arc)
  affects: []
tech_stack:
  added: []
  patterns:
    - Pattern B anti-skip gate (terminal → unconditional → gate → flagged → next chapter) — both terminals
    - TERM-01 three-variant valid_commands (bare / single-quote / double-quote) — both terminals
    - Edge-based quiz (2 exit edges as answer choices + quiz field for structural compliance)
    - History vignette as in-world Geist-Erinnerung (STORY-03 Bell Labs / 1969 / grep etymology)
    - claimed_ghost_box via effects.set_flag on ending node (STORY-04)
key_files:
  created: []
  modified:
    - app/data/campaigns/ghostline_act1.json
decisions:
  - "Quiz implemented as edge-based choices (not inline quiz rendering) per engine fact from RESEARCH.md: quiz field is NOT rendered by AbenteuerMode.vue in graph mode; populated quiz field retained for K3-03 structural compliance (grep check)"
  - "Terminal 2 uses scenario 'ghost_journal_sed1' + pass_flag 'k3_grep2_passed' — naming deliberately asymmetric to original grep1 to allow 158-03 to handle them independently"
  - "9 edges, not 10 — plan interfaces block says '10 edges' but only 9 are explicitly listed; 9 is the correct count for the described arc (plan comment was a typo)"
  - "Terminal outputs stay as [PLACEHOLDER — TERM-02 fills this] per CRITICAL-5 — 158-03 replaces with real shell output"
metrics:
  duration: "~20min"
  completed: "2026-06-30"
  tasks_completed: 2
  tasks_total: 2
  files_created: 0
  files_modified: 1
---

# Phase 158 Plan 02: ghostline_act1 Full K3 Arc Summary

One-liner: 9-node K3 arc expanded from 4-node smoke — grep + sed terminals with Pattern B gates, edge-based BRE/ERE quiz, Bell Labs 1969 history vignette, claimed_ghost_box ending flag.

## What Was Built

### app/data/campaigns/ghostline_act1.json (9-node full arc)

Complete K3 node graph:

```
a1_start (story, start:true)
  └─ e_start_to_grep1 (unconditional)
       ↓
a1_k3_grep1 (terminal, pass_flag: k3_grep1_passed)
  └─ e_grep1_to_gate (unconditional — Pattern B leg 1)
       ↓
a1_k3_grep1_gate (story — Pattern B gate node)
  └─ e_gate1_to_quiz (conditions.requires_flag: k3_grep1_passed — Pattern B leg 2)
       ↓
a1_k3_quiz (story — quiz node: edge-based + quiz field)
  ├─ e_quiz_correct → a1_k3_grep2 (correct answer edge)
  └─ e_quiz_wrong → a1_k3_quiz_wrong (wrong answer edge)
                         └─ e_quiz_wrong_to_grep2 (unconditional)
                              ↓
a1_k3_grep2 (terminal, pass_flag: k3_grep2_passed)
  └─ e_grep2_to_gate (unconditional — Pattern B leg 1)
       ↓
a1_k3_grep2_gate (story — Pattern B gate node)
  └─ e_gate2_to_history (conditions.requires_flag: k3_grep2_passed — Pattern B leg 2)
       ↓
a1_k3_history (story — Bell Labs 1969 vignette)
  └─ e_history_to_end (unconditional)
       ↓
a1_k3_end (type:ending, is_ending:true, effects:[{set_flag:claimed_ghost_box}])
```

### Terminal 1 — grep ^T (a1_k3_grep1)

TERM-01 quote variants:
- `grep ^T ghost_journal.txt` — required:true
- `grep '^T' ghost_journal.txt` — required:false
- `grep "^T" ghost_journal.txt` — required:false

All outputs: `[PLACEHOLDER — TERM-02 fills this]` (158-03 replaces)

### Terminal 2 — sed s/ERROR/WARN/g (a1_k3_grep2, "faded practice")

TERM-01 quote variants:
- `sed 's/ERROR/WARN/g' ghost_journal.txt` — required:true
- `sed "s/ERROR/WARN/g" ghost_journal.txt` — required:false
- `sed s/ERROR/WARN/g ghost_journal.txt` — required:false

All outputs: `[PLACEHOLDER — TERM-02 fills this]` (158-03 replaces)
max_attempts: 6 (reduced from 8 — "faded" / less scaffolding)

### Quiz Node (a1_k3_quiz)

BRE vs ERE exam trap (CONT-02), implemented as:
- 2 exit edges as answer choices (engine renders these, not the quiz field)
- quiz field populated for K3-03 structural compliance (object-array shape from RESEARCH.md):
  ```json
  "quiz": {
    "question": "Was ist der Unterschied zwischen grep 'a+' und grep -E 'a+'?",
    "options": [
      {"id": "a", "text": "Mit -E aktiviert + den ERE-Quantifier (1 oder mehr)"},
      {"id": "b", "text": "Kein Unterschied — beide matchen auf ein oder mehr 'a'-Zeichen"}
    ],
    "correct": "a",
    "explanation": "BRE behandelt + literal; ERE (grep -E oder egrep) interpretiert + als 'ein oder mehr'. ..."
  }
  ```

### History Vignette (a1_k3_history — STORY-03)

Bell Labs 1969 narrative: Ken Thompson built grep from ed's g/re/p command in one night. Framed as in-world Geist-Erinnerung (memory from the ghost). Atmospheric Noir/Mystery tone — no textbook framing, no "Lernziel:". NPC dialog from "Der Geist" (emotion: nostalgic).

### Ending (a1_k3_end — STORY-04)

- type: "ending" (triggers epilog in AbenteuerMode.vue line 1243)
- is_ending: true (StoryEngine validation)
- effects: [{set_flag: "claimed_ghost_box"}]

## Vitest Output (GREEN — Task 2)

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
   Duration  2.19s
```

ESLint: 0 errors, 0 warnings on ghostlineGraph.test.js.

## Structural Verification

| Check | Result |
|-------|--------|
| JSON valid | OK |
| Node count | 9 |
| Edge count | 9 |
| acts[0].node_ids count | 9 |
| Start nodes | 1 (a1_start) |
| Ending nodes | 1 (a1_k3_end, type:ending + is_ending:true) |
| Flagged edges | 2 (e_gate1_to_quiz:k3_grep1_passed, e_gate2_to_history:k3_grep2_passed) |
| Gate1 ungated exits | 0 |
| Gate2 ungated exits | 0 |
| claimed_ghost_box | present in a1_k3_end.effects |
| PLACEHOLDER count | 6 (3 per terminal — ready for 158-03) |
| Quiz options type | array of objects (id + text) |
| Quiz correct type | string ("a") |

## Deviations from Plan

### Auto-fixed Issues

None - plan executed exactly as written.

### Notes

- Plan interfaces block says "10 edges" but lists exactly 9 edges; built 9 (the diagram and edge list are authoritative, the count is a typo)
- No PHP, no Vue, no FEATURED edit — scope-sentinel respected

## Commits

| Hash | Message |
|------|---------|
| 1b70ed5 | feat(158-02): expand ghostline_act1 to full 9-node K3 arc |

## Self-Check: PASSED

Files exist:
- FOUND: app/data/campaigns/ghostline_act1.json

Commits exist:
- FOUND: 1b70ed5 (feat(158-02): expand ghostline_act1 to full 9-node K3 arc)

ESLint: 0 errors
Vitest: 12/12 passed
PHP files touched: none
Vue files touched: none
