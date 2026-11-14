/**
 * GitHub #38: the "Your rights" list on the privacy page.
 *
 *   38-a  The six rights came raw from the German-only privacy-info.json and were rendered without
 *         t(), so an English (or Arabic, Ukrainian ...) reader saw "Auskunft (Art. 15 DSGVO)".
 *   38-f  Withdrawal of consent (Art. 7(3)) and the complaint to a supervisory authority (Art. 77)
 *         were missing, although Art. 13(2)(c)/(d) requires both.
 *   38-b  The compiler drops the line break between </strong> and <span>, so the bold name and the
 *         bracket ran together: "Auskunft(Art. 15 DSGVO)".
 *
 * The l10n stand-in answers from the real l10n/<lang>.json, so a right only comes out translated if
 * the page asks the catalogue AND the key is there.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

import { catalogue, setTestLanguage, translate } from './support/l10nCatalog.js'
import PrivacyInfo from '../../src/components/PrivacyInfo.vue'

let host
let app

async function render(lang) {
	setTestLanguage(lang)
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(PrivacyInfo, { locale: lang }) })
	app.config.globalProperties.t = translate
	app.config.warnHandler = () => {}
	app.mount(host)
	await nextTick()
	return [...host.querySelectorAll('.privacy-info__rights li')]
}

async function rerender(lang) {
	app?.unmount()
	host?.remove()
	return render(lang)
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
	setTestLanguage('de')
})

const RIGHT_KEYS = ['Auskunft', 'Berichtigung', 'Löschung', 'Einschränkung', 'Widerspruch', 'Datenportabilität', 'Widerruf der Einwilligung', 'Beschwerde bei einer Aufsichtsbehörde']
const RIGHT_DESCRIPTIONS = [
	'Du kannst jederzeit erfahren, welche Daten über dich gespeichert sind.',
	'Unrichtige Daten können korrigiert werden.',
	'Du kannst die Löschung deiner Daten verlangen.',
	'Du kannst die Verarbeitung einschränken lassen.',
	'Du kannst der Verarbeitung widersprechen.',
	'Du kannst deine Daten in einem gängigen Format erhalten.',
	'Eine erteilte Einwilligung (etwa für die KI-Funktionen, das Telos-Profil, das Schwarmgedächtnis oder externe Videos) kannst du jederzeit mit Wirkung für die Zukunft widerrufen. Die Verarbeitung bis zum Widerruf bleibt rechtmäßig.',
	'Du kannst dich bei einer Datenschutz-Aufsichtsbehörde beschweren, insbesondere in dem EU-Mitgliedstaat deines Aufenthaltsorts, deines Arbeitsplatzes oder des mutmaßlichen Verstoßes.',
]

describe('privacy page: your rights (#38)', () => {
	it('lists the eight rights in English, not in German', async () => {
		const items = await render('en')
		const text = items.map((li) => li.textContent).join('\n')
		expect(items).toHaveLength(8)
		expect(text).toContain('Access')
		expect(text).toContain('Art. 15 GDPR')
		expect(text).toContain('You can find out at any time which data is stored about you.')
		expect(text).not.toContain('Auskunft')
		expect(text).not.toContain('DSGVO')
	})

	it('names withdrawal of consent (Art. 7(3)) and the complaint right (Art. 77) (38-f)', async () => {
		const items = await render('en')
		const text = items.map((li) => li.textContent.replace(/\s+/g, ' ').trim())
		expect(text[6]).toMatch(/^Withdrawal of consent \(Art\. 7\(3\) GDPR\) — .*at any time with effect for the future\. Processing carried out before the withdrawal remains lawful\.$/)
		expect(text[7]).toMatch(/^Complaint to a supervisory authority \(Art\. 77 GDPR\) — .*supervisory authority/)
		const de = (await rerender('de')).map((li) => li.textContent.replace(/\s+/g, ' ').trim())
		expect(de[6]).toMatch(/^Widerruf der Einwilligung \(Art\. 7 Abs\. 3 DSGVO\) — .*Die Verarbeitung bis zum Widerruf bleibt rechtmäßig\.$/)
		expect(de[7]).toMatch(/^Beschwerde bei einer Aufsichtsbehörde \(Art\. 77 DSGVO\)/)
	})

	it.each(['fr', 'ru', 'ar', 'uk'])('every right has its own translation in %s', async (lang) => {
		const items = await render(lang)
		const names = items.map((li) => li.querySelector('strong').textContent.trim())
		const cat = catalogue(lang)
		expect(names).toEqual(RIGHT_KEYS.map((key) => cat[key]))
		for (const key of RIGHT_KEYS) {
			expect(cat[key], `${lang}: ${key}`).not.toBe(key)
		}
		// names can coincide with English (fr "Rectification"); the sentences may not
		for (const key of RIGHT_DESCRIPTIONS) {
			expect(cat[key], `${lang}: ${key} copies English`).not.toBe(catalogue('en')[key])
		}
		expect(items.map((li) => li.textContent).join('\n')).not.toContain('DSGVO')
	})

	it('the heading is translated in fr, ru and ar too', () => {
		for (const lang of ['fr', 'ru', 'ar']) {
			expect(catalogue(lang)['Deine Rechte'], lang).not.toBe(catalogue('en')['Deine Rechte'])
		}
	})

	it.each(['de', 'en', 'ar'])('a space separates the bold name from the bracket (%s)', async (lang) => {
		const items = await render(lang)
		for (const li of items) {
			const name = li.querySelector('strong').textContent
			expect(li.textContent.replace(/\s+/g, ' ').trim()).toMatch(new RegExp(`^${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} \\(`))
		}
	})
})
