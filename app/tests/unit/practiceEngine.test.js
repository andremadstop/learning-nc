import { describe, it, expect } from 'vitest'
import {
	normalizeAnswer,
	checkAnswers,
	createPracticeSession,
	nextScenario,
	submitAnswer,
	getProgress,
	DEMO_SCENARIOS,
} from '../../src/utils/practiceEngine.js'

describe('normalizeAnswer', () => {
	it('trims whitespace', () => {
		expect(normalizeAnswer('  192.168.0.0  ')).toBe('192.168.0.0')
	})

	it('preserves CIDR notation with slash', () => {
		expect(normalizeAnswer('192.168.0.0/24')).toBe('192.168.0.0/24')
	})

	it('strips leading zeros from octets', () => {
		expect(normalizeAnswer('010.001.000.005')).toBe('10.1.0.5')
	})

	it('leaves plain numbers unchanged', () => {
		expect(normalizeAnswer('254')).toBe('254')
	})

	it('returns empty string for empty input', () => {
		expect(normalizeAnswer('')).toBe('')
	})

	it('handles CIDR with leading zeros in IP part', () => {
		expect(normalizeAnswer('010.000.001.000/24')).toBe('10.0.1.0/24')
	})

	it('handles subnet mask format', () => {
		expect(normalizeAnswer('255.255.255.240')).toBe('255.255.255.240')
	})

	it('handles null/undefined gracefully', () => {
		expect(normalizeAnswer(null)).toBe('')
		expect(normalizeAnswer(undefined)).toBe('')
	})
})

describe('checkAnswers', () => {
	it('returns correct for matching answers', () => {
		const result = checkAnswers(
			{ networkAddress: '192.168.1.0' },
			{ networkAddress: '192.168.1.0' },
		)
		expect(result).toHaveLength(1)
		expect(result[0]).toEqual({
			field: 'networkAddress',
			correct: true,
			expected: '192.168.1.0',
			given: '192.168.1.0',
		})
	})

	it('returns incorrect for mismatched answers', () => {
		const result = checkAnswers(
			{ networkAddress: '192.168.1.0' },
			{ networkAddress: '192.168.2.0' },
		)
		expect(result[0].correct).toBe(false)
		expect(result[0].expected).toBe('192.168.1.0')
		expect(result[0].given).toBe('192.168.2.0')
	})

	it('handles CIDR with or without slash flexibly', () => {
		const result = checkAnswers({ cidr: '/24' }, { cidr: '24' })
		expect(result[0].correct).toBe(true)
	})

	it('handles CIDR both with slash', () => {
		const result = checkAnswers({ cidr: '/24' }, { cidr: '/24' })
		expect(result[0].correct).toBe(true)
	})

	it('trims whitespace in user answers', () => {
		const result = checkAnswers({ hostCount: '254' }, { hostCount: ' 254 ' })
		expect(result[0].correct).toBe(true)
	})

	it('handles missing user answer as empty string', () => {
		const result = checkAnswers({ networkAddress: '192.168.1.0' }, {})
		expect(result[0].correct).toBe(false)
		expect(result[0].given).toBe('')
	})

	it('checks multiple fields at once', () => {
		const result = checkAnswers(
			{ networkAddress: '192.168.1.0', broadcast: '192.168.1.255' },
			{ networkAddress: '192.168.1.0', broadcast: '192.168.1.254' },
		)
		expect(result).toHaveLength(2)
		expect(result.find(r => r.field === 'networkAddress').correct).toBe(true)
		expect(result.find(r => r.field === 'broadcast').correct).toBe(false)
	})
})

describe('createPracticeSession', () => {
	it('uses DEMO_SCENARIOS by default', () => {
		const session = createPracticeSession()
		expect(session.scenarios).toHaveLength(DEMO_SCENARIOS.length)
		expect(session.current).toBeNull()
		expect(session.correct).toBe(0)
		expect(session.total).toBe(0)
		expect(session.streak).toBe(0)
		expect(session.maxStreak).toBe(0)
	})

	it('uses provided scenarios', () => {
		const custom = [{ id: 'test-1', expectedAnswers: {} }]
		const session = createPracticeSession(custom)
		expect(session.scenarios).toHaveLength(1)
	})

	it('does not mutate original array', () => {
		const custom = [{ id: 'a' }, { id: 'b' }]
		const session = createPracticeSession(custom)
		// Session has its own copy
		expect(session.scenarios).not.toBe(custom)
	})
})

describe('nextScenario', () => {
	it('returns a scenario not yet answered', () => {
		const session = createPracticeSession([
			{ id: 's1', expectedAnswers: {} },
			{ id: 's2', expectedAnswers: {} },
		])
		const scenario = nextScenario(session)
		expect(scenario).not.toBeNull()
		expect(session.current).toBe(scenario)
	})

	it('returns null when all scenarios answered', () => {
		const session = createPracticeSession([{ id: 's1', expectedAnswers: {} }])
		session.answered.add('s1')
		const result = nextScenario(session)
		expect(result).toBeNull()
	})

	it('skips already answered scenarios', () => {
		const scenarios = [
			{ id: 's1', expectedAnswers: {} },
			{ id: 's2', expectedAnswers: {} },
		]
		const session = createPracticeSession(scenarios)
		// Answer first scenario manually
		const first = nextScenario(session)
		session.answered.add(first.id)
		const second = nextScenario(session)
		expect(second).not.toBeNull()
		expect(second.id).not.toBe(first.id)
	})
})

describe('submitAnswer', () => {
	it('increments total on every submission', () => {
		const session = createPracticeSession([
			{ id: 's1', expectedAnswers: { hostCount: '14' } },
		])
		nextScenario(session)
		const results = checkAnswers(session.current.expectedAnswers, { hostCount: '14' })
		submitAnswer(session, results)
		expect(session.total).toBe(1)
	})

	it('increments correct and streak on all-correct answer', () => {
		const session = createPracticeSession([
			{ id: 's1', expectedAnswers: { hostCount: '14' } },
		])
		nextScenario(session)
		const results = checkAnswers(session.current.expectedAnswers, { hostCount: '14' })
		submitAnswer(session, results)
		expect(session.correct).toBe(1)
		expect(session.streak).toBe(1)
		expect(session.maxStreak).toBe(1)
	})

	it('resets streak on wrong answer', () => {
		const session = createPracticeSession([
			{ id: 's1', expectedAnswers: { hostCount: '14' } },
			{ id: 's2', expectedAnswers: { hostCount: '62' } },
		])

		// First: correct (use current scenario's own expected answers)
		nextScenario(session)
		submitAnswer(session, checkAnswers(session.current.expectedAnswers, { ...session.current.expectedAnswers }))
		expect(session.streak).toBe(1)

		// Second: wrong
		nextScenario(session)
		submitAnswer(session, checkAnswers(session.current.expectedAnswers, { hostCount: '999' }))
		expect(session.streak).toBe(0)
		expect(session.correct).toBe(1)
		expect(session.maxStreak).toBe(1)
	})

	it('adds current scenario id to answered set', () => {
		const session = createPracticeSession([
			{ id: 's1', expectedAnswers: { hostCount: '14' } },
		])
		nextScenario(session)
		const results = checkAnswers(session.current.expectedAnswers, { hostCount: '14' })
		submitAnswer(session, results)
		expect(session.answered.has('s1')).toBe(true)
	})
})

describe('getProgress', () => {
	it('returns initial progress', () => {
		const session = createPracticeSession([
			{ id: 's1', expectedAnswers: {} },
			{ id: 's2', expectedAnswers: {} },
		])
		const progress = getProgress(session)
		expect(progress).toEqual({
			correct: 0,
			total: 0,
			streak: 0,
			maxStreak: 0,
			remaining: 2,
		})
	})

	it('reflects progress after submissions', () => {
		const session = createPracticeSession([
			{ id: 's1', expectedAnswers: { hostCount: '14' } },
			{ id: 's2', expectedAnswers: { hostCount: '62' } },
		])
		nextScenario(session)
		submitAnswer(session, checkAnswers(session.current.expectedAnswers, { ...session.current.expectedAnswers }))
		const progress = getProgress(session)
		expect(progress.correct).toBe(1)
		expect(progress.total).toBe(1)
		expect(progress.remaining).toBe(1)
	})
})

describe('DEMO_SCENARIOS', () => {
	it('contains 5 scenarios', () => {
		expect(DEMO_SCENARIOS).toHaveLength(5)
	})

	it('each scenario has required fields', () => {
		for (const s of DEMO_SCENARIOS) {
			expect(s).toHaveProperty('id')
			expect(s).toHaveProperty('type')
			expect(s).toHaveProperty('difficulty')
			expect(s).toHaveProperty('question')
			expect(s).toHaveProperty('expectedAnswers')
		}
	})

	it('covers subnet, vlsm, and ipv6 types', () => {
		const types = new Set(DEMO_SCENARIOS.map(s => s.type))
		expect(types.has('subnet')).toBe(true)
		expect(types.has('vlsm')).toBe(true)
		expect(types.has('ipv6')).toBe(true)
	})

	it('has valid difficulty values', () => {
		const valid = ['easy', 'medium', 'hard']
		for (const s of DEMO_SCENARIOS) {
			expect(valid).toContain(s.difficulty)
		}
	})
})
