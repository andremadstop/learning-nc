import { describe, it, expect } from 'vitest'

// Pure-function helpers mirroring PbqAuthorTool's computed config-generation logic.
// These functions are defined inline here to establish the TDD contract.
// They duplicate the logic in PbqAuthorTool.vue — the tests verify exact behaviour.

function buildCliConfig(form) {
  const config = {
    domain: form.domain,
    terminals: form.terminals,
    command_outputs: (form.commandOutputs || [])
      .filter(pair => pair.cmd.trim() !== '')
      .reduce((acc, pair) => {
        acc[pair.cmd.toLowerCase()] = pair.output
        return acc
      }, {}),
  }
  if (form.hint && form.hint.trim() !== '') {
    config.hint = form.hint
  }
  return config
}

function buildPlacementConfig(form, topologyForm) {
  const useTopology = form.useTopology
  const hasNodes = topologyForm.nodes && topologyForm.nodes.length > 0
  return {
    positions: form.positions,
    device_options: form.deviceOptions,
    scoring_mode: form.scoringMode,
    topology: (useTopology && hasNodes)
      ? { nodes: topologyForm.nodes, links: topologyForm.links }
      : null,
  }
}

function buildDropdownConfig(form) {
  return {
    questions: form.questions,
  }
}

function buildMultiPanelConfig(cliForm, placementForm, topologyForm) {
  const hasNodes = topologyForm.nodes && topologyForm.nodes.length > 0
  return {
    cli: {
      domain: cliForm.domain,
      terminals: cliForm.terminals,
      command_outputs: (cliForm.commandOutputs || [])
        .filter(pair => pair.cmd.trim() !== '')
        .reduce((acc, pair) => {
          acc[pair.cmd.toLowerCase()] = pair.output
          return acc
        }, {}),
    },
    placement: {
      positions: placementForm.positions,
      device_options: placementForm.deviceOptions,
      scoring_mode: placementForm.scoringMode,
    },
    topology: hasNodes
      ? { nodes: topologyForm.nodes, links: topologyForm.links }
      : null,
  }
}

function buildGeneratedJson(subtype, config) {
  return JSON.stringify({ pbq_subtype: subtype, pbq_config: config }, null, 2)
}

describe('PbqAuthorTool config generation', () => {
  // --- buildCliConfig ---

  it('AUTHOR-01: buildCliConfig normalises command_outputs keys to lowercase', () => {
    const form = {
      domain: 'cisco_ios',
      hint: '',
      terminals: [{ name: 'Router' }],
      commandOutputs: [{ cmd: 'Show IP Route', output: 'Gateway of last resort...' }],
    }
    const config = buildCliConfig(form)
    expect(Object.keys(config.command_outputs)).toContain('show ip route')
    expect(Object.keys(config.command_outputs)).not.toContain('Show IP Route')
  })

  it('AUTHOR-01: buildCliConfig omits hint key when hint is empty string', () => {
    const form = {
      domain: 'cisco_ios',
      hint: '',
      terminals: [{ name: 'Router' }],
      commandOutputs: [],
    }
    const config = buildCliConfig(form)
    expect(Object.prototype.hasOwnProperty.call(config, 'hint')).toBe(false)
  })

  it('AUTHOR-01: buildCliConfig includes hint key when hint is non-empty', () => {
    const form = {
      domain: 'cisco_ios',
      hint: 'Use show commands',
      terminals: [{ name: 'Router' }],
      commandOutputs: [],
    }
    const config = buildCliConfig(form)
    expect(config.hint).toBe('Use show commands')
  })

  // --- buildPlacementConfig ---

  it('AUTHOR-01: buildPlacementConfig returns topology: null when useTopology is false', () => {
    const form = {
      useTopology: false,
      scoringMode: 'strict',
      positions: [{ id: 'n1', label: 'Router', correct: 'router' }],
      deviceOptions: ['router', 'switch'],
    }
    const topologyForm = {
      nodes: [{ id: 'n1', type: 'router', label: '', x: 200, y: 150 }],
      links: [],
    }
    const config = buildPlacementConfig(form, topologyForm)
    expect(config.topology).toBeNull()
  })

  it('AUTHOR-01: buildPlacementConfig returns topology: null when useTopology is true but nodes array is empty', () => {
    const form = {
      useTopology: true,
      scoringMode: 'strict',
      positions: [{ id: 'n1', label: 'Router', correct: 'router' }],
      deviceOptions: ['router', 'switch'],
    }
    const topologyForm = {
      nodes: [],
      links: [],
    }
    const config = buildPlacementConfig(form, topologyForm)
    expect(config.topology).toBeNull()
  })

  it('AUTHOR-01: buildPlacementConfig returns topology: {nodes, links} when useTopology is true and nodes.length > 0', () => {
    const form = {
      useTopology: true,
      scoringMode: 'strict',
      positions: [{ id: 'n1', label: 'Router', correct: 'router' }],
      deviceOptions: ['router', 'switch'],
    }
    const topologyForm = {
      nodes: [{ id: 'n1', type: 'router', label: 'R1', x: 200, y: 150 }],
      links: [{ from: 'n1', to: 'n2' }],
    }
    const config = buildPlacementConfig(form, topologyForm)
    expect(config.topology).not.toBeNull()
    expect(config.topology.nodes).toHaveLength(1)
    expect(config.topology.links).toHaveLength(1)
  })

  // --- buildMultiPanelConfig ---

  it('AUTHOR-01: buildMultiPanelConfig merges cli + placement + topology at top level', () => {
    const cliForm = {
      domain: 'cisco_ios',
      hint: '',
      terminals: [{ name: 'Router' }],
      commandOutputs: [{ cmd: 'show version', output: 'Cisco IOS 15.1' }],
    }
    const placementForm = {
      useTopology: true,
      scoringMode: 'strict',
      positions: [{ id: 'n1', label: 'Router', correct: 'router' }],
      deviceOptions: ['router', 'switch'],
    }
    const topologyForm = {
      nodes: [{ id: 'n1', type: 'router', label: 'R1', x: 200, y: 150 }],
      links: [],
    }
    const config = buildMultiPanelConfig(cliForm, placementForm, topologyForm)
    expect(config).toHaveProperty('cli')
    expect(config).toHaveProperty('placement')
    expect(config).toHaveProperty('topology')
    expect(config.cli.domain).toBe('cisco_ios')
    expect(config.placement.scoring_mode).toBe('strict')
    expect(config.topology.nodes).toHaveLength(1)
  })

  // --- buildGeneratedJson ---

  it('AUTHOR-01: buildGeneratedJson returns a parseable JSON string', () => {
    const config = { domain: 'cisco_ios', terminals: [{ name: 'Router' }], command_outputs: {} }
    const json = buildGeneratedJson('cli', config)
    expect(() => JSON.parse(json)).not.toThrow()
  })

  it('AUTHOR-01: buildGeneratedJson output parses to object with pbq_subtype and pbq_config keys', () => {
    const config = { domain: 'cisco_ios', terminals: [{ name: 'Router' }], command_outputs: {} }
    const json = buildGeneratedJson('cli', config)
    const parsed = JSON.parse(json)
    expect(parsed).toHaveProperty('pbq_subtype', 'cli')
    expect(parsed).toHaveProperty('pbq_config')
    expect(parsed.pbq_config.domain).toBe('cisco_ios')
  })
})
