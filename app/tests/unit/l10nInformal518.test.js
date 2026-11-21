import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// 5.18.0: the new messages of the release address the user informally in French, Russian and
// Ukrainian (tu/ты/ти), like the certificate library conflict messages. Three of them still used
// the formal imperative. Checks the .json catalogues and the .js ones the frontend reads.
const catalog = (lang, ext) => {
	const raw = readFileSync(resolve(__dirname, `../../l10n/${lang}.${ext}`), 'utf8')
	if (ext === 'json') return JSON.parse(raw).translations
	const body = raw.slice(raw.indexOf('{'), raw.lastIndexOf('},') + 1)
	return JSON.parse(body.replace(/,\s*}$/, '}'))
}

const KEYS = [
	'The pool fields of the file (%s) were not applied: this import adds the questions to the open pool and leaves its name and settings as they are. To create a pool from the file, use "Import pools" in the Pools tab.',
	'Line {line}: Ambiguous numbers — read as correct answer {number} without explanation; if the last number is an explanation, add a header row with a "correct" column',
	'Line %1$s: Ambiguous numbers — read as correct answer %2$s without explanation; if the last number is an explanation, add a header row with a "correct" column',
]

const FORMAL = {
	fr: /\b(?:vous|votre|vos|utilisez|ajoutez)\b/i,
	ru: /(?:^|[^\p{L}])(?:вы|вас|вам|ваш\p{L}*|используйте|добавьте)(?![\p{L}])/iu,
	uk: /(?:^|[^\p{L}])(?:ви|вас|вам|ваш\p{L}*|скористайтеся|додайте)(?![\p{L}])/iu,
}

describe('the new 5.18.0 messages say tu/ты/ти', () => {
	for (const lang of Object.keys(FORMAL)) {
		for (const ext of ['json', 'js']) {
			it(`${lang}.${ext}`, () => {
				const t = catalog(lang, ext)
				for (const key of KEYS) {
					expect(t[key], key).toBeTypeOf('string')
					expect(t[key], key).not.toMatch(FORMAL[lang])
				}
			})
		}
	}
})
