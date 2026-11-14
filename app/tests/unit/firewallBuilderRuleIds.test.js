/**
 * Audit #98: addRule() used `rule-${rules.length + 1}`, which repeats an existing ID after a
 * removal — duplicate v-for keys, and the packet test highlighted two rows as the match.
 */
import { describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (_app, text) => String(text)
})
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))

import FirewallBuilder from '../../src/components/FirewallBuilder.vue'

function createVm() {
	const vm = { mode: 'standalone', scenario: null }
	Object.assign(vm, FirewallBuilder.data.call(vm))
	for (const [name, fn] of Object.entries(FirewallBuilder.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('FirewallBuilder rule ids', () => {
	it('stay unique after add, add, remove, add', () => {
		const vm = createVm()
		vm.addRule()
		vm.addRule()
		vm.removeRule(vm.rules.length - 2)
		vm.addRule()
		vm.addRule()

		const ids = vm.rules.map((rule) => rule.id)
		expect(new Set(ids).size).toBe(ids.length)
	})
})
