import { describe, expect, it } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import consent from '../../data/ai-consent.json'
import privacyData from '../../data/privacy-info.json'

// Review finding (Codex): AiChatMemoryService::saveExchange() stores every user message (up to
// 1000 chars) and every reply verbatim, loadMemory() returns the recent ones and the prompt carries
// them again. Only entries beyond the cap become a summary. The consent text and the privacy texts
// said "a summary of your earlier chats" — the legal basis has to name the stored messages.

const LANGS = ['de', 'en', 'fr', 'ru', 'ar', 'uk']
const STORED_MESSAGES = {
	de: /Nachrichten.*Antworten.*gespeichert.*mitgeschickt/,
	en: /messages.*replies.*stored.*sent again/,
	fr: /messages.*réponses.*enregistrés.*renvoyés/,
	ru: /сообщения.*ответы.*хранятся.*отправляются/,
	ar: /رسائلك.*ردود.*تُحفَظ.*تُرسَل/,
	uk: /повідомлення.*відповіді.*зберігаються.*надсилаються/,
}

function memoryItem(html) {
	return [...html.matchAll(/<li>(.*?)<\/li>/g)].map((m) => m[1]).find((li) => STORED_MESSAGES.de.test(li))
}

describe('the consent text says the recent chat messages are stored and sent again', () => {
	for (const lang of LANGS) {
		it(lang, () => {
			const html = consent.locales[lang].data_list_html
			expect(html).toMatch(STORED_MESSAGES[lang])
		})
	}

	it('the German legacy body says the same', () => {
		expect(consent.body_html).toMatch(STORED_MESSAGES.de)
		expect(consent.body_html).not.toMatch(/eine Zusammenfassung deiner früheren Chats mit VirtuProf, die auf dem Server gespeichert ist/)
	})

	it('no locale reduces the stored chat to "a summary" any more', () => {
		const de = consent.locales.de.data_list_html
		expect(de).not.toMatch(/<li>eine Zusammenfassung deiner früheren Chats/)
		expect(memoryItem(de)).toBeTruthy()
	})
})

describe('privacy page and settings say the same', () => {
	it('privacy-info.json AI category names the stored messages', () => {
		const ai = privacyData.categories.find((c) => c.name.startsWith('KI-Interaktion'))
		expect(ai.items).toMatch(/Nachrichten.*Antworten.*gespeichert/)
		expect(ai.retention).toMatch(/Nachrichten/)
		expect(ai.retention).not.toMatch(/^Die Zusammenfassung deiner früheren Chats liegt/)
	})

	it('the settings data table no longer says "a summary on this server"', () => {
		const src = fs.readFileSync(path.resolve(process.cwd(), 'src/components/PersonalSettings.vue'), 'utf8')
		expect(src).not.toContain('Browser (localStorage) und eine Zusammenfassung auf diesem Server')
		expect(src).toContain('Browser (localStorage) und auf diesem Server: deine letzten Nachrichten mit den Antworten, ältere als Zusammenfassung')
	})
})
