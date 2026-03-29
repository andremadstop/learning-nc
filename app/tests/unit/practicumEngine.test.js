import { describe, it, expect, beforeEach, vi } from 'vitest'
import {
	PracticumEngine,
	loadSessionsForSimulator,
	PRACTICUM_SESSIONS,
} from '../../src/utils/practicumEngine.js'

const mockSession = {
	id: 'test-session-1',
	simulatorType: 'firewall',
	title: 'Test Session',
	description: 'A test session with 3 steps',
	steps: [
		{ stepId: 'step-01', context: 'Step 1 context', explanation: 'Step 1 explanation', scenarioId: 'web-only' },
		{ stepId: 'step-02', context: 'Step 2 context', explanation: 'Step 2 explanation', scenarioId: 'ssh-admin-net' },
		{ stepId: 'step-03', context: 'Step 3 context', explanation: 'Step 3 explanation', scenarioId: 'icmp-external' },
	],
}

describe('PracticumEngine', () => {
	beforeEach(() => {
		localStorage.clear()
	})

	describe('constructor and initial state', () => {
		it('initializes at step 0 with empty results', () => {
			const engine = new PracticumEngine(mockSession)
			expect(engine.stepIndex).toBe(0)
			expect(engine.totalSteps).toBe(3)
			expect(engine.isComplete).toBe(false)
			expect(engine.sessionId).toBe('test-session-1')
		})

		it('currentStep returns first step object', () => {
			const engine = new PracticumEngine(mockSession)
			expect(engine.currentStep).toEqual(mockSession.steps[0])
			expect(engine.currentStep.scenarioId).toBe('web-only')
		})
	})

	describe('step progression', () => {
		it('recordResult advances to next step', () => {
			const engine = new PracticumEngine(mockSession)
			engine.recordResult({ passed: true, score: 1 })
			expect(engine.stepIndex).toBe(1)
			expect(engine.currentStep.scenarioId).toBe('ssh-admin-net')
		})

		it('recordResult stores result in order', () => {
			const engine = new PracticumEngine(mockSession)
			engine.recordResult({ passed: true, score: 1 })
			engine.recordResult({ passed: false, score: 0 })
			expect(engine.stepIndex).toBe(2)
			expect(engine.currentStep.scenarioId).toBe('icmp-external')
		})

		it('isComplete after all steps recorded', () => {
			const engine = new PracticumEngine(mockSession)
			engine.recordResult({ passed: true, score: 1 })
			engine.recordResult({ passed: false, score: 0 })
			engine.recordResult({ passed: true, score: 1 })
			expect(engine.isComplete).toBe(true)
			expect(engine.currentStep).toBeNull()
		})
	})

	describe('summary', () => {
		it('returns correct score as X/Y string', () => {
			const engine = new PracticumEngine(mockSession)
			engine.recordResult({ passed: true, score: 1 })
			engine.recordResult({ passed: false, score: 0 })
			engine.recordResult({ passed: true, score: 1 })
			const summary = engine.summary
			expect(summary.sessionId).toBe('test-session-1')
			expect(summary.score).toBe('2/3')
			expect(summary.results).toHaveLength(3)
			expect(summary.startedAt).toBeTruthy()
			expect(summary.completedAt).toBeTruthy()
		})

		it('completedAt is null when not finished', () => {
			const engine = new PracticumEngine(mockSession)
			engine.recordResult({ passed: true, score: 1 })
			expect(engine.summary.completedAt).toBeNull()
		})
	})

	describe('localStorage persistence', () => {
		it('persist() saves state to localStorage', () => {
			const engine = new PracticumEngine(mockSession)
			engine.recordResult({ passed: true, score: 1 })
			engine.persist()
			const stored = JSON.parse(localStorage.getItem('lnc-practicum-test-session-1'))
			expect(stored.sessionId).toBe('test-session-1')
			expect(stored.stepIndex).toBe(1)
			expect(stored.results).toHaveLength(1)
			expect(stored.startedAt).toBeTruthy()
		})

		it('restore() recreates engine at saved position', () => {
			const engine = new PracticumEngine(mockSession)
			engine.recordResult({ passed: true, score: 1 })
			engine.recordResult({ passed: false, score: 0 })
			engine.persist()

			const restored = PracticumEngine.restore('test-session-1', mockSession)
			expect(restored).not.toBeNull()
			expect(restored.stepIndex).toBe(2)
			expect(restored.currentStep.scenarioId).toBe('icmp-external')
			expect(restored.summary.results).toHaveLength(2)
		})

		it('restore() returns null if no saved state', () => {
			const result = PracticumEngine.restore('nonexistent', mockSession)
			expect(result).toBeNull()
		})

		it('reset() clears saved state and returns to step 0', () => {
			const engine = new PracticumEngine(mockSession)
			engine.recordResult({ passed: true, score: 1 })
			engine.persist()
			engine.reset()
			expect(engine.stepIndex).toBe(0)
			expect(engine.summary.results).toHaveLength(0)
			expect(localStorage.getItem('lnc-practicum-test-session-1')).toBeNull()
		})
	})

	describe('edge cases', () => {
		it('recordResult after completion does nothing', () => {
			const engine = new PracticumEngine(mockSession)
			engine.recordResult({ passed: true, score: 1 })
			engine.recordResult({ passed: true, score: 1 })
			engine.recordResult({ passed: true, score: 1 })
			engine.recordResult({ passed: true, score: 1 }) // extra call
			expect(engine.summary.results).toHaveLength(3)
			expect(engine.stepIndex).toBe(3)
		})
	})
})

describe('loadSessionsForSimulator', () => {
	it('returns array for known simulator type', () => {
		const sessions = loadSessionsForSimulator('firewall')
		expect(Array.isArray(sessions)).toBe(true)
	})

	it('returns terminal practicum sessions for the terminal simulator', () => {
		const sessions = loadSessionsForSimulator('terminal')
		expect(Array.isArray(sessions)).toBe(true)
		expect(sessions.length).toBeGreaterThanOrEqual(2)
		expect(sessions.every(session => session.simulatorType === 'terminal')).toBe(true)
	})

	it('returns empty array for unknown type', () => {
		const sessions = loadSessionsForSimulator('nonexistent')
		expect(sessions).toEqual([])
	})
})

describe('PRACTICUM_SESSIONS registry', () => {
	it('has entries for all practicum-enabled simulator types', () => {
		const expectedTypes = ['firewall', 'dns', 'routing', 'nat', 'portscan', 'wireshark', 'authflow', 'subnet', 'terminal']
		for (const type of expectedTypes) {
			expect(PRACTICUM_SESSIONS).toHaveProperty(type)
			expect(Array.isArray(PRACTICUM_SESSIONS[type])).toBe(true)
		}
	})
})
