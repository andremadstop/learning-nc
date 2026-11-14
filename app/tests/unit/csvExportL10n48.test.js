/**
 * GitHub #48: the course CSV exports are written in the exporter's language through
 * lib/Service/CsvExportL10n.php. Each of its keys must be a real translation; an English copy
 * (fr "Swarm Contributions") would put English back into a French export.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const source = readFileSync(resolve(__dirname, '../../lib/Service/CsvExportL10n.php'), 'utf8')
const keys = [...new Set([...source.matchAll(/->t\('((?:[^'\\]|\\.)*)'/g)].map((m) => m[1]))]
const catalog = (lang) => JSON.parse(readFileSync(resolve(__dirname, `../../l10n/${lang}.json`), 'utf8')).translations
const en = catalog('en')
// the same word in English and the target language
const SAME = { fr: ['XP', 'Sessions', 'Abzeichen'], ru: ['XP'], ar: ['XP'], uk: ['XP'] }

describe('CSV export labels (GitHub #48)', () => {
	it('reads the keys of the export', () => {
		expect(keys).toContain('Bestanden am')
		expect(keys).toContain('Schwarm-Beiträge')
	})

	for (const lang of ['fr', 'ru', 'ar', 'uk']) {
		it(`are translated in ${lang}`, () => {
			const c = catalog(lang)
			expect(keys.filter((k) => !(k in c))).toEqual([])
			expect(keys.filter((k) => !SAME[lang].includes(k) && c[k] === en[k])).toEqual([])
		})
	}
})
