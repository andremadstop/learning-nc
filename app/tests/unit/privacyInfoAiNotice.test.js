import { describe, expect, it } from 'vitest'
import consent from '../../data/ai-consent.json'
import privacyData from '../../data/privacy-info.json'
import fs from 'node:fs'
import path from 'node:path'
import { buildAiNotice, buildPrivacyCategoryGroups } from '../../src/utils/privacyInfo.js'

// D6 (5.7.0): the privacy page says what the consent dialog says. Review finding (Codex): the AI
// notice was built from the German-only privacy-info.json, so ar/uk users read the recipient and
// data disclosure in German. The notice now comes from the six-language consent catalog
// (ai-consent.json, guarded by check-consent-locales.py) in the reader's language.

const LANGS = ['de', 'en', 'fr', 'ru', 'ar', 'uk']

function listItems(html) {
	return [...html.matchAll(/<li>(.*?)<\/li>/g)].map((m) => m[1].replace(/<[^>]+>/g, ''))
}

function stripTags(html) {
	return html.replace(/<[^>]+>/g, '').trim()
}

describe('buildAiNotice uses the consent text of the reader\'s language', () => {
	for (const lang of LANGS) {
		it(`${lang}: every paragraph is the consent text of ${lang} with the recipient filled in`, () => {
			const l = consent.locales[lang]
			const view = buildAiNotice(privacyData, { active: true, provider: 'Google Gemini', region: 'us' }, lang)
			expect(view.active).toBe(true)
			expect(view.lang).toBe(lang)
			expect(view.dir).toBe(lang === 'ar' ? 'rtl' : 'ltr')
			expect(view.intro).toBe(stripTags(l.intro_html))
			expect(view.providerSentence).toBe(l.provider_sentence.replace('{provider}', 'Google Gemini'))
			expect(view.dataHeading).toBe(l.data_list_heading)
			expect(view.dataList).toEqual(listItems(l.data_list_html))
			expect(view.identity).toBe(l.identity_note)
			expect(view.region).toBe(l.region_paragraphs.us.replace('{provider}', 'Google Gemini'))
			expect(view.automation).toBe(l.automation_paragraph.replace('{provider}', 'Google Gemini'))
			expect(view.retention).toBe(l.retention_note)
			expect(view.optOut).toBe(l.revocation_note)
			expect(JSON.stringify(view)).not.toMatch(/\{provider\}|<[a-z]/)
		})

		it(`${lang}: switched-off AI says so in ${lang}`, () => {
			const view = buildAiNotice(privacyData, { active: false }, lang)
			expect(view.active).toBe(false)
			expect(view.off).toBe(consent.locales[lang].notice_off)
			expect(view.lang).toBe(lang)
		})
	}

	it('the ar notice is not German', () => {
		const view = buildAiNotice(privacyData, { active: true, provider: 'X', region: 'eu' }, 'ar')
		expect(JSON.stringify(view)).not.toMatch(/werden|Daten|Lernprofil/)
	})

	it('a locale like uk_UA or pt-BR is normalised; an unknown language falls back to English', () => {
		expect(buildAiNotice(privacyData, { active: false }, 'uk_UA').lang).toBe('uk')
		expect(buildAiNotice(privacyData, { active: false }, 'pt-BR').lang).toBe('en')
	})

	it('a local server gets the local paragraph, not the US one', () => {
		const view = buildAiNotice(privacyData, { active: true, provider: 'Ollama (Server dieser Lernplattform)', region: 'local' }, 'de')
		expect(view.region).toMatch(/eigenen Server/)
		expect(view.region).not.toMatch(/USA/)
		expect(JSON.stringify(view)).not.toMatch(/Google/)
	})

	it('an unknown region falls back to the cautious paragraph', () => {
		const view = buildAiNotice(privacyData, { active: true, provider: 'X', region: 'zz' }, 'de')
		expect(view.region).toBe(consent.locales.de.region_paragraphs.unknown.replace('{provider}', 'X'))
	})

	it('shows nothing until the recipient is known', () => {
		expect(buildAiNotice(privacyData, null, 'de')).toBeNull()
		expect(buildAiNotice(privacyData, undefined, 'de')).toBeNull()
		expect(buildAiNotice(privacyData, { active: true, provider: '', region: 'us' }, 'de')).toBeNull()
	})

	it('the notice heading is translated, not left in English', () => {
		const read = (lang) => JSON.parse(fs.readFileSync(path.resolve(process.cwd(), `l10n/${lang}.json`), 'utf8'))
		for (const catalog of ['fr', 'ru', 'ar', 'uk'].map(read)) {
			expect(catalog.translations['KI-Hinweis']).toBeTruthy()
			expect(catalog.translations['KI-Hinweis']).not.toBe('AI notice')
		}
	})
})

describe('privacy-info.json keeps no second copy of the AI notice', () => {
	it('has no German-only notice paragraphs any more', () => {
		const n = privacyData.ai_notice || {}
		for (const key of ['intro', 'data_list', 'identity_note', 'region', 'automation', 'retention', 'opt_out', 'off']) {
			expect(n[key]).toBeUndefined()
		}
	})

	it('names no fixed provider anywhere in the file', () => {
		const raw = JSON.stringify(privacyData)
		expect(raw).not.toMatch(/Google|Gemini|OpenAI|Anthropic|Mistral|Ollama/)
		expect(raw).toContain('{provider}')
	})

	it('no longer claims the profile stays home or the chat stays in the browser', () => {
		const raw = JSON.stringify(privacyData)
		expect(raw).not.toMatch(/NICHT an Google/)
		expect(raw).not.toMatch(/Lernprofil werden NICHT/)
		expect(raw).not.toMatch(/lokal im Browser/)
		expect(raw).not.toMatch(/Virtueller Assistent/)
	})
})

describe('category texts carry the recipient too', () => {
	it('fills {provider} in the AI category and keeps a fallback without one', () => {
		const withProvider = buildPrivacyCategoryGroups(privacyData, 'Google Gemini')
		const ai = withProvider.find((g) => g.id === 'ai').entries.find((e) => e.name.startsWith('KI-Interaktion'))
		expect(ai.who_sees).toContain('Google Gemini')
		expect(ai.who_sees).not.toContain('{provider}')
		expect(ai.items).toMatch(/Lernprofil/)
		expect(ai.retention).toMatch(/Server/)

		const without = buildPrivacyCategoryGroups(privacyData)
		const ai2 = without.find((g) => g.id === 'ai').entries.find((e) => e.name.startsWith('KI-Interaktion'))
		expect(ai2.who_sees).not.toContain('{provider}')
		expect(ai2.who_sees).toContain(privacyData.ai_notice.provider_fallback)
	})
})
