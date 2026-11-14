import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// GitHub #40: the course inbox was renamed and the announcement list moved into the feed, which
// left three keys no source file reads any more. The i18n gate checks source -> catalogue only,
// so dead keys stay unnoticed and keep being translated.
const DEAD = ['Anfragen', 'No announcements yet.', 'No open requests for this course.']

const catalog = (lang, ext) => {
	const raw = readFileSync(resolve(__dirname, `../../l10n/${lang}.${ext}`), 'utf8')
	if (ext === 'json') return JSON.parse(raw).translations
	const body = raw.slice(raw.indexOf('{'), raw.lastIndexOf('},') + 1)
	return JSON.parse(body.replace(/,\s*}$/, '}'))
}

describe('keys orphaned by #40 are gone from the catalogues', () => {
	for (const ext of ['json', 'js']) {
		for (const lang of ['de', 'en', 'fr', 'ru', 'ar', 'uk']) {
			it(`${lang}.${ext}`, () => {
				const tr = catalog(lang, ext)
				for (const key of DEAD) {
					expect(Object.hasOwn(tr, key), `${lang}.${ext} ${key}`).toBe(false)
				}
			})
		}
	}

	it('"Anfragen" survives as the German value of "Requests"', () => {
		expect(catalog('de', 'json').Requests).toBe('Anfragen')
	})
})
