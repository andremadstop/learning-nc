/**
 * Audit 2026-11 (S10): opening an existing PBQ in the Config Builder and applying it rebuilt the
 * config from the form fields only. Everything the builder does not edit was dropped: `evaluation`
 * (the server scores cli and multi_panel only from it, so every answer became "0/0"), placement
 * hotspot coordinates, scenario images, instructions, reference images, extra dropdown fields.
 *
 * Unlike pbqAuthorTool.test.js (which re-implements the builders), this binds the real component
 * methods and computed properties.
 */
import { describe, it, expect, vi } from 'vitest'

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('../../src/components/PbqRenderer.vue', () => ({ default: { name: 'PbqRenderer', template: '<div />' } }))

globalThis.t = (app, text) => text

import PbqAuthorTool from '../../src/components/PbqAuthorTool.vue'

function createTool(initialSubtype, initialConfig) {
	const vm = { initialSubtype, initialConfig, $emit: vi.fn() }
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

function applied(vm) {
	vm.applyConfig()
	return vm.$emit.mock.calls.find(([name]) => name === 'apply')[1].config
}

// Same shape TrainingService scores from: a list of { terminal, required_pattern, points }.
const EVALUATION = [{ terminal: 'Router', required_pattern: 'ip route 0\\.0\\.0\\.0', points: 2 }]

describe('PBQ Config Builder keeps the fields it does not edit', () => {
	it('cli: evaluation, instructions and terminal extras survive an edit of a command output', () => {
		const vm = createTool('cli', {
			domain: 'cisco_ios',
			terminals: [{ name: 'Router', hostname: 'R1' }],
			command_outputs: { 'show ip route': 'old' },
			hint: 'Look at the routes',
			evaluation: EVALUATION,
			instructions: ['Configure a default route'],
		})
		vm.cliForm.commandOutputs[0].output = 'new'
		const config = applied(vm)
		expect(config.command_outputs).toEqual({ 'show ip route': 'new' })
		expect(config.evaluation).toEqual(EVALUATION)
		expect(config.instructions).toEqual(['Configure a default route'])
		expect(config.terminals).toEqual([{ name: 'Router', hostname: 'R1' }])
		expect(config.hint).toBe('Look at the routes')
	})

	it('cli: an emptied hint is still removed (the form wins for the fields it edits)', () => {
		const vm = createTool('cli', { domain: 'cisco_ios', terminals: [{ name: 'Router' }], command_outputs: {}, hint: 'old hint', evaluation: EVALUATION })
		vm.cliForm.hint = ''
		const config = applied(vm)
		expect(config).not.toHaveProperty('hint')
		expect(config.evaluation).toEqual(EVALUATION)
	})

	it('placement: hotspot coordinates, images and scenario type survive; a deleted position stays deleted', () => {
		const vm = createTool('placement', {
			scenario_type: 'image',
			background_image: '/apps/learning/img/office.png',
			instructions: ['Place the devices'],
			reference_images: [{ src: '/apps/learning/img/ref.png', caption: 'Ref' }],
			device_options: ['router', 'firewall'],
			scoring_mode: 'strict',
			positions: [
				{ id: 'p1', label: 'Edge', correct: 'firewall', x_pct: 12.5, y_pct: 40 },
				{ id: 'p2', label: 'Core', correct: 'router', x_pct: 60, y_pct: 70 },
			],
		})
		vm.placementForm.positions[0].label = 'Perimeter'
		vm.placementForm.positions.splice(1, 1)
		const config = applied(vm)
		expect(config.positions).toEqual([{ id: 'p1', label: 'Perimeter', correct: 'firewall', x_pct: 12.5, y_pct: 40 }])
		expect(config.background_image).toBe('/apps/learning/img/office.png')
		expect(config.scenario_type).toBe('image')
		expect(config.instructions).toEqual(['Place the devices'])
		expect(config.reference_images).toEqual([{ src: '/apps/learning/img/ref.png', caption: 'Ref' }])
	})

	it('placement: switching the topology off still removes it', () => {
		const vm = createTool('placement', {
			positions: [{ id: 'n1', label: 'A', correct: 'router' }],
			topology: { nodes: [{ id: 'n1', type: 'router', label: '', x: 1, y: 2 }], links: [] },
		})
		vm.placementForm.useTopology = false
		expect(applied(vm)).not.toHaveProperty('topology')
	})

	it('dropdown: extra question fields and section note_format survive', () => {
		const vm = createTool('dropdown', {
			instructions: ['Pick the right values'],
			sections: [{ id: 'log', title: 'Log', note: 'line', note_format: 'log' }],
			questions: [{ id: 'q1', section: 'log', label: 'Port', options: ['22', '443'], correct: '443', points: 3 }],
		})
		vm.dropdownForm.questions[0].label = 'TLS port'
		vm.dropdownForm.questions[0].placeholder = ''
		const config = applied(vm)
		expect(config.questions[0]).toMatchObject({ id: 'q1', label: 'TLS port', correct: '443', points: 3 })
		expect(config.questions[0]).not.toHaveProperty('placeholder')
		expect(config.sections[0].note_format).toBe('log')
		expect(config.instructions).toEqual(['Pick the right values'])
	})

	it('multi_panel: cli.evaluation (scored by the server), top-level and nested panel extras survive', () => {
		const rules = [{ terminal: 'Router', required_pattern: 'no ip address', points: 1 }]
		const vm = createTool('multi_panel', {
			instructions: ['Fix the link'],
			scenario_image: '/apps/learning/img/scenario.png',
			cli: { domain: 'cisco_ios', terminals: [{ name: 'Router' }], command_outputs: { 'show int': 'up' }, evaluation: rules },
			placement: {
				positions: [{ id: 'p1', label: 'A', correct: 'switch', x_pct: 5, y_pct: 6 }],
				device_options: ['switch'],
				scoring_mode: 'strict',
				background_image: '/apps/learning/img/net.png',
			},
		})
		vm.cliForm.commandOutputs[0].output = 'down'
		const config = applied(vm)
		expect(config.cli.evaluation).toEqual(rules)
		expect(config.instructions).toEqual(['Fix the link'])
		expect(config.scenario_image).toBe('/apps/learning/img/scenario.png')
		expect(config.cli.command_outputs).toEqual({ 'show int': 'down' })
		expect(config.placement.positions[0]).toMatchObject({ id: 'p1', x_pct: 5, y_pct: 6 })
		expect(config.placement.background_image).toBe('/apps/learning/img/net.png')
	})

	it('switching the subtype does not carry over the old config', () => {
		const vm = createTool('cli', { domain: 'cisco_ios', terminals: [{ name: 'Router' }], command_outputs: {}, evaluation: EVALUATION })
		vm.selectedSubtype = 'dropdown'
		const config = applied(vm)
		expect(config).not.toHaveProperty('evaluation')
		expect(config).not.toHaveProperty('terminals')
	})
})
