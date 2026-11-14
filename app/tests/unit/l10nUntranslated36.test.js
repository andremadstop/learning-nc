/**
 * Review of the #36/#40 branch: keys on lines and flows the branch rewrote still showed the
 * English (or German) text in other languages. The four i18n gates do not catch a value that
 * equals the English text or the key. These are the keys found; each must be translated.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const load = (lang) => JSON.parse(readFileSync(resolve(__dirname, `../../l10n/${lang}.json`), 'utf8')).translations
const en = load('en')

const GUIDE = 'Dieser Bereich fasst deinen Kursfortschritt in eine Übersicht mit Meisterschaft, Streaks, Badges und offenen Fragen zusammen.'
const CASES = {
	fr: ['New announcement', 'Your answer...', 'Send answer', 'Mastered', 'Last active: {date}', 'Fortschritt', GUIDE],
	ru: ['Lernraum', 'Fortschritt', GUIDE],
	ar: ['Fortschritt', GUIDE],
}

describe('keys of the reworked views are translated (GitHub #36/#40)', () => {
	for (const [lang, keys] of Object.entries(CASES)) {
		const catalogue = load(lang)
		it.each(keys)(`${lang}: %s`, (key) => {
			expect(catalogue[key], 'present').toBeTruthy()
			expect(catalogue[key], 'not the English text').not.toBe(en[key])
			expect(catalogue[key], 'not the German key').not.toBe(key)
		})
	}
})
