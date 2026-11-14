/**
 * GitHub #50 (follow-up): the class register's notes, button and empty state were English
 * copies in French, Russian and Arabic, so "Make visible in course" and its success and error
 * notes read in English there. Every literal of Klassenbuch.vue must be a real translation.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const source = readFileSync(resolve(__dirname, '../../src/components/Klassenbuch.vue'), 'utf8')
const keys = [...new Set([...source.matchAll(/t\('learning',\s*'((?:[^'\\]|\\.)*)'/g)].map((m) => m[1]))]
const catalog = (lang) => JSON.parse(readFileSync(resolve(__dirname, `../../l10n/${lang}.json`), 'utf8')).translations
const en = catalog('en')

describe('class register strings (GitHub #50)', () => {
	it('finds the strings of the component', () => {
		expect(keys).toContain('Im Kurs sichtbar machen')
		expect(keys).toContain('Sichtbarkeit konnte nicht geändert werden.')
	})

	for (const lang of ['fr', 'ru', 'ar', 'uk']) {
		it(`are translated in ${lang}, not English copies`, () => {
			const c = catalog(lang)
			const copies = keys.filter((k) => k !== 'vCard' && c[k] === en[k])
			expect(copies).toEqual([])
		})
	}
})
