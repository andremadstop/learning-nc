import { describe, expect, it } from 'vitest'

import {
	calculateFsrsIntervalDays,
	calculateFsrsRetrievability,
	initializeFsrsFromRating,
	previewFsrsReview,
	formatFsrsIntervalLabel,
} from '../../src/utils/fsrsScheduler'

describe('fsrsScheduler edge cases', () => {
	describe('stability = 0', () => {
		it('calculateFsrsIntervalDays does not produce NaN or Infinity', () => {
			const result = calculateFsrsIntervalDays(0)
			expect(Number.isFinite(result)).toBe(true)
			expect(Number.isNaN(result)).toBe(false)
			expect(result).toBeGreaterThanOrEqual(1)
		})

		it('calculateFsrsRetrievability does not produce NaN or Infinity', () => {
			const result = calculateFsrsRetrievability(0, 5)
			expect(Number.isFinite(result)).toBe(true)
			expect(Number.isNaN(result)).toBe(false)
		})

		it('previewFsrsReview with stability=0 falls back to initialization', () => {
			const result = previewFsrsReview({ stability: 0, difficulty: 0.5 }, 3)
			expect(Number.isFinite(result.stability)).toBe(true)
			expect(Number.isFinite(result.difficulty)).toBe(true)
			expect(Number.isFinite(result.intervalDays)).toBe(true)
			expect(Number.isFinite(result.retrievability)).toBe(true)
		})
	})

	describe('stability = 999999 (very high)', () => {
		it('calculateFsrsIntervalDays produces a large but finite interval', () => {
			const result = calculateFsrsIntervalDays(999999)
			expect(Number.isFinite(result)).toBe(true)
			expect(result).toBeGreaterThan(1000)
		})

		it('calculateFsrsRetrievability remains close to 1.0 for short elapsed time', () => {
			const result = calculateFsrsRetrievability(999999, 1)
			expect(result).toBeGreaterThan(0.99)
			expect(result).toBeLessThanOrEqual(1.0)
		})

		it('previewFsrsReview produces finite values', () => {
			const now = 1_800_000_000
			const result = previewFsrsReview(
				{ stability: 999999, difficulty: 0.5, last_reviewed: now - 86400 },
				3,
				now,
			)
			expect(Number.isFinite(result.stability)).toBe(true)
			expect(Number.isFinite(result.intervalDays)).toBe(true)
			expect(Number.isFinite(result.retrievability)).toBe(true)
		})
	})

	describe('difficulty = 0 (minimum boundary)', () => {
		it('previewFsrsReview falls back to initialization when difficulty is 0', () => {
			const result = previewFsrsReview({ stability: 5, difficulty: 0 }, 2)
			expect(Number.isFinite(result.stability)).toBe(true)
			expect(Number.isFinite(result.difficulty)).toBe(true)
			expect(result.difficulty).toBeGreaterThan(0)
		})
	})

	describe('difficulty = 1 (maximum boundary)', () => {
		it('previewFsrsReview handles maximum difficulty', () => {
			const now = 1_800_000_000
			const result = previewFsrsReview(
				{ stability: 5, difficulty: 1, last_reviewed: now - 86400 },
				1,
				now,
			)
			expect(Number.isFinite(result.stability)).toBe(true)
			expect(Number.isFinite(result.difficulty)).toBe(true)
			expect(result.difficulty).toBeGreaterThanOrEqual(0.1)
			expect(result.difficulty).toBeLessThanOrEqual(1.0)
		})
	})

	describe('t = 0 (just reviewed)', () => {
		it('retrievability should be approximately 1.0', () => {
			const result = calculateFsrsRetrievability(5, 0)
			expect(result).toBeCloseTo(1.0, 5)
		})

		it('previewFsrsReview with last_reviewed = now has retrievability ~1.0', () => {
			const now = 1_800_000_000
			const result = previewFsrsReview(
				{ stability: 5, difficulty: 0.5, last_reviewed: now },
				3,
				now,
			)
			expect(result.retrievability).toBeCloseTo(1.0, 5)
		})
	})

	describe('t = very large (10000 days)', () => {
		it('retrievability should approach 0', () => {
			const result = calculateFsrsRetrievability(5, 10000)
			expect(result).toBeGreaterThanOrEqual(0)
			expect(result).toBeLessThan(0.01)
		})

		it('retrievability is still a finite number', () => {
			const result = calculateFsrsRetrievability(5, 10000)
			expect(Number.isFinite(result)).toBe(true)
		})
	})

	describe('negative stability (defensive)', () => {
		it('calculateFsrsIntervalDays does not crash', () => {
			const result = calculateFsrsIntervalDays(-5)
			expect(Number.isFinite(result)).toBe(true)
			expect(result).toBeGreaterThanOrEqual(1)
		})

		it('calculateFsrsRetrievability does not crash', () => {
			const result = calculateFsrsRetrievability(-5, 3)
			expect(Number.isFinite(result)).toBe(true)
			expect(Number.isNaN(result)).toBe(false)
		})

		it('previewFsrsReview with negative stability falls back to initialization', () => {
			const result = previewFsrsReview({ stability: -5, difficulty: 0.5 }, 2)
			expect(Number.isFinite(result.stability)).toBe(true)
			expect(result.stability).toBeGreaterThan(0)
		})
	})

	describe('NaN input', () => {
		it('calculateFsrsIntervalDays does not propagate NaN', () => {
			const result = calculateFsrsIntervalDays(NaN)
			expect(Number.isNaN(result)).toBe(false)
			expect(Number.isFinite(result)).toBe(true)
		})

		it('calculateFsrsRetrievability does not propagate NaN for stability', () => {
			const result = calculateFsrsRetrievability(NaN, 5)
			expect(Number.isNaN(result)).toBe(false)
			expect(Number.isFinite(result)).toBe(true)
		})

		it('calculateFsrsRetrievability does not propagate NaN for elapsedDays', () => {
			const result = calculateFsrsRetrievability(5, NaN)
			expect(Number.isNaN(result)).toBe(false)
			expect(Number.isFinite(result)).toBe(true)
		})

		it('formatFsrsIntervalLabel does not propagate NaN', () => {
			const result = formatFsrsIntervalLabel(NaN)
			expect(result).toBe('1 day')
		})

		it('previewFsrsReview with NaN stability falls back to initialization', () => {
			const result = previewFsrsReview({ stability: NaN, difficulty: 0.5 }, 3)
			expect(Number.isNaN(result.stability)).toBe(false)
			expect(Number.isFinite(result.stability)).toBe(true)
		})
	})
})
