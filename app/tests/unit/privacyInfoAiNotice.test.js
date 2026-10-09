import { describe, expect, it } from 'vitest'
import consent from '../../data/ai-consent.json'
import privacyData from '../../data/privacy-info.json'
import { buildAiNotice, buildPrivacyCategoryGroups } from '../../src/utils/privacyInfo.js'

// D6 (5.7.0): the privacy page says what the consent dialog says. The consent text is the single
// source of truth; privacy-info.json must not drift from it, must not name a fixed provider and
// must not keep the claims that stopped being true (profile never sent, chat only in the browser).

const de = consent.locales.de

function listItems(html) {
	return [...html.matchAll(/<li>(.*?)<\/li>/g)].map((m) => m[1])
}

describe('privacy-info.json ai_notice follows the consent text', () => {
	it('lists exactly the data the consent dialog lists', () => {
		expect(privacyData.ai_notice.data_list).toEqual(listItems(de.data_list_html))
		expect(privacyData.ai_notice.data_list_heading).toBe(de.data_list_heading)
	})

	it('uses the consent paragraphs word for word', () => {
		const n = privacyData.ai_notice
		expect(n.identity_note).toBe(de.identity_note)
		expect(n.automation).toBe(de.automation_paragraph)
		expect(n.retention).toBe(de.retention_note)
		expect(n.opt_out).toBe(de.revocation_note)
		for (const region of ['us', 'eu', 'unknown', 'local']) {
			expect(n.region[region]).toBe(de.region_paragraphs[region])
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

describe('buildAiNotice', () => {
	it('puts the recipient into every paragraph and picks the region text', () => {
		const view = buildAiNotice(privacyData, { active: true, provider: 'Google Gemini', region: 'us' })
		expect(view.active).toBe(true)
		expect(view.intro).toContain('Google Gemini')
		expect(view.region).toContain('Google Gemini')
		expect(view.region).toContain('USA')
		expect(view.automation).toContain('Google Gemini')
		expect(JSON.stringify(view)).not.toMatch(/\{provider\}/)
		expect(view.dataList).toHaveLength(7)
	})

	it('a local server gets the local paragraph, not the US one', () => {
		const view = buildAiNotice(privacyData, { active: true, provider: 'Ollama (Server dieser Lernplattform)', region: 'local' })
		expect(view.region).toMatch(/eigenen Server/)
		expect(view.region).not.toMatch(/USA/)
		expect(JSON.stringify(view)).not.toMatch(/Google/)
	})

	it('an unknown region falls back to the cautious paragraph', () => {
		const view = buildAiNotice(privacyData, { active: true, provider: 'X', region: 'zz' })
		expect(view.region).toBe(privacyData.ai_notice.region.unknown.replace('{provider}', 'X'))
	})

	it('switched-off AI says so and names nobody', () => {
		const view = buildAiNotice(privacyData, { active: false })
		expect(view.active).toBe(false)
		expect(view.off).toMatch(/ausgeschaltet/)
		expect(view.intro).toBeUndefined()
	})

	it('shows nothing until the recipient is known', () => {
		expect(buildAiNotice(privacyData, null)).toBeNull()
		expect(buildAiNotice(privacyData, undefined)).toBeNull()
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
