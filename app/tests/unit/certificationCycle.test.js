import { describe, expect, it } from 'vitest'
import { attemptsLeft, memberCycleStatus } from '../../src/utils/certificationCycle.js'

describe('certification cycle helpers (GitHub #84 / #87)', () => {
	it('names why a learner is stuck, or that a new cycle is open', () => {
		expect(memberCycleStatus(undefined)).toBeNull()
		expect(memberCycleStatus({ reason: 'revoked', new_cycle: null })).toBe('revoked')
		expect(memberCycleStatus({ reason: 'expired', new_cycle: null })).toBe('expired')
		expect(memberCycleStatus({ reason: 'attempts', new_cycle: { reason: 'attempts', since: 1 } })).toBe('attempts')
		expect(memberCycleStatus({ reason: null, new_cycle: { reason: 'revoked', since: 1 } })).toBe('cycle_open')
		expect(memberCycleStatus({ reason: null, new_cycle: null })).toBeNull()
		expect(memberCycleStatus({ reason: 'something-else', new_cycle: null })).toBeNull()
	})

	it('counts the attempts left on a pool, null without a total limit', () => {
		expect(attemptsLeft(null, 3)).toBeNull()
		expect(attemptsLeft({ limit_per_day: 5, total_limit: null, used_by_pool: [] }, 3)).toBeNull()
		expect(attemptsLeft({ total_limit: 3, used_by_pool: { 3: 1 } }, 3)).toBe(2)
		expect(attemptsLeft({ total_limit: 3, used_by_pool: { 3: 5 } }, 3)).toBe(0)
		expect(attemptsLeft({ total_limit: 3, used_by_pool: [] }, 3)).toBe(3)
	})
})
