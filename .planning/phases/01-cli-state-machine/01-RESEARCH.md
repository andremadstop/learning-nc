# Phase 1: CLI State Machine — Research

**Researched:** 2026-03-16
**Domain:** Vue 2.7 state machine, Cisco IOS CLI simulation, PBQ scoring
**Confidence:** HIGH (all findings from direct codebase analysis)

---

## Summary

Phase 1 extends the existing `PbqCli.vue` component from a "dumb terminal" (static prompt, no command logic) into a Cisco IOS-aware state machine. The component already has the right structure: multi-terminal support, per-terminal history, input buffering, and emit-based answer reporting.

The backend scoring for `cli` subtype already exists in `TrainingService::scorePbqAnswer()` and uses regex pattern matching against the terminal history. The scoring contract is: `config.evaluation[]` entries each have a `terminal`, `required_pattern`, and `points`. Phase 1 does NOT need to change scoring — it only needs to make the frontend interactive enough that users actually type the right commands.

The state machine (exec/config/config-if mode transitions, prompt changes, command_outputs feedback, error messages) lives entirely in the Vue component and is driven by the `pbq_config` JSON. No backend changes are required for the core goal — the mode state is transient, session-local, and does not need to be persisted across page reloads. A single terminal per question is the common case; multi-terminal support is already there structurally.

**Primary recommendation:** Implement the CLI state machine as a pure frontend concern in `PbqCli.vue`. Enrich `pbq_config` schema with `domain`, `command_outputs`, and (for cisco_ios) `modes` definition. Keep the backend `evaluation` scoring unchanged.

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| CLI-01 | PbqCli supports `domain` field (cisco_ios/linux/windows/sql/generic) to determine prompt schema | Domain field maps to a prompt-schema lookup table in the Vue component |
| CLI-02 | Cisco IOS: `conf t` -> config mode, `interface X` -> config-if mode, `exit` -> back | State machine with a mode stack; transitions defined per domain or overridable per config |
| CLI-03 | Unknown commands show contextual error message (e.g. `% Invalid command`) | Command evaluation logic: check command_outputs first, then transitions, else error |
| CLI-04 | `command_outputs` dict enables configured feedback text per command | Simple key-value lookup in config JSON, rendered as output line after command |
| CLI-05 | State machine persists mode between commands within one question session | Mode stored in Vue component `data()` (reactive, per-terminal), reset on component destroy |
</phase_requirements>

---

## Standard Stack

### Core (no new dependencies needed)

| Library | Version | Purpose | Notes |
|---------|---------|---------|-------|
| Vue 2.7 | existing | Component reactivity, `$set` for dynamic keys | Already in use |
| Nextcloud Vue | existing | NcButton etc. — not needed for this phase | Available if needed |

No new npm packages are required for Phase 1. The state machine is pure JS logic.

### Installation

```bash
# No new packages — pure Vue 2.7 component work
```

---

## Architecture Patterns

### Existing Config Schema (current, as-is)

```json
{
  "hint": "Configure the interface IP address",
  "terminals": [
    {
      "name": "Router",
      "initial_prompt": "Router>"
    }
  ],
  "evaluation": [
    {
      "terminal": "Router",
      "required_pattern": "ip address 192\\.168\\.1\\.1",
      "points": 2
    }
  ]
}
```

**Key insight:** `evaluation` is backend-only, consumed by `TrainingService::scorePbqAnswer()`. It must not be broken. The frontend never reads `evaluation`.

### Proposed Extended Config Schema (Phase 1 additions)

```json
{
  "hint": "Configure the interface IP address",
  "domain": "cisco_ios",
  "terminals": [
    {
      "name": "Router",
      "initial_prompt": "Router>"
    }
  ],
  "command_outputs": {
    "show version": "Cisco IOS Software, Version 15.2(4)M3...",
    "show ip interface brief": "Interface        IP-Address    Status   Protocol\nFa0/0            unassigned    down     down"
  },
  "evaluation": [
    {
      "terminal": "Router",
      "required_pattern": "ip address 192\\.168\\.1\\.1",
      "points": 2
    }
  ]
}
```

**New fields:**
- `domain` — string enum: `cisco_ios` | `linux` | `windows` | `sql` | `generic` (CLI-01)
- `command_outputs` — flat dict, keys are command strings (case-insensitive), values are output text (CLI-04)

No schema changes to `terminals[]` or `evaluation[]` — backward compatible.

### Recommended Project Structure Change

Only `PbqCli.vue` is modified. No new files strictly needed for the state machine logic itself. Optionally extract a JS helper:

```
app/src/
├── components/
│   ├── PbqCli.vue           ← primary change
│   └── ...
└── utils/
    └── cliStateMachine.js   ← optional extraction (keeps PbqCli.vue clean)
```

### Pattern 1: Domain Prompt Schemas

Each domain defines:
- `hostname_placeholder` — derived from `terminal.name`
- `mode_prompts` — map from mode name to prompt suffix

```javascript
// In PbqCli.vue or cliStateMachine.js
const DOMAIN_SCHEMAS = {
  cisco_ios: {
    modes: {
      exec:      (host) => `${host}>`,
      config:    (host) => `${host}(config)#`,
      'config-if': (host, ctx) => `${host}(config-if)#`,
    },
    defaultMode: 'exec',
    transitions: {
      exec: {
        'conf t':          { toMode: 'config' },
        'configure terminal': { toMode: 'config' },
        'enable':          { toMode: 'exec' },  // already privileged in sim
      },
      config: {
        'exit':            { toMode: 'exec' },
        'end':             { toMode: 'exec' },
      },
      // interface X is dynamic — handled by pattern matching
    },
    errorMsg: '% Invalid input detected at \'^\'  marker.',
  },
  linux: {
    modes: { shell: (host, ctx) => `${ctx.user || 'user'}@${host}:~$ ` },
    defaultMode: 'shell',
    transitions: {},
    errorMsg: 'bash: {cmd}: command not found',
  },
  windows: {
    modes: { cmd: (host) => `C:\\Users\\Administrator> ` },
    defaultMode: 'cmd',
    transitions: {},
    errorMsg: '\'{cmd}\' is not recognized as an internal or external command.',
  },
  sql: {
    modes: { prompt: () => 'mysql> ' },
    defaultMode: 'prompt',
    transitions: {},
    errorMsg: 'ERROR 1064 (42000): You have an error in your SQL syntax.',
  },
  generic: {
    modes: { prompt: (host) => `${host}> ` },
    defaultMode: 'prompt',
    transitions: {},
    errorMsg: 'Unknown command.',
  },
}
```

Source: Cisco IOS CLI behavior (HIGH confidence — widely documented, N10-009 exam standard).

### Pattern 2: Command Evaluation Order

Per `submitCommand()`, evaluate in this order:

1. **Transition check** — does the command (normalized, lowercase, trimmed) match a transition rule for the current mode?
   - If yes: switch mode, push output line (optional), update prompt
   - Special case for cisco_ios config mode: `interface <X>` (regex match) transitions to `config-if` and sets interface context
2. **command_outputs lookup** — does the command (case-insensitive) exist in `config.command_outputs`?
   - If yes: push configured output text as history line(s)
3. **Error fallback** — command not recognized:
   - Push domain-appropriate error message
   - Mode does NOT change

```javascript
// Pseudocode — in submitCommand(term)
const normalized = cmd.toLowerCase().trim()
const schema = DOMAIN_SCHEMAS[this.config.domain || 'generic']
const mode = this.termModes[term.name]  // current mode

// Step 1: Transition
const transitions = schema.transitions[mode] || {}
let transitioned = false

if (transitions[normalized]) {
  const t = transitions[normalized]
  this.setTermMode(term.name, t.toMode)
  if (t.output) this.pushLine(term.name, t.output)
  transitioned = true
} else if (this.config.domain === 'cisco_ios' && mode === 'config') {
  const ifMatch = normalized.match(/^interface\s+(.+)$/)
  if (ifMatch) {
    this.setTermContextInterface(term.name, ifMatch[1])
    this.setTermMode(term.name, 'config-if')
    transitioned = true
  }
}

if (!transitioned) {
  // Step 2: command_outputs
  const outputs = this.config.command_outputs || {}
  const outputKey = Object.keys(outputs).find(k => k.toLowerCase() === normalized)
  if (outputKey) {
    this.pushLine(term.name, outputs[outputKey])
  } else {
    // Step 3: error
    const errMsg = typeof schema.errorMsg === 'function'
      ? schema.errorMsg(cmd)
      : schema.errorMsg.replace('{cmd}', cmd)
    this.pushLine(term.name, errMsg)
  }
}
```

### Pattern 3: Mode State in Vue Component

```javascript
// In data()
data() {
  const termModes = {}
  const termContexts = {}
  for (const term of (this.config.terminals || [])) {
    const schema = DOMAIN_SCHEMAS[this.config.domain || 'generic']
    termModes[term.name] = schema?.defaultMode || 'prompt'
    termContexts[term.name] = {}
  }
  return {
    inputBuffers: {...},
    localHistory: {...},
    termModes,       // NEW: current mode per terminal
    termContexts,    // NEW: extra context (e.g. active interface name)
  }
},
```

Dynamic prompt computed from mode:

```javascript
// In methods or computed
getPrompt(term) {
  const schema = DOMAIN_SCHEMAS[this.config.domain || 'generic']
  const mode = this.termModes[term.name] || schema?.defaultMode
  const modePrompts = schema?.modes || {}
  const promptFn = modePrompts[mode] || modePrompts[schema.defaultMode]
  const host = term.name  // "Router", "Switch1", etc.
  const ctx = this.termContexts[term.name] || {}
  return promptFn ? promptFn(host, ctx) : `${host}> `
},
```

Template change: replace `term.initial_prompt` with `getPrompt(term)`:

```html
<span class="pbq-terminal-prompt">{{ getPrompt(term) }} </span>
```

### Anti-Patterns to Avoid

- **Storing mode in localHistory:** Mode is transient UI state, not part of the answer. The history (what gets emitted) should contain only the typed lines — that's what backend scoring regexes against. Do not mix mode-change markers into history.
- **Case-sensitive command matching:** Real IOS CLI is case-insensitive for built-ins. Normalize to lowercase before matching.
- **Blocking scroll on mode change:** Call `$nextTick` + scroll after every history mutation, not just on submit.
- **Modifying `evaluation[]` for state machine:** The backend scoring reads `evaluation[]` only. The frontend must not depend on `evaluation[]` at all.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead |
|---------|-------------|-------------|
| Terminal scrolling | Custom scroll manager | `$nextTick(() => el.scrollTop = el.scrollHeight)` — already in component |
| Command history (up-arrow) | Custom ring buffer | Out of scope for Phase 1 (not in requirements) |
| Regex for IOS interface names | Custom parser | Simple JS regex `/^interface\s+(.+)$/i` is sufficient |
| Backend scoring change | New scoring algorithm | Existing regex pattern scoring already handles it — don't touch |

---

## Common Pitfalls

### Pitfall 1: Breaking Existing Scoring

**What goes wrong:** Changing how commands are pushed to `localHistory` (adding extra lines, changing format) breaks the backend regex patterns in `evaluation[].required_pattern`.

**Why it happens:** The backend scores by running regex against `implode("\n", $history)` — the flat array of strings emitted by the component. If the format changes (e.g. adding prefix lines), existing patterns stop matching.

**How to avoid:** Emit the same format as today: one string per entered command, in the format `"PROMPT CMD"`. Output lines (feedback text, error messages) should be in `localHistory` for display but NOT in the emitted array if you separate concerns. However, looking at the current code: today `history.push(prompt + ' ' + cmd)` and `$emit('update', term.name, [...history])` — the history IS the emitted value and contains prompt+cmd lines.

**Resolution:** Keep emitting the full display history. Backend patterns are already written against "the full text typed in the terminal" format. Mode-switch output lines added to display history are fine — they won't match typical regex patterns for IP address or config commands. Just verify no output line accidentally matches a scoring pattern.

**Warning signs:** Scoring tests fail on questions that previously worked.

### Pitfall 2: Dynamic Prompt Not Reactive in Vue 2

**What goes wrong:** `termModes[term.name]` is set with direct assignment (`this.termModes[term.name] = 'config'`) but Vue 2 cannot detect property addition on plain objects.

**Why it happens:** Vue 2 reactivity requires `$set` for adding new keys to reactive objects. If `termModes` is initialized with all keys upfront, direct assignment works. If it's not pre-initialized, the prompt won't update.

**How to avoid:** Pre-initialize ALL terminal names as keys in `termModes` and `termContexts` in `data()`. Then direct assignment (`this.termModes[term.name] = newMode`) is reactive. OR use `this.$set(this.termModes, term.name, newMode)` for safety.

**Warning signs:** Mode changes silently succeed but prompt doesn't update in UI.

### Pitfall 3: `exit` Mode Stack Ambiguity

**What goes wrong:** `exit` in Cisco IOS should go to the "previous" mode, not a hardcoded target. From `config-if`, `exit` goes to `config`. From `config`, `exit` goes to exec. A flat transition map (`exit → exec`) is wrong for config-if.

**How to avoid:** Use a mode stack per terminal, or define `exit` per-mode in the transitions table (as shown in Pattern 1 above: `exit` in `config` maps to `exec`, `exit` in `config-if` would map to `config`). The transitions table is already keyed by mode, so this is natural.

### Pitfall 4: Cisco `end` vs `exit`

**What goes wrong:** In real IOS, `end` always returns to privileged EXEC mode (not just one level up), while `exit` goes one level up. Missing `end` support means exam questions using `end` won't work.

**How to avoid:** In `config` and `config-if` modes, both `end` and `exit` must be recognized. `end` always goes to `exec`. `exit` goes one level up.

### Pitfall 5: NC CSP — No `v-html`

**What goes wrong:** Rendering `command_outputs` text that contains `<br>` or HTML tags via `v-html` violates Nextcloud CSP and is disallowed.

**How to avoid:** Use `white-space: pre-wrap` CSS (already applied to `.pbq-terminal-line`) and newline characters (`\n`) in output text. Split multi-line outputs on `\n` and render each as a separate `<div>` with `v-for`. Never use `v-html` with config-sourced text.

---

## Code Examples

### Current `submitCommand` (as-is)

```javascript
// Source: app/src/components/PbqCli.vue line 53
submitCommand(term) {
  const cmd = this.inputBuffers[term.name].trim()
  if (!cmd) return
  const prompt = term.initial_prompt || '>'
  const history = this.localHistory[term.name] || []
  history.push(prompt + ' ' + cmd)
  this.$set(this.localHistory, term.name, history)
  this.inputBuffers[term.name] = ''
  this.$emit('update', term.name, [...history])
  // ... scroll
},
```

### Existing Backend Scoring for CLI (as-is)

```php
// Source: app/lib/Service/TrainingService.php line 1197
case 'cli':
    foreach (($config['evaluation'] ?? []) as $ev) {
        $maxPoints += ($ev['points'] ?? 1);
        $history = $userAnswers[$ev['terminal']] ?? [];
        $allCmds = is_array($history) ? implode("\n", $history) : (string)$history;
        if (@preg_match('/' . addcslashes($ev['required_pattern'], '/') . '/i', $allCmds)) {
            $points += ($ev['points'] ?? 1);
        }
    }
    break;
```

This scoring runs regex against the joined history array. The history emitted by the frontend is the input for this scoring. Format: `"Router> conf t\nRouter(config)# interface Fa0/0\n..."` after Phase 1.

### Cisco IOS Mode Transitions (authoritative)

```
User EXEC mode:    Router>
  conf t           →  Router(config)#
  configure terminal → Router(config)#

Config mode:       Router(config)#
  interface Fa0/0  →  Router(config-if)#
  exit             →  Router>
  end              →  Router>

Config-if mode:    Router(config-if)#
  exit             →  Router(config)#
  end              →  Router>
```

Source: Cisco IOS CLI behavior — HIGH confidence, well-documented for N10-009/CCNA.

---

## State of the Art

| Old Approach | Current Approach | Impact |
|--------------|------------------|--------|
| Static `initial_prompt` in template | Dynamic `getPrompt(term)` method | Prompt updates reactively on mode change |
| No command processing | Ordered evaluation: transitions → command_outputs → error | Realistic CLI behavior |
| Single line output per command | Multi-line output via `\n` split | Supports `show` command output |

---

## Validation Architecture

No `config.json` found — treat `nyquist_validation` as enabled.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | No automated test framework detected in project |
| Config file | None — Wave 0 gap |
| Quick run command | N/A — see Wave 0 |
| Full suite command | N/A — see Wave 0 |

This is a Vue 2 Nextcloud app with no existing test infrastructure. Phase 1 is frontend-only (Vue component) with a small backend validation concern (schema acceptance). Manual testing via `learning-dev` Docker environment is the current practice per CLAUDE.md.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | File |
|--------|----------|-----------|------|
| CLI-01 | `domain` field selects correct prompt schema | manual | Deploy to learning-dev, verify prompt format |
| CLI-02 | `conf t` / `interface X` / `exit` mode transitions | manual | Type commands in browser, observe prompt |
| CLI-03 | Unknown command shows `% Invalid command` | manual | Type gibberish, observe error line |
| CLI-04 | Configured `command_outputs` text appears after command | manual | Configure a command_output, type that command |
| CLI-05 | Mode persists between commands | manual | Type `conf t` then another command, verify `(config)#` prompt |

**Rationale for manual-only:** Vue 2 component unit tests require `@vue/test-utils` + `jest` or `vitest`, which are not present. Setting up a test framework is out of scope for Phase 1. The existing deploy workflow (CLAUDE.md) is build + docker-cp + browser verification.

### Wave 0 Gaps

All testing is manual via `learning-dev`. No test framework setup needed for Phase 1 — manual verification against the 5 success criteria from ROADMAP.md is sufficient and consistent with how the rest of the app is tested.

None — no test infrastructure required for this phase given project norms.

---

## Open Questions

1. **Multi-terminal questions: shared or per-terminal mode?**
   - What we know: `terminals[]` is an array; each has its own `localHistory` and `inputBuffers`
   - What's unclear: Should mode be per-terminal (yes, logically) or shared? Exam simulations typically have one terminal.
   - Recommendation: Per-terminal mode state (`termModes[term.name]`). Already natural given the existing per-terminal data structure.

2. **Should `command_outputs` keys be exact-match or prefix-match?**
   - What we know: Requirements say "definierte Befehle in `command_outputs`" — implies defined set
   - What's unclear: Real IOS supports abbreviated commands (e.g. `sh ip int br` for `show ip interface brief`)
   - Recommendation: Exact match (normalized, case-insensitive) for Phase 1. Abbreviation support is an enhancement for later phases or the Author Tool.

3. **Are there existing PBQ CLI questions in the database to validate against?**
   - What we know: The scoring code exists but no example JSON with `evaluation[]` was found in `app/examples/`
   - What's unclear: What real question configs look like
   - Recommendation: Create at least one test question manually during implementation. The `command_outputs` field is NEW — no existing questions use it, so backward compatibility concern is only for `evaluation[]` scoring.

4. **`initial_prompt` field backward compatibility**
   - What we know: Current config uses `terminal.initial_prompt` (e.g. `"Router>"`)
   - After Phase 1: prompt is derived from `domain` + mode + `terminal.name`
   - Recommendation: If `domain` is absent or `generic`, fall back to `term.initial_prompt` for full backward compat. This means zero-config questions continue to work unchanged.

---

## Sources

### Primary (HIGH confidence — direct codebase analysis)

- `/home/andre/Workspace/Code/learning-nc/app/src/components/PbqCli.vue` — existing component structure, current schema, emit contract
- `/home/andre/Workspace/Code/learning-nc/app/lib/Service/TrainingService.php` lines 1177-1225 — `scorePbqAnswer()` for CLI subtype, exact scoring contract
- `/home/andre/Workspace/Code/learning-nc/app/lib/Db/Question.php` — `pbqConfig` stored as JSON string, decoded in `jsonSerialize()`
- `/home/andre/Workspace/Code/learning-nc/app/src/components/PbqRenderer.vue` — how PbqCli is mounted, props contract
- `/home/andre/Workspace/Code/learning-nc/.planning/REQUIREMENTS.md` — CLI-01 through CLI-05

### Secondary (MEDIUM confidence)

- Cisco IOS CLI mode behavior (exec/config/config-if) — widely documented, N10-009 exam standard, consistent across sources

### Tertiary (LOW confidence)

None — no unverified claims.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new deps, pure Vue 2.7 work within existing project
- Architecture: HIGH — derived directly from existing component and backend code
- Pitfalls: HIGH — Vue 2 reactivity pitfall and scoring contract pitfall directly observable in code
- Cisco IOS transitions: MEDIUM-HIGH — well-documented standard behavior, no Version-specific quirks at N10-009 level

**Research date:** 2026-03-16
**Valid until:** 2026-06-16 (stable domain — Vue 2.7 is in maintenance mode, Cisco IOS CLI behavior is stable)
