import { describe, expect, it } from 'vitest'

import {
	addSegment,
	consentGateDecision,
	CONSENT_REQUIRED_SOURCES,
	coveredFraction,
	coveredSeconds,
	EMPTY_VTT_DATA_URI,
	mergeIntervals,
	normalizeSegment,
	shouldFlushHeartbeat,
} from '../../src/utils/videoInterval.js'

describe('videoInterval — segment normalization', () => {
	it('drops zero-length and reversed-to-zero segments', () => {
		expect(normalizeSegment(5, 5)).toBeNull()
		expect(normalizeSegment(3, 3)).toBeNull()
	})

	it('orders a reversed segment into a forward interval', () => {
		expect(normalizeSegment(9, 4)).toEqual({ start: 4, end: 9 })
	})

	it('clamps negatives to zero', () => {
		expect(normalizeSegment(-3, 5)).toEqual({ start: 0, end: 5 })
	})

	it('rejects non-finite input', () => {
		expect(normalizeSegment(NaN, 5)).toBeNull()
		expect(normalizeSegment(2, Infinity)).toBeNull()
	})
})

describe('videoInterval — merge + accumulation (client overlap merge)', () => {
	it('merges overlapping intervals', () => {
		const merged = mergeIntervals([
			{ start: 0, end: 10 },
			{ start: 5, end: 15 },
		])
		expect(merged).toEqual([{ start: 0, end: 15 }])
	})

	it('coalesces touching intervals', () => {
		const merged = mergeIntervals([
			{ start: 0, end: 5 },
			{ start: 5, end: 9 },
		])
		expect(merged).toEqual([{ start: 0, end: 9 }])
	})

	it('keeps disjoint intervals separate and sorted', () => {
		const merged = mergeIntervals([
			{ start: 20, end: 25 },
			{ start: 0, end: 5 },
		])
		expect(merged).toEqual([
			{ start: 0, end: 5 },
			{ start: 20, end: 25 },
		])
	})

	it('addSegment merges without mutating the input', () => {
		const base = [{ start: 0, end: 10 }]
		const next = addSegment(base, 8, 20)
		expect(next).toEqual([{ start: 0, end: 20 }])
		// input untouched
		expect(base).toEqual([{ start: 0, end: 10 }])
	})

	it('addSegment ignores a degenerate segment but still returns a merged list', () => {
		const next = addSegment([{ start: 0, end: 10 }], 5, 5)
		expect(next).toEqual([{ start: 0, end: 10 }])
	})

	it('coveredSeconds sums merged (not double-counted) coverage', () => {
		expect(coveredSeconds([
			{ start: 0, end: 10 },
			{ start: 5, end: 15 },
		])).toBe(15)
	})
})

describe('videoInterval — coverage fraction (gate mirror)', () => {
	it('reports the fraction of the duration covered', () => {
		expect(coveredFraction([{ start: 0, end: 95 }], 100)).toBeCloseTo(0.95, 5)
	})

	it('clamps to 1 when the client over-covers', () => {
		expect(coveredFraction([{ start: 0, end: 120 }], 100)).toBe(1)
	})

	it('returns 0 for a null/zero duration (never opens a mis-configured gate)', () => {
		expect(coveredFraction([{ start: 0, end: 50 }], null)).toBe(0)
		expect(coveredFraction([{ start: 0, end: 50 }], 0)).toBe(0)
	})
})

describe('videoInterval — heartbeat throttle decision', () => {
	it('always flushes the first ping (no previous send)', () => {
		expect(shouldFlushHeartbeat(null, 1000)).toBe(true)
		expect(shouldFlushHeartbeat(undefined, 1000)).toBe(true)
	})

	it('suppresses a flush inside the throttle window', () => {
		expect(shouldFlushHeartbeat(1000, 5000, 10000)).toBe(false)
	})

	it('flushes once the throttle window has elapsed', () => {
		expect(shouldFlushHeartbeat(1000, 11000, 10000)).toBe(true)
		expect(shouldFlushHeartbeat(1000, 11001, 10000)).toBe(true)
	})

	it('does not flush on a non-finite clock', () => {
		expect(shouldFlushHeartbeat(1000, NaN, 10000)).toBe(false)
	})
})

describe('videoInterval — third-party consent gate (VIDEO-05)', () => {
	it('nc-files never needs consent', () => {
		expect(consentGateDecision('nc-files', false)).toBe('allowed')
	})

	it('documents never need consent', () => {
		expect(consentGateDecision('document', false)).toBe('allowed')
	})

	it('blocks vimeo until consent is given', () => {
		expect(consentGateDecision('vimeo', false)).toBe('blocked')
		expect(consentGateDecision('vimeo', true)).toBe('allowed')
	})

	it('blocks youtube-nocookie until consent is given', () => {
		expect(consentGateDecision('youtube-nocookie', false)).toBe('blocked')
		expect(consentGateDecision('youtube-nocookie', true)).toBe('allowed')
	})

	it('exposes the consent-required source list', () => {
		expect(CONSENT_REQUIRED_SOURCES).toEqual(['vimeo', 'youtube-nocookie'])
	})
})

describe('videoInterval — empty subtitle track', () => {
	it('is a valid non-empty WebVTT data URI', () => {
		expect(EMPTY_VTT_DATA_URI.startsWith('data:text/vtt')).toBe(true)
		expect(decodeURIComponent(EMPTY_VTT_DATA_URI)).toContain('WEBVTT')
	})
})
