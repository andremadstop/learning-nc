import { describe, expect, it } from 'vitest'
import {
	SIMULATOR_MAP,
	normalizeResult,
	resolveScenario,
} from '../../src/utils/simulatorShellLogic.js'

describe('SIMULATOR_MAP', () => {
	it('has exactly 8 keys', () => {
		expect(Object.keys(SIMULATOR_MAP)).toHaveLength(8)
	})

	it('contains all simulator types', () => {
		const expected = ['firewall', 'dns', 'routing', 'nat', 'portscan', 'wireshark', 'authflow', 'terminal']
		expect(Object.keys(SIMULATOR_MAP).sort()).toEqual(expected.sort())
	})

	it('each value is a function (dynamic import factory)', () => {
		for (const key of Object.keys(SIMULATOR_MAP)) {
			expect(typeof SIMULATOR_MAP[key]).toBe('function')
		}
	})
})

describe('normalizeResult', () => {
	it('{ correct: true } returns passed=true, score=1.0', () => {
		const result = normalizeResult({ correct: true })
		expect(result.passed).toBe(true)
		expect(result.score).toBe(1.0)
		expect(result.rawResult).toEqual({ correct: true })
	})

	it('{ correct: false } returns passed=false, score=0.0', () => {
		const result = normalizeResult({ correct: false })
		expect(result.passed).toBe(false)
		expect(result.score).toBe(0.0)
	})

	it('{ passed: true } (AuthFlowSimulator) returns passed=true', () => {
		const result = normalizeResult({ passed: true, errors: [] })
		expect(result.passed).toBe(true)
		expect(result.score).toBe(1.0)
	})

	it('{ passed: false } (AuthFlowSimulator) returns passed=false', () => {
		const result = normalizeResult({ passed: false, errors: ['wrong step'] })
		expect(result.passed).toBe(false)
		expect(result.score).toBe(0.0)
	})

	it('{ kind: "lookup" } returns null (DNS free-lookup ignored)', () => {
		const result = normalizeResult({ kind: 'lookup', answer: '1.2.3.4' })
		expect(result).toBeNull()
	})

	it('{ kind: "exercise", correct: true } returns passed=true', () => {
		const result = normalizeResult({ kind: 'exercise', correct: true })
		expect(result.passed).toBe(true)
		expect(result.score).toBe(1.0)
	})

	it('{ kind: "exercise", correct: false } returns passed=false', () => {
		const result = normalizeResult({ kind: 'exercise', correct: false })
		expect(result.passed).toBe(false)
		expect(result.score).toBe(0.0)
	})
})

describe('resolveScenario', () => {
	it('returns scenarioOverride when set (takes precedence over scenarioId)', () => {
		const override = { id: 'custom', name: 'Custom Scenario' }
		const result = resolveScenario('firewall', 'web-only', override)
		expect(result).toBe(override)
	})

	it('looks up scenario by id in SCENARIOS[type] when no override', () => {
		const result = resolveScenario('firewall', 'web-only', null)
		expect(result).not.toBeNull()
		expect(result.id).toBe('web-only')
	})

	it('returns null when scenarioId not found and no override', () => {
		const result = resolveScenario('firewall', 'nonexistent-scenario-xyz', null)
		expect(result).toBeNull()
	})
})
