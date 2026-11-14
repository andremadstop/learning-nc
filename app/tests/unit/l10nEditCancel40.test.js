import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// GitHub #40: the fr, ru and ar catalogues held the English words "Edit" and "Cancel" as the
// values of "Bearbeiten" and "Abbrechen", so those buttons stayed English. The announcement edit
// dialog reuses both strings.
const catalog = (lang, ext) => {
	const raw = readFileSync(resolve(__dirname, `../../l10n/${lang}.${ext}`), 'utf8')
	if (ext === 'json') return JSON.parse(raw).translations
	const body = raw.slice(raw.indexOf('{'), raw.lastIndexOf('},') + 1)
	return JSON.parse(body.replace(/,\s*}$/, '}'))
}

describe('"Bearbeiten" and "Abbrechen" are translated (GitHub #40)', () => {
	for (const ext of ['json', 'js']) {
		const en = catalog('en', ext)
		for (const lang of ['fr', 'ru', 'ar', 'uk']) {
			it(`${lang}.${ext}`, () => {
				const tr = catalog(lang, ext)
				for (const key of ['Bearbeiten', 'Abbrechen']) {
					expect(tr[key], `${lang} ${key}`).toBeTruthy()
					expect(tr[key], `${lang} ${key}`).not.toBe(en[key])
				}
			})
		}
	}
})
