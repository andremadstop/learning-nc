import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// Pre-existing: the French title of the pool-rules dialog was still the English "Pool Rules",
// while the French hints that point to it say « règles du pool ».
const catalog = (lang, ext) => {
	const raw = readFileSync(resolve(__dirname, `../../l10n/${lang}.${ext}`), 'utf8')
	if (ext === 'json') return JSON.parse(raw).translations
	const body = raw.slice(raw.indexOf('{'), raw.lastIndexOf('},') + 1)
	return JSON.parse(body.replace(/,\s*}$/, '}'))
}

describe('"Pool Rules" is French in fr', () => {
	for (const ext of ['json', 'js']) {
		it(`fr.${ext}`, () => {
			expect(catalog('fr', ext)['Pool Rules']).toBe('Règles du pool')
		})
	}
})
