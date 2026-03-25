import { describe, expect, it } from 'vitest'
import SimulatorShell, { SIMULATOR_MAP } from '../../src/components/SimulatorShell.vue'

describe('SIMULATOR_MAP', () => {
	it('has exactly 7 keys', () => {
		expect(Object.keys(SIMULATOR_MAP)).toHaveLength(7)
	})

	it('contains all simulator types', () => {
		const expected = ['firewall', 'dns', 'routing', 'nat', 'portscan', 'wireshark', 'authflow']
		expect(Object.keys(SIMULATOR_MAP).sort()).toEqual(expected.sort())
	})

	it('each value is a function (dynamic import factory)', () => {
		for (const key of Object.keys(SIMULATOR_MAP)) {
			expect(typeof SIMULATOR_MAP[key]).toBe('function')
		}
	})
})

describe('onResult normalization', () => {
	function callOnResult(payload, type = 'firewall') {
		const vm = {
			type,
			scenarioId: '',
			scenarioOverride: null,
			emitted: [],
			$emit(event, ...args) { this.emitted.push([event, ...args]) },
		}
		SimulatorShell.methods.onResult.call(vm, payload)
		return vm.emitted
	}

	it('{ correct: true } emits complete with passed=true, score=1.0', () => {
		const emitted = callOnResult({ correct: true })
		expect(emitted).toHaveLength(1)
		expect(emitted[0][0]).toBe('complete')
		expect(emitted[0][1]).toBe(true)
		expect(emitted[0][2]).toBe(1.0)
		expect(emitted[0][3]).toEqual({ correct: true })
	})

	it('{ correct: false } emits complete with passed=false, score=0.0', () => {
		const emitted = callOnResult({ correct: false })
		expect(emitted).toHaveLength(1)
		expect(emitted[0][0]).toBe('complete')
		expect(emitted[0][1]).toBe(false)
		expect(emitted[0][2]).toBe(0.0)
	})

	it('{ passed: true } (AuthFlowSimulator) emits complete with passed=true', () => {
		const emitted = callOnResult({ passed: true, errors: [] }, 'authflow')
		expect(emitted).toHaveLength(1)
		expect(emitted[0][0]).toBe('complete')
		expect(emitted[0][1]).toBe(true)
		expect(emitted[0][2]).toBe(1.0)
	})

	it('{ passed: false } (AuthFlowSimulator) emits complete with passed=false', () => {
		const emitted = callOnResult({ passed: false, errors: ['wrong step'] }, 'authflow')
		expect(emitted).toHaveLength(1)
		expect(emitted[0][1]).toBe(false)
		expect(emitted[0][2]).toBe(0.0)
	})

	it('{ kind: "lookup" } does NOT emit complete (DNS free-lookup ignored)', () => {
		const emitted = callOnResult({ kind: 'lookup', answer: '1.2.3.4' }, 'dns')
		expect(emitted).toHaveLength(0)
	})

	it('{ kind: "exercise", correct: true } emits complete with passed=true', () => {
		const emitted = callOnResult({ kind: 'exercise', correct: true }, 'dns')
		expect(emitted).toHaveLength(1)
		expect(emitted[0][0]).toBe('complete')
		expect(emitted[0][1]).toBe(true)
		expect(emitted[0][2]).toBe(1.0)
	})

	it('{ kind: "exercise", correct: false } emits complete with passed=false', () => {
		const emitted = callOnResult({ kind: 'exercise', correct: false }, 'dns')
		expect(emitted).toHaveLength(1)
		expect(emitted[0][1]).toBe(false)
		expect(emitted[0][2]).toBe(0.0)
	})
})

describe('resolvedScenario', () => {
	it('returns scenarioOverride when set (takes precedence over scenarioId)', () => {
		const override = { id: 'custom', name: 'Custom Scenario' }
		const vm = {
			type: 'firewall',
			scenarioId: 'web-only',
			scenarioOverride: override,
		}
		const result = SimulatorShell.computed.resolvedScenario.call(vm)
		expect(result).toBe(override)
	})

	it('looks up scenario by id in SCENARIOS[type] when no override', () => {
		const vm = {
			type: 'firewall',
			scenarioId: 'web-only',
			scenarioOverride: null,
		}
		const result = SimulatorShell.computed.resolvedScenario.call(vm)
		expect(result).not.toBeNull()
		expect(result.id).toBe('web-only')
	})

	it('returns null when scenarioId not found and no override', () => {
		const vm = {
			type: 'firewall',
			scenarioId: 'nonexistent-scenario-xyz',
			scenarioOverride: null,
		}
		const result = SimulatorShell.computed.resolvedScenario.call(vm)
		expect(result).toBeNull()
	})
})
