/**
 * GitHub #38 (38-d): the "Data categories" section of the privacy page.
 *
 * The card titles were hard-coded German without t() (partly with "oe"/"ae" spellings), and every
 * category text came raw from the German-only privacy-info.json, so a reader in any language saw
 * the Art. 13 information in German. The grouping matched the German names with regexes, so it
 * also had to move to explicit ids before the names could be translated.
 *
 * The l10n stand-in answers from the real l10n/<lang>.json: a text only comes out translated if the
 * page asks the catalogue AND the key is there.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

import { catalogue, setTestLanguage, translate } from './support/l10nCatalog.js'
import PrivacyInfo from '../../src/components/PrivacyInfo.vue'
import { buildPrivacyCategories } from '../../src/utils/privacyInfo.js'

let host
let app

async function render(lang, props = {}) {
	setTestLanguage(lang)
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(PrivacyInfo, { locale: lang, ...props }) })
	app.config.globalProperties.t = translate
	app.config.warnHandler = () => {}
	app.mount(host)
	await nextTick()
	return host.querySelector('.privacy-info__category-grid')
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
	setTestLanguage('de')
})

const GROUP_TITLES = [
	'Lerndaten',
	'Persönlichkeitsprofil / Telos & KI-Interaktionen',
	'Schwarmgedächtnis',
	'Audit & Moderation',
	'Gamification',
	'Prüfungsdaten',
	'Externe Dienste',
]

/** Every German source text of the categories, as the page asks the catalogue for it. */
function categoryKeys() {
	setTestLanguage('de')
	const keys = new Set()
	for (const c of buildPrivacyCategories()) {
		for (const f of ['name', 'items', 'purpose', 'who_sees', 'retention', 'legal_basis']) {
			keys.add(c[f])
		}
	}
	return [...keys]
}

const GERMAN_MARKERS = ['Lerndaten', 'Solange dein Account existiert', 'Einwilligung', 'Berechtigtes Interesse', 'Kursteilnehmer', 'Dozent', 'verarbeitet die Anfrage', 'Persoenlichkeitsprofil', 'Schwarmgedaechtnis']

describe('privacy page: data categories (#38)', () => {
	it('shows the cards and their texts in English, not in German', async () => {
		const grid = await render('en')
		const titles = [...grid.querySelectorAll('h5')].map((el) => el.textContent.trim())
		expect(titles).toEqual(GROUP_TITLES.map((key) => catalogue('en')[key]))
		const text = grid.textContent
		expect(text).toContain('As long as your account exists')
		expect(text).toContain('Legitimate interest (Art. 6(1)(f) GDPR)')
		for (const marker of GERMAN_MARKERS) {
			expect(text, marker).not.toContain(marker)
		}
	})

	it.each(['fr', 'ru', 'ar', 'uk'])('every category text has its own translation in %s', async (lang) => {
		const cat = catalogue(lang)
		const en = catalogue('en')
		for (const key of [...categoryKeys(), ...GROUP_TITLES]) {
			expect(cat[key], `${lang}: missing ${key}`).toBeTruthy()
			// short names may coincide with English or German (fr "Gamification" is "Ludification", but
			// "Vimeo / YouTube" stays); the sentences may not
			if (key.length > 40) {
				expect(cat[key], `${lang}: ${key} copies English`).not.toBe(en[key])
				expect(cat[key], `${lang}: ${key} is German`).not.toBe(key)
			}
		}
		const text = (await render(lang)).textContent
		for (const marker of GERMAN_MARKERS) {
			expect(text, `${lang}: ${marker}`).not.toContain(marker)
		}
	})

	it('the grouping survives the translation: 7 cards, the ai card holds Telos and the AI interaction (ar)', async () => {
		const grid = await render('ar')
		const cards = [...grid.querySelectorAll('.privacy-info__category-card')]
		expect(cards).toHaveLength(7)
		const ai = cards[1]
		expect(ai.querySelector('h5').textContent.trim()).toBe(catalogue('ar')['Persönlichkeitsprofil / Telos & KI-Interaktionen'])
		expect(ai.querySelectorAll('.privacy-info__entry')).toHaveLength(2)
		expect(cards[6].querySelectorAll('.privacy-info__entry')).toHaveLength(2)
	})

	it('the German card titles are spelled with umlauts', async () => {
		const grid = await render('de')
		const titles = [...grid.querySelectorAll('h5')].map((el) => el.textContent.trim())
		expect(titles).toEqual(GROUP_TITLES)
		expect(grid.textContent).not.toMatch(/Persoenlich|gedaechtnis/)
	})

	it('the recipient goes into the translated AI text; without one a whole sentence names the operator\'s service', async () => {
		let grid = await render('en', { aiNotice: { active: true, provider: 'Mistral AI', region: 'eu' } })
		expect(grid.textContent).toContain('Mistral AI processes the request.')
		expect(grid.textContent).not.toContain('{provider}')
		app.unmount()
		host.remove()
		grid = await render('uk')
		expect(grid.textContent).toContain(catalogue('uk')['Der vom Betreiber eingerichtete KI-Dienst verarbeitet die Anfrage. Name, E-Mail und Kontodaten werden nicht automatisch mitgeschickt; was du selbst in eine Nachricht schreibst, geht an den KI-Dienst.'])
	})

	it('the gamification texts hold with gamification on and off', () => {
		setTestLanguage('de')
		const byId = Object.fromEntries(buildPrivacyCategories().map((c) => [c.id, c]))
		expect(byId.gamification.who_sees).toMatch(/^Sofern Gamification aktiviert ist: /)
		expect(byId.gamification.who_sees).toContain('Ist Gamification ausgeschaltet, zeigt die Lernoberfläche diese Werte niemandem an; in deinem Datenexport sind sie weiterhin enthalten.')
		expect(byId['learning-data'].who_sees).toContain('Sofern Gamification aktiviert ist, sind XP und Badges im Leaderboard')
		expect(byId['learning-data'].who_sees).not.toMatch(/XP\/Badges im Leaderboard für alle\.$/)
	})
})
