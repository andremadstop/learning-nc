import { describe, it, expect } from 'vitest'
import consent from '../../data/ai-consent.json'

// Task D1 (5.7.0): the consent text is one template per language. The dialog (D2) fills in
// the recipient; the text itself must say truthfully what is sent (spec 3.6).

const LANGS = ['de', 'en', 'fr', 'ru', 'ar', 'uk']

function allStrings(node) {
	if (typeof node === 'string') return [node]
	return Object.values(node).flatMap(allStrings)
}

describe('ai-consent.json', () => {
	it('keeps the version the server compares as a string', () => {
		expect(consent.version).toBe('3.0')
	})

	it('has the text in all six languages', () => {
		expect(Object.keys(consent.locales).sort()).toEqual([...LANGS].sort())
	})

	it('keeps the legacy top-level fields the VirtuProf dialog still reads until D3', () => {
		for (const key of ['title', 'body_html', 'accept_label', 'decline_label']) {
			expect(typeof consent[key]).toBe('string')
			expect(consent[key].trim()).not.toBe('')
		}
	})

	it('legacy body names no fixed provider or country, since the recipient is configurable', () => {
		expect(consent.body_html).not.toMatch(/Gemini|Google|USA/)
		expect(consent.body_html).not.toMatch(/\{\w+\}/)
	})

	it('the German source lists what is really sent (spec 3.6)', () => {
		const de = consent.locales.de
		expect(de.data_list_html).toMatch(/Lernprofil/)
		expect(de.data_list_html).toMatch(/Subnetting/)
		expect(de.data_list_html).toMatch(/Zusammenfassung/)
		expect(de.data_list_html).toMatch(/Kursmaterial/)
		expect(de.identity_note).toMatch(/nicht automatisch/)
		expect(de.automation_paragraph).toMatch(/einmal pro Woche/i)
		expect(de.revocation_note).toMatch(/widerruf/i)
	})

	it('names the recipient only through placeholders in plain-text fields', () => {
		for (const lang of LANGS) {
			const l = consent.locales[lang]
			expect(l.provider_sentence).toContain('{provider}')
			expect(l.reason_headers.recipient_changed).toContain('{previous}')
			expect(l.reason_headers.recipient_changed).toContain('{provider}')
			expect(l.intro_html).not.toMatch(/\{\w+\}/)
			expect(l.data_list_html).not.toMatch(/\{\w+\}/)
		}
	})

	it('mentions no provider that 5.7.0 does not offer', () => {
		const text = allStrings(consent).join('\n')
		expect(text).not.toMatch(/openai|anthropic|openrouter|mistral|ionos|deepseek/i)
	})
})
