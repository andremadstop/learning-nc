/**
 * PbqAuthorTool config generation, tested against the real component methods and computed
 * properties (audit #125: this file used to test its own drifted copies of the builders, which
 * lowercased command keys and had no per_terminal or dropdown mode).
 *
 * Command keys keep the author's case: cliStateMachine matches them case-insensitively.
 */
import { describe, it, expect, vi } from 'vitest'

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('../../src/components/PbqRenderer.vue', () => ({ default: { name: 'PbqRenderer', template: '<div />' } }))

globalThis.t = (app, text) => text

import PbqAuthorTool from '../../src/components/PbqAuthorTool.vue'

// A fresh builder (no stored config) for the given subtype, with data, computed and methods bound.
function createTool(subtype) {
	const vm = { initialSubtype: subtype, initialConfig: null, $emit: vi.fn() }
	Object.assign(vm, PbqAuthorTool.data.call(vm))
	for (const [name, def] of Object.entries(PbqAuthorTool.computed)) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(PbqAuthorTool.methods)) {
		vm[name] = fn.bind(vm)
	}
	PbqAuthorTool.created.call(vm)
	return vm
}

describe('PbqAuthorTool config generation', () => {
	// --- buildCliConfig ---

	it('buildCliConfig keeps the case of command_outputs keys and trims them', () => {
		const vm = createTool('cli')
		vm.cliForm.commandOutputs = [{ terminal: 'Router', cmd: '  Show IP Route ', output: 'Gateway of last resort...' }]
		const config = vm.buildCliConfig()
		expect(config.command_outputs).toEqual({ 'Show IP Route': 'Gateway of last resort...' })
	})

	it('buildCliConfig drops rows with an empty command', () => {
		const vm = createTool('cli')
		vm.cliForm.commandOutputs = [
			{ terminal: 'Router', cmd: '   ', output: 'ignored' },
			{ terminal: 'Router', cmd: 'show version', output: 'IOS 15' },
		]
		expect(vm.buildCliConfig().command_outputs).toEqual({ 'show version': 'IOS 15' })
	})

	it('buildCliConfig groups outputs by terminal in per_terminal mode', () => {
		const vm = createTool('cli')
		vm.cliForm.outputScope = 'per_terminal'
		vm.cliForm.terminals = [{ name: 'R1' }, { name: 'R2' }]
		vm.cliForm.commandOutputs = [
			{ terminal: 'R1', cmd: 'show ip route', output: 'r1 routes' },
			{ terminal: 'R2', cmd: 'show ip route', output: 'r2 routes' },
			{ terminal: '', cmd: 'show version', output: 'defaults to first terminal' },
		]
		expect(vm.buildCliConfig().command_outputs).toEqual({
			R1: { 'show ip route': 'r1 routes', 'show version': 'defaults to first terminal' },
			R2: { 'show ip route': 'r2 routes' },
		})
	})

	it('buildCliConfig omits the hint key when the hint is blank', () => {
		const vm = createTool('cli')
		vm.cliForm.hint = '   '
		expect(Object.prototype.hasOwnProperty.call(vm.buildCliConfig(), 'hint')).toBe(false)
	})

	it('buildCliConfig includes a trimmed hint when it is non-empty', () => {
		const vm = createTool('cli')
		vm.cliForm.hint = '  Use show commands '
		expect(vm.buildCliConfig().hint).toBe('Use show commands')
	})

	it('buildCliConfig drops nameless terminals and falls back to the default terminal', () => {
		const vm = createTool('cli')
		vm.cliForm.terminals = [{ name: '  ' }]
		expect(vm.buildCliConfig().terminals).toEqual([{ name: 'Router' }])
	})

	// --- buildPlacementConfig ---

	it('buildPlacementConfig has no topology key when useTopology is false', () => {
		const vm = createTool('placement')
		vm.placementForm.useTopology = false
		expect(Object.prototype.hasOwnProperty.call(vm.buildPlacementConfig(), 'topology')).toBe(false)
	})

	it('buildPlacementConfig has no topology key when useTopology is true but there are no nodes', () => {
		const vm = createTool('placement')
		vm.placementForm.useTopology = true
		vm.topologyForm.nodes = []
		expect(Object.prototype.hasOwnProperty.call(vm.buildPlacementConfig(), 'topology')).toBe(false)
	})

	it('buildPlacementConfig includes topology {nodes, links} when useTopology is true and nodes exist', () => {
		const vm = createTool('placement')
		vm.placementForm.useTopology = true
		vm.topologyForm.nodes = [{ id: 'n1', type: 'router', label: 'R1', x: 200, y: 150 }]
		vm.topologyForm.links = [{ from: 'n1', to: 'n2' }]
		const config = vm.buildPlacementConfig()
		expect(config.topology.nodes).toHaveLength(1)
		expect(config.topology.links).toHaveLength(1)
	})

	it('buildPlacementConfig parses "a | b" into a list of accepted answers', () => {
		const vm = createTool('placement')
		vm.placementForm.positions = [
			{ id: 'n1', label: 'Edge', correctInput: 'router | firewall' },
			{ id: 'n2', label: 'Core', correctInput: 'switch' },
		]
		const config = vm.buildPlacementConfig()
		expect(config.positions).toEqual([
			{ id: 'n1', label: 'Edge', correct: ['router', 'firewall'] },
			{ id: 'n2', label: 'Core', correct: 'switch' },
		])
		expect(config.scoring_mode).toBe('strict')
	})

	// --- buildMultiPanelConfig ---

	it('buildMultiPanelConfig (placement mode) puts cli, placement and topology at the top level', () => {
		const vm = createTool('multi_panel')
		vm.multiPanelMode = 'placement'
		vm.cliForm.commandOutputs = [{ terminal: 'Router', cmd: 'show version', output: 'Cisco IOS 15.1' }]
		vm.placementForm.useTopology = true
		vm.topologyForm.nodes = [{ id: 'n1', type: 'router', label: 'R1', x: 200, y: 150 }]
		const config = vm.buildMultiPanelConfig()
		expect(config.cli.command_outputs).toEqual({ 'show version': 'Cisco IOS 15.1' })
		expect(config.placement.scoring_mode).toBe('strict')
		expect(config.placement).not.toHaveProperty('topology')
		expect(config.topology.nodes).toHaveLength(1)
		expect(config).not.toHaveProperty('dropdown')
	})

	it('buildMultiPanelConfig (dropdown mode) builds cli + dropdown and no placement', () => {
		const vm = createTool('multi_panel')
		vm.multiPanelMode = 'dropdown'
		vm.dropdownForm.questions = [{ id: 'q1', label: 'Port?', correct: '22', options: [' 22 ', '', '80'] }]
		const config = vm.buildMultiPanelConfig()
		expect(config).toHaveProperty('cli')
		expect(config.dropdown.questions).toEqual([{ id: 'q1', label: 'Port?', correct: '22', options: ['22', '80'] }])
		expect(config).not.toHaveProperty('placement')
		expect(config).not.toHaveProperty('topology')
	})

	// --- generatedConfig / generatedJson ---

	it('generatedJson is the pretty-printed config of the selected subtype', () => {
		const vm = createTool('cli')
		vm.cliForm.commandOutputs = [{ terminal: 'Router', cmd: 'show clock', output: '12:00' }]
		const parsed = JSON.parse(vm.generatedJson)
		expect(parsed).toEqual(vm.buildCliConfig())
		expect(parsed.domain).toBe('cisco_ios')
	})

	it('previewQuestion carries pbq_subtype and pbq_config', () => {
		const vm = createTool('dropdown')
		expect(vm.previewQuestion.pbq_subtype).toBe('dropdown')
		expect(vm.previewQuestion.pbq_config).toEqual(vm.buildDropdownConfig())
	})
})
