/**
 * Audit #177/#183: the feeds' relative times were German literals ('gerade eben', 'vor 3 Stunden')
 * and dates a fixed TT.MM.JJJJ; CourseMaterials/CourseKnowledgeImport pinned 'de-DE'.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'uk',
	getCanonicalLocale: () => 'en-US',
}))

import { formatLocalDate, formatRelativeTime, intlLocale } from '../../src/utils/relativeTime.js'

const NOW = Date.UTC(2026, 10, 14, 12, 0, 0)
const ago = (seconds) => Math.floor(NOW / 1000) - seconds

describe('formatRelativeTime', () => {
	it('uses the Nextcloud language by default', () => {
		expect(formatRelativeTime(ago(3 * 3600), { now: NOW })).toBe('3 години тому')
	})

	it('has the plural forms of every app language', () => {
		expect(formatRelativeTime(ago(60), { locale: 'en', now: NOW })).toBe('1 minute ago')
		expect(formatRelativeTime(ago(120), { locale: 'en', now: NOW })).toBe('2 minutes ago')
		expect(formatRelativeTime(ago(5 * 86400), { locale: 'ru', now: NOW })).toBe('5 дней назад')
		expect(formatRelativeTime(ago(2 * 86400), { locale: 'fr', now: NOW })).toBe('avant-hier')
		expect(formatRelativeTime(ago(3 * 3600), { locale: 'ar', now: NOW })).toBe('قبل 3 ساعات')
	})

	it('says "now" for the last minute', () => {
		expect(formatRelativeTime(ago(5), { locale: 'en', now: NOW })).toBe('now')
	})

	it('shows a date after 30 days', () => {
		expect(formatRelativeTime(ago(40 * 86400), { locale: 'de', now: NOW })).toBe('5.10.2026')
	})
})

describe('intlLocale', () => {
	it('accepts Nextcloud tags with underscores and falls back to English for garbage', () => {
		expect(intlLocale('pt_BR')).toBe('pt-BR')
		expect(intlLocale('de')).toBe('de')
		expect(intlLocale('not a locale!')).toBe('en')
		expect(intlLocale('')).toBe('en')
	})
})

describe('formatLocalDate', () => {
	it('uses the Nextcloud locale instead of a fixed de-DE', () => {
		expect(formatLocalDate(Date.UTC(2026, 0, 15, 12) / 1000)).toBe('1/15/2026')
		expect(formatLocalDate(Date.UTC(2026, 0, 15, 12) / 1000, 'de-DE')).toBe('15.1.2026')
	})
})
