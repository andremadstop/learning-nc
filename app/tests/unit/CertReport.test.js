import { describe, it, expect } from 'vitest'
import {
	buildCertReportQuery,
	shouldShowCertReport,
	formatScore,
	formatDate,
} from '../../src/utils/cert-report.js'

describe('buildCertReportQuery', () => {
	it('returns an empty string when no filters are set', () => {
		expect(buildCertReportQuery({})).toBe('')
	})

	it('omits null / undefined / empty-string params (no stray from=&to=)', () => {
		expect(buildCertReportQuery({ from: null, to: undefined, expiringDays: '' })).toBe('')
	})

	it('emits only the set param', () => {
		expect(buildCertReportQuery({ expiringDays: 30 })).toBe('expiringDays=30')
	})

	it('passes numeric from/to through in a stable order', () => {
		expect(buildCertReportQuery({ from: 1000, to: 2000 })).toBe('from=1000&to=2000')
	})

	it('combines all three in a stable order', () => {
		expect(buildCertReportQuery({ from: 1000, to: 2000, expiringDays: 30 }))
			.toBe('from=1000&to=2000&expiringDays=30')
	})

	it('treats 0 as a real value (not omitted)', () => {
		expect(buildCertReportQuery({ expiringDays: 0 })).toBe('expiringDays=0')
	})
})

describe('shouldShowCertReport', () => {
	it('is false for a student on a certifying course', () => {
		expect(shouldShowCertReport(false, { cert_enabled: true })).toBe(false)
	})

	it('is false for an instructor on a non-certifying course', () => {
		expect(shouldShowCertReport(true, { cert_enabled: false })).toBe(false)
	})

	it('is true for an instructor on a certifying course', () => {
		expect(shouldShowCertReport(true, { cert_enabled: true })).toBe(true)
	})

	it('is false for an instructor when the course is undefined', () => {
		expect(shouldShowCertReport(true, undefined)).toBe(false)
	})

	it('is false for an instructor when the course is null', () => {
		expect(shouldShowCertReport(true, null)).toBe(false)
	})
})

describe('formatScore', () => {
	it('renders an em-dash for the empty score', () => {
		expect(formatScore('')).toBe('—')
	})

	it('passes a real score through', () => {
		expect(formatScore('95')).toBe('95')
	})
})

describe('formatDate', () => {
	it('returns an empty string for a falsy unix value', () => {
		expect(formatDate(null)).toBe('')
		expect(formatDate(0)).toBe('')
		expect(formatDate(undefined)).toBe('')
	})

	it('formats a unix timestamp as YYYY-MM-DD', () => {
		// 2026-06-27T00:00:00Z = 1782518400
		expect(formatDate(1782518400)).toBe('2026-06-27')
	})
})
