/**
 * multi_panel PBQs: the real PbqMultiPanel merge methods and the real PbqRenderer progress count
 * (audit #126: this file used to test its own copies, which had drifted and ignored the dropdown
 * panel, plus a test that compared a constant with itself).
 */
import { describe, it, expect, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))

globalThis.t = (app, text) => text

import PbqMultiPanel from '../../src/components/PbqMultiPanel.vue'
import PbqRenderer from '../../src/components/PbqRenderer.vue'

function panel(value = {}) {
	return { value, $emit: vi.fn() }
}

// PbqRenderer with its computed properties bound to a fake instance.
function renderer(question, localAnswer = {}) {
	const vm = { question, localAnswer }
	for (const [name, def] of Object.entries(PbqRenderer.computed)) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(PbqRenderer.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('PbqMultiPanel merges a panel update into its own namespace', () => {
	it('onCliUpdate emits the terminal history under cli', () => {
		const vm = panel()
		PbqMultiPanel.methods.onCliUpdate.call(vm, 'Router', ['Router> show version', 'Cisco IOS...'])
		expect(vm.$emit).toHaveBeenCalledWith('update', 'cli', { Router: ['Router> show version', 'Cisco IOS...'] })
	})

	it('onCliUpdate replaces one terminal and keeps the others', () => {
		const vm = panel({ cli: { Router: ['old line'], Switch: ['sw line'] }, placement: { n1: 'router' } })
		PbqMultiPanel.methods.onCliUpdate.call(vm, 'Router', ['new line'])
		expect(vm.$emit).toHaveBeenCalledWith('update', 'cli', { Router: ['new line'], Switch: ['sw line'] })
	})

	it('onPlacementUpdate emits the device assignment under placement and keeps earlier ones', () => {
		const vm = panel({ placement: { n1: 'router' } })
		PbqMultiPanel.methods.onPlacementUpdate.call(vm, 'n2', 'switch')
		expect(vm.$emit).toHaveBeenCalledWith('update', 'placement', { n1: 'router', n2: 'switch' })
	})

	it('onDropdownUpdate emits the answer under dropdown and keeps earlier ones', () => {
		const vm = panel({ dropdown: { q1: '22' } })
		PbqMultiPanel.methods.onDropdownUpdate.call(vm, 'q2', '443')
		expect(vm.$emit).toHaveBeenCalledWith('update', 'dropdown', { q1: '22', q2: '443' })
	})
})

describe('PbqRenderer progress for multi_panel', () => {
	it('totalCount sums terminals and placement positions', () => {
		const vm = renderer({
			pbq_subtype: 'multi_panel',
			pbq_config: {
				cli: { terminals: [{ name: 'Router' }, { name: 'Switch' }] },
				placement: { positions: [{ id: 'n1' }, { id: 'n2' }, { id: 'n3' }] },
			},
		})
		expect(vm.totalCount).toBe(5)
	})

	it('totalCount counts scorable dropdown questions of the dropdown panel', () => {
		const vm = renderer({
			pbq_subtype: 'multi_panel',
			pbq_config: {
				cli: { terminals: [{ name: 'Router' }] },
				dropdown: { questions: [{ id: 'q1' }, { id: 'q2', scorable: false }, { id: 'q3' }] },
			},
		})
		expect(vm.totalCount).toBe(3)
	})

	it('answeredCount sums the cli, placement and dropdown sub-answers', () => {
		const vm = renderer({ pbq_subtype: 'multi_panel', pbq_config: {} }, {
			cli: { Router: ['cmd1'], Switch: ['cmd2'] },
			placement: { n1: 'router' },
			dropdown: { q1: '22', q2: '443' },
		})
		expect(vm.answeredCount).toBe(5)
	})

	it('answeredCount for other subtypes counts the top-level answers', () => {
		const vm = renderer({ pbq_subtype: 'dropdown', pbq_config: {} }, { q1: '22', q2: '443' })
		expect(vm.answeredCount).toBe(2)
	})
})
