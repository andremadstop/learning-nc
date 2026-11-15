import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// Pre-existing catalogue gap, visible since the review of #36: the VirtuProf context panel
// (VirtuProfFullscreen.vue areaLabel() and contextEntries) names the area and the question with
// t(). In fr/ru/ar some of those values were still the English text, so the panel showed
// "Settings", "My Progress", "Question" and "No additional context active" there.
const catalog = (lang, ext) => {
	const raw = readFileSync(resolve(__dirname, `../../l10n/${lang}.${ext}`), 'utf8')
	if (ext === 'json') return JSON.parse(raw).translations
	const body = raw.slice(raw.indexOf('{'), raw.lastIndexOf('},') + 1)
	return JSON.parse(body.replace(/,\s*}$/, '}'))
}

const expected = {
	fr: {
		Einstellungen: 'Paramètres',
		'Mein Fortschritt': 'Ma progression',
		'Kein zusätzlicher Kontext aktiv': 'Aucun contexte supplémentaire actif',
	},
	ru: { Einstellungen: 'Настройки', Frage: 'Вопрос' },
	ar: { Einstellungen: 'الإعدادات', Frage: 'سؤال' },
}

describe('VirtuProf context panel labels are translated (GitHub #36 review)', () => {
	for (const [lang, entries] of Object.entries(expected)) {
		for (const ext of ['json', 'js']) {
			it(`${lang}.${ext}`, () => {
				const cat = catalog(lang, ext)
				for (const [key, value] of Object.entries(entries)) {
					expect(cat[key], key).toBe(value)
				}
			})
		}
	}
})
