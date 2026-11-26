import { describe, expect, it } from 'vitest'
import { nextStartFor, waitClock, waitSecondsUntil } from '../../src/utils/examWait.js'

describe('examWait (GitHub #92)', () => {
	it('reads the next start of a pool from the pass status, number or string key', () => {
		expect(nextStartFor({ next_start_by_pool: { 3: 1700000600 } }, 3)).toBe(1700000600)
		expect(nextStartFor({ next_start_by_pool: { 3: '1700000600' } }, '3')).toBe(1700000600)
		expect(nextStartFor({ next_start_by_pool: {} }, 3)).toBeNull()
		expect(nextStartFor({ next_start_by_pool: [] }, 3)).toBeNull()
		expect(nextStartFor({}, 3)).toBeNull()
		expect(nextStartFor(null, 3)).toBeNull()
		expect(nextStartFor({ next_start_by_pool: { 3: 0 } }, 3)).toBeNull()
	})

	it('counts whole seconds up, never below zero', () => {
		expect(waitSecondsUntil(1000, 900.2)).toBe(100)
		expect(waitSecondsUntil(1000, 1001)).toBe(0)
		expect(waitSecondsUntil(null, 900)).toBe(0)
	})

	it('shows minutes and seconds, and hours from one hour on', () => {
		expect(waitClock(90)).toBe('1:30')
		expect(waitClock(5)).toBe('0:05')
		expect(waitClock(3600 + 2 * 60 + 9)).toBe('1:02:09')
		expect(waitClock(-3)).toBe('0:00')
	})
})
