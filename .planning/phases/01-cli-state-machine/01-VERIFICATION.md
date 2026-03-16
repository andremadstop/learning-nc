---
phase: 01-cli-state-machine
verified: 2026-03-16T21:45:00Z
status: human_needed
score: 7/7 must-haves verified
re_verification: false
human_verification:
  - test: "Open a CLI PBQ question with domain: 'cisco_ios' and terminal name 'Router' in the browser"
    expected: "Prompt shows 'Router>'; typing 'conf t' changes it to 'Router(config)#'; typing 'interface Fa0/0' changes it to 'Router(config-if)#'; typing 'exit' goes back to 'Router(config)#'; typing 'end' goes back to 'Router>'"
    why_human: "Mode transition rendering and prompt update require visual inspection of the live Vue component in a running Nextcloud instance"
  - test: "In the same question, type an unknown command such as 'xyzzy'"
    expected: "Terminal shows '% Invalid input detected at '^' marker.' as an output line; prompt stays at 'Router>'"
    why_human: "Error output display requires browser verification; automated grep cannot confirm Vue reactivity rendering"
  - test: "In the same question, type a command configured in command_outputs (e.g. 'show version')"
    expected: "Terminal renders the configured output text as one or more plain-text lines; no HTML tags visible"
    why_human: "command_outputs rendering and multi-line split require live browser verification"
  - test: "Load a question with NO domain field and initial_prompt: 'Switch>'"
    expected: "Prompt shows exactly 'Switch>' — not 'Switch> ' with extra space, not 'generic>' or similar"
    why_human: "Backward compat branch depends on runtime config shape; requires a real question config to trigger"
  - test: "In a cisco_ios question, type 'conf t', then type 'ip address 192.168.1.1 255.255.255.0'"
    expected: "The ip address command line is recorded in history with prompt 'Router(config)#' (mode persisted from previous conf t)"
    why_human: "Mode persistence across multiple commands requires live session state to be observed"
---

# Phase 1: CLI State Machine Verification Report

**Phase Goal:** Implement CLI state machine for PBQ terminal simulation
**Verified:** 2026-03-16T21:45:00Z
**Status:** human_needed — all automated checks passed, 5 browser-only scenarios pending
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | DOMAIN_SCHEMAS exports all five domains (cisco_ios, linux, windows, sql, generic) | VERIFIED | Lines 16-92 of cliStateMachine.js — all five keys present as top-level exports |
| 2 | evaluateCommand() returns a result object with type, output lines, and next mode | VERIFIED | Lines 137-195 — all four return paths emit { type, nextMode, nextContext, lines } |
| 3 | getPrompt() returns correct prompt string for any domain/mode/host/context | VERIFIED | Lines 107-115 — schema lookup, effectiveMode fallback, promptFn fallback, host+'> ' final fallback |
| 4 | cisco_ios defines exec, config, config-if modes with correct per-mode transition rules | VERIFIED | Lines 18-50 — exec transitions (conf t, configure terminal, enable), config transitions (exit->exec, end->exec), config-if transitions (exit->config, end->exec) |
| 5 | command_outputs lookup is case-insensitive and exact-match | VERIFIED | Line 174 — `Object.keys(outputs).find(k => k.toLowerCase() === normalized)` |
| 6 | Unknown commands produce the domain-appropriate error message | VERIFIED | Lines 187-193 — errorMsg dispatched as string or function(cmd.trim()), returned in lines[0] |
| 7 | Backward compat: absent domain falls back to generic schema | VERIFIED | PbqCli.vue line 66 — `if (!this.config.domain && term.initial_prompt) return term.initial_prompt`; cliStateMachine.js line 108 — `DOMAIN_SCHEMAS[domain] || DOMAIN_SCHEMAS.generic` |

**Score: 7/7 truths verified**

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/src/utils/cliStateMachine.js` | DOMAIN_SCHEMAS, evaluateCommand, getPrompt | VERIFIED | 196 lines, zero external imports, all three exports present |
| `app/src/components/PbqCli.vue` | State-machine-aware CLI terminal component | VERIFIED | 144 lines, import wired, termModes/termContexts in data(), currentPrompt() method, submitCommand() uses evaluateCommand() |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| PbqCli.vue script block | cliStateMachine.js | `import { evaluateCommand, getPrompt, DOMAIN_SCHEMAS } from '../utils/cliStateMachine'` | WIRED | Line 35 — all three exports imported |
| PbqCli.vue submitCommand() | evaluateCommand() | called on every Enter keypress with cmd, domain, currentMode, context, commandOutputs | WIRED | Line 85 — `const result = evaluateCommand(cmd, domain, currentMode, context, commandOutputs)` |
| PbqCli.vue template | currentPrompt() method | `{{ currentPrompt(term) }}` in input row span | WIRED | Line 19 — template bound to method; method delegates to getPrompt() at line 69 |
| PbqCli.vue emit | TrainingService scoring contract | `$emit('update', term.name, [...history])` — history format: "PROMPT CMD" per entry | WIRED | Line 103 — full history copy emitted; cmdLine built as `promptStr + ' ' + cmd` at line 83 |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| CLI-01 | 01-01, 01-02 | PbqCli supports domain field (cisco_ios/linux/windows/sql/generic) for prompt schema selection | SATISFIED | DOMAIN_SCHEMAS has all 5 domains; PbqCli.vue reads `this.config.domain` to select schema |
| CLI-02 | 01-02 | Cisco IOS: conf t enters config mode, interface X enters config-if mode, exit/end return correctly | SATISFIED (automated) / HUMAN for visual | State machine transitions verified in code; browser test needed for visual confirmation |
| CLI-03 | 01-02 | Unknown commands show context-appropriate error message | SATISFIED (automated) / HUMAN for visual | Step 4 error fallback verified in code; browser rendering needs human check |
| CLI-04 | 01-01, 01-02 | command_outputs dict enables configured feedback text per command | SATISFIED (automated) / HUMAN for visual | Step 3 lookup verified; multi-line split on '\n' verified at line 177; browser rendering needs human check |
| CLI-05 | 01-02 | State machine persists mode between commands within a question session | SATISFIED | termModes updated via $set at line 96; currentMode read from termModes at line 76 on next submitCommand |

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| — | — | None found | — | — |

No TODOs, FIXMEs, placeholder returns, empty handlers, or v-html usage detected in either file.

---

### Human Verification Required

#### 1. Cisco IOS Mode Transitions (CLI-02)

**Test:** Open a CLI PBQ question in the browser with this config:
```json
{
  "domain": "cisco_ios",
  "hint": "Configure the loopback interface",
  "terminals": [{ "name": "Router", "initial_prompt": "Router>" }],
  "command_outputs": { "show version": "Cisco IOS Version 15.2" },
  "evaluation": [{ "terminal": "Router", "required_pattern": "ip address", "points": 1 }]
}
```
Type in sequence: `conf t`, `interface Fa0/0`, `exit`, `end`

**Expected:** Prompts cycle through Router>, Router(config)#, Router(config-if)#, Router(config)#, Router>

**Why human:** Vue reactivity rendering and prompt display require visual browser inspection

---

#### 2. Error Message Rendering (CLI-03)

**Test:** In the same question, type `xyzzy`

**Expected:** Terminal shows output line `% Invalid input detected at '^' marker.` and prompt remains at Router>

**Why human:** Output line rendering via Vue v-for in history array requires visual inspection

---

#### 3. command_outputs Rendering (CLI-04)

**Test:** Type `show version` in the cisco_ios terminal

**Expected:** Terminal shows `Cisco IOS Version 15.2` as a plain-text output line (no HTML tags)

**Why human:** Plain-text rendering (no v-html confirmed in code) needs visual confirmation that no escape artifacts appear

---

#### 4. Backward Compatibility (CLI-01)

**Test:** Load a question with NO domain field and `initial_prompt: "Switch>"`

**Expected:** Prompt shows exactly `Switch>` — not undefined>, not Switch>  with extra space

**Why human:** The backward compat branch `(!this.config.domain && term.initial_prompt)` needs a real question config to trigger and verify visually

---

#### 5. Mode Persistence (CLI-05)

**Test:** Type `conf t`, then `ip address 192.168.1.1 255.255.255.0` in sequence

**Expected:** The ip address command is recorded in history prefixed with `Router(config)# ` (mode persisted from conf t)

**Why human:** History accumulation and prompt prefix correctness across multiple commands requires runtime observation

---

### Gaps Summary

No gaps. All automated checks passed at all three verification levels (exists, substantive, wired):

- `cliStateMachine.js` is substantive (196 lines, zero dependencies, all exports present with correct logic)
- `PbqCli.vue` is substantive (import wired, all state additions present, submitCommand fully rewritten)
- All key links are connected (import, evaluateCommand call, currentPrompt binding, emit contract)
- All 5 CLI requirement IDs claimed by the plans are satisfied in code

The 5 human verification items are browser rendering checks that cannot be automated via static analysis. They are expected to pass given the code correctness verified above.

---

**Commits verified:**
- `ac38571` — feat(01-01): add CLI state machine - DOMAIN_SCHEMAS and getPrompt()
- `dc0d290` — fix(01-01): preserve interface name case in dynamic transitions
- `75f2d78` — feat(01-02): integrate cliStateMachine into PbqCli.vue

---

_Verified: 2026-03-16T21:45:00Z_
_Verifier: Claude (gsd-verifier)_
