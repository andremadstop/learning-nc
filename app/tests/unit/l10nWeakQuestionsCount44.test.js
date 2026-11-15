import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const catalog = (lang, ext) => {
	const raw = readFileSync(resolve(__dirname, `../../l10n/${lang}.${ext}`), 'utf8')
	if (ext === 'json') return JSON.parse(raw).translations
	const body = raw.slice(raw.indexOf('{'), raw.lastIndexOf('},') + 1)
	return JSON.parse(body.replace(/,\s*}$/, '}'))
}

const WEAK = 'Noch keine schwachen Fragen. Eine Frage erscheint hier ab {n} Antworten.'
const WRONG = 'Noch keine falsch beantwortete Frage mit mindestens {n} Antworten.'

// n is MIN_ANSWERS_PER_QUESTION = 2: "ответят 2 раз" is wrong Russian, "2 إجابات" is not the
// Arabic dual. The sentences are worded so the number needs no agreement.
describe('the weak-question empty states read right for n = 2 (review of GitHub #44)', () => {
	for (const ext of ['json', 'js']) {
		it(`ru.${ext}`, () => {
			expect(catalog('ru', ext)[WEAK]).not.toMatch(/\{n\} раз\./)
		})
		it(`ar.${ext}`, () => {
			const ar = catalog('ar', ext)
			expect(ar[WEAK]).not.toContain('{n} إجابات')
			expect(ar[WRONG]).not.toContain('{n} إجابات')
		})
	}
})
