import { describe, it, expect } from 'vitest'
import { DOMAIN_SCHEMAS, evaluateCommand, getPrompt } from '../../src/utils/cliStateMachine.js'

// ─── DOMAIN_SCHEMAS ───────────────────────────────────────────────────────────

describe('DOMAIN_SCHEMAS', () => {
  it('exports all five required domains', () => {
    expect(DOMAIN_SCHEMAS).toHaveProperty('cisco_ios')
    expect(DOMAIN_SCHEMAS).toHaveProperty('linux')
    expect(DOMAIN_SCHEMAS).toHaveProperty('windows')
    expect(DOMAIN_SCHEMAS).toHaveProperty('sql')
    expect(DOMAIN_SCHEMAS).toHaveProperty('generic')
  })

  it('cisco_ios has exec, config, config-if modes', () => {
    const modes = DOMAIN_SCHEMAS.cisco_ios.modes
    expect(modes).toHaveProperty('exec')
    expect(modes).toHaveProperty('config')
    expect(modes).toHaveProperty('config-if')
  })

  it('each domain has defaultMode, transitions, dynamicTransitions, errorMsg', () => {
    for (const domain of Object.keys(DOMAIN_SCHEMAS)) {
      const schema = DOMAIN_SCHEMAS[domain]
      expect(schema).toHaveProperty('defaultMode')
      expect(schema).toHaveProperty('transitions')
      expect(schema).toHaveProperty('dynamicTransitions')
      expect(schema).toHaveProperty('errorMsg')
    }
  })
})

// ─── getPrompt ────────────────────────────────────────────────────────────────

describe('getPrompt', () => {
  it('cisco_ios exec mode returns "Router>"', () => {
    expect(getPrompt('cisco_ios', 'exec', 'Router', {})).toBe('Router>')
  })

  it('cisco_ios config mode returns "Router(config)#"', () => {
    expect(getPrompt('cisco_ios', 'config', 'Router', {})).toBe('Router(config)#')
  })

  it('cisco_ios config-if mode returns "Router(config-if)#"', () => {
    expect(getPrompt('cisco_ios', 'config-if', 'Router', {})).toBe('Router(config-if)#')
  })

  it('linux shell mode returns correct prompt', () => {
    const result = getPrompt('linux', 'shell', 'SRV', {})
    expect(result).toContain('@SRV')
    expect(result).toContain('$')
  })

  it('unknown domain falls back to generic', () => {
    const result = getPrompt('nonexistent_domain', null, 'Host', {})
    expect(result).toContain('Host')
  })

  it('null mode uses schema defaultMode', () => {
    const result = getPrompt('cisco_ios', null, 'R1', {})
    expect(result).toBe('R1>')
  })
})

// ─── evaluateCommand — static transitions ────────────────────────────────────

describe('evaluateCommand — static transitions', () => {
  it('conf t transitions cisco_ios exec → config', () => {
    const r = evaluateCommand('conf t', 'cisco_ios', 'exec', {}, {})
    expect(r.type).toBe('transition')
    expect(r.nextMode).toBe('config')
  })

  it('configure terminal also transitions exec → config', () => {
    const r = evaluateCommand('configure terminal', 'cisco_ios', 'exec', {}, {})
    expect(r.type).toBe('transition')
    expect(r.nextMode).toBe('config')
  })

  it('exit in config mode goes back to exec', () => {
    const r = evaluateCommand('exit', 'cisco_ios', 'config', {}, {})
    expect(r.type).toBe('transition')
    expect(r.nextMode).toBe('exec')
  })

  it('end in config mode goes to exec', () => {
    const r = evaluateCommand('end', 'cisco_ios', 'config', {}, {})
    expect(r.type).toBe('transition')
    expect(r.nextMode).toBe('exec')
  })

  it('exit in config-if mode goes to config', () => {
    const r = evaluateCommand('exit', 'cisco_ios', 'config-if', {}, {})
    expect(r.type).toBe('transition')
    expect(r.nextMode).toBe('config')
  })

  it('end in config-if mode goes to exec', () => {
    const r = evaluateCommand('end', 'cisco_ios', 'config-if', {}, {})
    expect(r.type).toBe('transition')
    expect(r.nextMode).toBe('exec')
  })

  it('commands are case-insensitive (CONF T)', () => {
    const r = evaluateCommand('CONF T', 'cisco_ios', 'exec', {}, {})
    expect(r.type).toBe('transition')
    expect(r.nextMode).toBe('config')
  })

  it('transition returns empty lines array', () => {
    const r = evaluateCommand('conf t', 'cisco_ios', 'exec', {}, {})
    expect(r.lines).toEqual([])
  })
})

// ─── evaluateCommand — dynamic transitions ───────────────────────────────────

describe('evaluateCommand — dynamic transitions (interface)', () => {
  it('interface Fa0/0 transitions config → config-if', () => {
    const r = evaluateCommand('interface Fa0/0', 'cisco_ios', 'config', {}, {})
    expect(r.type).toBe('transition')
    expect(r.nextMode).toBe('config-if')
  })

  it('interface name is preserved in nextContext', () => {
    const r = evaluateCommand('interface Fa0/0', 'cisco_ios', 'config', {}, {})
    expect(r.nextContext.interface).toBe('Fa0/0')
  })

  it('interface name casing is preserved (Fa0/0 not fa0/0)', () => {
    const r = evaluateCommand('interface FastEthernet0/1', 'cisco_ios', 'config', {}, {})
    expect(r.nextContext.interface).toBe('FastEthernet0/1')
  })
})

// ─── evaluateCommand — command_outputs ───────────────────────────────────────

describe('evaluateCommand — command_outputs', () => {
  it('matching command returns type=output with configured lines', () => {
    const outputs = { 'show version': 'Cisco IOS Version 15.2' }
    const r = evaluateCommand('show version', 'cisco_ios', 'exec', {}, outputs)
    expect(r.type).toBe('output')
    expect(r.lines).toContain('Cisco IOS Version 15.2')
  })

  it('command_outputs lookup is case-insensitive', () => {
    const outputs = { 'Show Version': 'IOS 15.2' }
    const r = evaluateCommand('show version', 'cisco_ios', 'exec', {}, outputs)
    expect(r.type).toBe('output')
  })

  it('multi-line output is split into separate lines', () => {
    const outputs = { 'show ip int': 'line1\nline2\nline3' }
    const r = evaluateCommand('show ip int', 'cisco_ios', 'exec', {}, outputs)
    expect(r.lines).toHaveLength(3)
    expect(r.lines[0]).toBe('line1')
    expect(r.lines[2]).toBe('line3')
  })

  it('output hit keeps current mode', () => {
    const outputs = { 'show version': 'IOS 15.2' }
    const r = evaluateCommand('show version', 'cisco_ios', 'config', {}, outputs)
    expect(r.nextMode).toBe('config')
  })
})

// ─── evaluateCommand — error fallback ────────────────────────────────────────

describe('evaluateCommand — error fallback', () => {
  it('unknown command returns type=error', () => {
    const r = evaluateCommand('xyzzy', 'cisco_ios', 'exec', {}, {})
    expect(r.type).toBe('error')
  })

  it('cisco_ios error message contains expected marker', () => {
    const r = evaluateCommand('gibberish', 'cisco_ios', 'exec', {}, {})
    expect(r.lines[0]).toContain('Invalid input')
  })

  it('linux error message contains command name', () => {
    const r = evaluateCommand('foobar', 'linux', 'shell', {}, {})
    expect(r.lines[0]).toContain('foobar')
    expect(r.lines[0]).toContain('not found')
  })

  it('error keeps current mode', () => {
    const r = evaluateCommand('bad', 'cisco_ios', 'config', {}, {})
    expect(r.nextMode).toBe('config')
  })

  it('error preserves current context', () => {
    const ctx = { interface: 'Fa0/0' }
    const r = evaluateCommand('bad', 'cisco_ios', 'config-if', ctx, {})
    expect(r.nextContext).toEqual(ctx)
  })
})

// ─── evaluateCommand — edge cases ────────────────────────────────────────────

describe('evaluateCommand — edge cases', () => {
  it('undefined context is treated as {}', () => {
    const r = evaluateCommand('conf t', 'cisco_ios', 'exec', undefined, {})
    expect(r.type).toBe('transition')
  })

  it('undefined commandOutputs is treated as {}', () => {
    const r = evaluateCommand('xyzzy', 'cisco_ios', 'exec', {}, undefined)
    expect(r.type).toBe('error')
  })

  it('unknown domain falls back to generic schema', () => {
    const r = evaluateCommand('anything', 'unknown_domain', 'prompt', {}, {})
    expect(r.type).toBe('error')
    expect(r.lines[0]).toBe('Unknown command.')
  })

  it('always returns nextMode and nextContext', () => {
    const r = evaluateCommand('test', 'cisco_ios', 'exec', {}, {})
    expect(r).toHaveProperty('nextMode')
    expect(r).toHaveProperty('nextContext')
  })
})
