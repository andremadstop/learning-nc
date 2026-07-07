import { describe, it, expect } from 'vitest'
import { formatTelosSummaryValue, formatTelosHours } from '../../src/utils/virtuprof-telos-format.js'

// Charakterisierungs-Tests: fixieren das Verhalten aus VirtuProf.vue. Zero-Behavior-Change.
const vt = (key) => key

describe('formatTelosSummaryValue', () => {
	it('String: getrimmter Wert oder Fallback', () => {
		expect(formatTelosSummaryValue('hello', 'fb')).toBe('hello')
		expect(formatTelosSummaryValue('  ', 'fb')).toBe('fb')
		expect(formatTelosSummaryValue(null, 'fb')).toBe('fb')
	})
	it('Array: nichtleere Einträge kommagetrennt, sonst Fallback', () => {
		expect(formatTelosSummaryValue(['a', 'b'], 'fb')).toBe('a, b')
		expect(formatTelosSummaryValue([' ', 'x'], 'fb')).toBe('x')
		expect(formatTelosSummaryValue([], 'fb')).toBe('fb')
	})
})

describe('formatTelosHours', () => {
	it('nicht-positiv/NaN → "Flexible" (via vt)', () => {
		expect(formatTelosHours(0, vt)).toBe('Flexible')
		expect(formatTelosHours(-3, vt)).toBe('Flexible')
		expect(formatTelosHours('abc', vt)).toBe('Flexible')
	})
	it('ganze Zahl ohne Nachkomma', () => {
		expect(formatTelosHours(5, vt)).toBe('5h/Woche')
		expect(formatTelosHours(3.0, vt)).toBe('3h/Woche')
	})
	it('Dezimalwert mit einer Nachkommastelle', () => {
		expect(formatTelosHours(2.5, vt)).toBe('2.5h/Woche')
	})
})
