/**
 * CLI State Machine — domain logic layer for PBQ CLI simulations.
 *
 * Exports:
 *   DOMAIN_SCHEMAS  — prompt schemas for all five domains
 *   evaluateCommand — command evaluation pipeline
 *   getPrompt       — prompt string generator
 *
 * Zero external dependencies. Pure ES module.
 */

export type CliDomain = 'cisco_ios' | 'linux' | 'windows' | 'sql' | 'generic'

export interface CliContext {
	[key: string]: unknown
	user?: string
	interface?: string
}

export interface PbqCliConfig {
	domain?: CliDomain
	host?: string
	mode?: string
	context?: CliContext
	command_outputs?: Record<string, string>
}

interface StaticTransition {
	toMode: string
}

interface DynamicTransition {
	mode: string
	pattern: RegExp
	toMode: string
	contextKey: string
	contextFn: (match: RegExpMatchArray) => unknown
}

type PromptRenderer = (host: string, ctx: CliContext) => string

interface DomainSchema {
	modes: Record<string, PromptRenderer>
	defaultMode: string
	transitions: Record<string, Record<string, StaticTransition>>
	dynamicTransitions: DynamicTransition[]
	errorMsg: string | ((cmd: string) => string)
}

export interface CliEvaluationResult {
	type: 'transition' | 'output' | 'error'
	nextMode: string
	nextContext: CliContext
	lines: string[]
}

// ---------------------------------------------------------------------------
// DOMAIN_SCHEMAS
// ---------------------------------------------------------------------------

export const DOMAIN_SCHEMAS: Record<CliDomain, DomainSchema> = {

  cisco_ios: {
    modes: {
      exec:        (host: string) => host + '>',
      config:      (host: string) => host + '(config)#',
      'config-if': (host: string) => host + '(config-if)#',
    },
    defaultMode: 'exec',
    transitions: {
      exec: {
        'conf t':             { toMode: 'config' },
        'configure terminal': { toMode: 'config' },
        'enable':             { toMode: 'exec' },
      },
      config: {
        'exit': { toMode: 'exec' },
        'end':  { toMode: 'exec' },
      },
      'config-if': {
        'exit': { toMode: 'config' },
        'end':  { toMode: 'exec' },
      },
    },
    dynamicTransitions: [
      {
        mode:       'config',
        pattern:    /^interface\s+(.+)$/i,
        toMode:     'config-if',
        contextKey: 'interface',
        contextFn:  (match) => match[1],
      },
    ],
    errorMsg: "% Invalid input detected at '^' marker.",
  },

  linux: {
    modes: {
      shell: (host: string, ctx: CliContext) => String(ctx.user || 'user') + '@' + host + ':~$ ',
    },
    defaultMode: 'shell',
    transitions: {},
    dynamicTransitions: [],
    errorMsg: (cmd: string) => 'bash: ' + cmd + ': command not found',
  },

  windows: {
    modes: {
      cmd: (_host: string, _ctx: CliContext) => 'C:\\Users\\Administrator> ',
    },
    defaultMode: 'cmd',
    transitions: {},
    dynamicTransitions: [],
    errorMsg: (cmd: string) => "'" + cmd + "' is not recognized as an internal or external command.",
  },

  sql: {
    modes: {
      prompt: (_host: string, _ctx: CliContext) => 'mysql> ',
    },
    defaultMode: 'prompt',
    transitions: {},
    dynamicTransitions: [],
    errorMsg: 'ERROR 1064 (42000): You have an error in your SQL syntax.',
  },

  generic: {
    modes: {
      prompt: (host: string) => host + '> ',
    },
    defaultMode: 'prompt',
    transitions: {},
    dynamicTransitions: [],
    errorMsg: 'Unknown command.',
  },

}

// ---------------------------------------------------------------------------
// getPrompt
// ---------------------------------------------------------------------------

/**
 * Returns the prompt string for the given domain/mode/host/context.
 *
 * @param {string} domain   - e.g. 'cisco_ios', 'linux', 'windows', 'sql', 'generic'
 * @param {string} mode     - current mode, e.g. 'exec', 'config', 'shell'
 * @param {string} host     - hostname or device name
 * @param {Object} context  - extra state (e.g. { user: 'root', interface: 'Fa0/0' })
 * @returns {string}
 */
export function getPrompt(domain: string, mode: string | null | undefined, host: string, context: CliContext): string {
  const schema = DOMAIN_SCHEMAS[domain as CliDomain] || DOMAIN_SCHEMAS.generic
  const effectiveMode = mode || schema.defaultMode
  const promptFn = schema.modes[effectiveMode] || schema.modes[schema.defaultMode]
  if (promptFn) {
    return promptFn(host, context || {})
  }
  return host + '> '
}

// ---------------------------------------------------------------------------
// evaluateCommand
// ---------------------------------------------------------------------------

/**
 * Evaluates a CLI command against the current domain/mode state.
 *
 * Evaluation order:
 *   1. Static transition lookup
 *   2. Dynamic transition lookup
 *   3. command_outputs lookup (case-insensitive exact match)
 *   4. Error fallback
 *
 * @param {string} cmd            - raw command string as typed by user
 * @param {string} domain         - domain key (e.g. 'cisco_ios')
 * @param {string} currentMode    - current mode (e.g. 'exec')
 * @param {Object} context        - current context object (may be undefined)
 * @param {Object} commandOutputs - map of command string -> output string (may be undefined)
 * @returns {{ type: string, nextMode: string, nextContext: Object, lines: string[] }}
 */
export function evaluateCommand(
  cmd: string,
  domain: string,
  currentMode: string,
  context?: CliContext,
  commandOutputs?: Record<string, string>,
): CliEvaluationResult {
  const ctx = context || {}
  const outputs = commandOutputs || {}
  const schema = DOMAIN_SCHEMAS[domain as CliDomain] || DOMAIN_SCHEMAS.generic
  const normalized = cmd.toLowerCase().trim()

  // Step 1 — Static transition lookup
  const modeTransitions = schema.transitions[currentMode] || {}
  if (modeTransitions[normalized]) {
    return {
      type:        'transition',
      nextMode:    modeTransitions[normalized].toMode,
      nextContext: { ...ctx },
      lines:       [],
    }
  }

  // Step 2 — Dynamic transition lookup
  // Match against normalized for case-insensitive trigger detection, but use the
  // original trimmed command for capture groups so interface names preserve case.
  const cmdTrimmed = cmd.trim()
  for (const dt of (schema.dynamicTransitions || [])) {
    if (dt.mode === currentMode) {
      const m = cmdTrimmed.match(dt.pattern)
      if (m) {
        const newContext = { ...ctx, [dt.contextKey]: dt.contextFn(m) }
        return {
          type:        'transition',
          nextMode:    dt.toMode,
          nextContext: newContext,
          lines:       [],
        }
      }
    }
  }

  // Step 3 — command_outputs lookup (case-insensitive exact match)
  const matchKey = Object.keys(outputs).find((key) => key.toLowerCase() === normalized)
  if (matchKey !== undefined) {
    const outputText = outputs[matchKey]
    const lines = outputText.split('\n')
    return {
      type:        'output',
      nextMode:    currentMode,
      nextContext: { ...ctx },
      lines,
    }
  }

  // Step 4 — Error fallback
  const errorMsg = schema.errorMsg
  const errText = typeof errorMsg === 'function' ? errorMsg(cmd.trim()) : errorMsg
  return {
    type:        'error',
    nextMode:    currentMode,
    nextContext: { ...ctx },
    lines:       [errText],
  }
}
