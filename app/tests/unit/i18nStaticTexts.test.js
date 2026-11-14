/**
 * Texts that were German (or English) literals outside t(), so no catalogue could translate them.
 * The l10n stand-in answers from the real l10n/<lang>.json, so each assertion only passes when the
 * component asks the catalogue AND the key is there.
 *
 *   #184/#187 Impressum and privacy page headings; the privacy page named an internal repo path
 *   #187      TerminalPuzzle labels, duel-invitation badge on the avatars
 *   #185      the onboarding skip question had ae/ue in German
 *   #190      the translation dialog had no Arabic, although 'ar' is a content language
 *   #197      the first onboarding card asked fr/uk users in German
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebrateBadge: vi.fn() }))

import axios from '@nextcloud/axios'
import { catalogue, setTestLanguage, translate } from './support/l10nCatalog.js'
import ImpressumPage from '../../src/components/ImpressumPage.vue'
import PrivacyPage from '../../src/components/PrivacyPage.vue'
import TerminalPuzzle from '../../src/components/TerminalPuzzle.vue'
import OnbHook from '../../src/components/onboarding/OnbHook.vue'
import TranslationDialog from '../../src/components/TranslationDialog.vue'
import { inviteBadgeLabel } from '../../src/utils/inviteBadge.js'

let host
let app

async function render(Component, props = {}, stubs = {}) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(Component, props) })
	app.config.globalProperties.t = translate
	app.config.warnHandler = () => {}
	for (const [name, stub] of Object.entries(stubs)) app.component(name, stub)
	app.mount(host)
	await nextTick()
	return host
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
	setTestLanguage('de')
})

const operator = { name: 'Muster UG', address: 'Weg 1, 12345 Ort', email: 'info@example.org', register: '', content_responsible: '' }

describe('legal pages (audit #184, #187)', () => {
	it('the imprint headings follow the reader\'s language', async () => {
		setTestLanguage('en')
		const el = await render(ImpressumPage, { appUrl: '/a', privacyUrl: '/p', operator })
		const text = el.textContent
		expect(el.querySelector('h1').textContent.trim()).toBe(catalogue('en').Impressum)
		for (const key of ['Anbieter', 'Kontakt', 'Verantwortlich für Inhalte', 'Zur Datenschutzerklärung', 'Lernplattform']) {
			expect(text).toContain(catalogue('en')[key])
		}
		expect(text).not.toContain('Anbieter')
		expect(text).not.toContain('Kontakt')
	})

	it('the privacy page is translated and no longer names an internal file path', async () => {
		setTestLanguage('fr')
		const el = await render(PrivacyPage, { appUrl: '/a', imprintUrl: '/i', operator }, {})
		expect(el.querySelector('h1').textContent.trim()).toBe(catalogue('fr')['Datenschutzerklärung'])
		expect(el.textContent).toContain(catalogue('fr')['Diese Seite bündelt die Datenschutzinformationen der Learning-App direkt in Nextcloud.'])
		expect(el.textContent).not.toContain('privacy-info.json')
	})
})

describe('TerminalPuzzle labels (audit #187)', () => {
	it('labels the objective and the default texts in the reader\'s language', async () => {
		setTestLanguage('de')
		const el = await render(TerminalPuzzle, { scenario: { valid_commands: ['ls'] } })
		expect(el.querySelector('.terminal-puzzle__objective-label').textContent).toBe('ZIEL:')
		expect(el.textContent).toContain('Schließe die Terminal-Aufgabe ab.')
	})
})

describe('duel invitation badge (audit #187, #196)', () => {
	it('is read out in the reader\'s language', () => {
		setTestLanguage('uk')
		expect(inviteBadgeLabel(3)).toBe('Запрошення на дуель: 3')
		setTestLanguage('en')
		expect(inviteBadgeLabel(1)).toBe('Duel invitations: 1')
	})
})

describe('onboarding (audit #185, #197)', () => {
	it('asks the first card in French and Ukrainian too', () => {
		setTestLanguage('fr')
		expect(OnbHook.computed.question.call({})).toBe('Combien de bits dans un octet ?')
		setTestLanguage('uk')
		expect(OnbHook.computed.question.call({})).toBe('Скільки бітів у байті?')
	})

	it('the German skip question is written with umlauts', () => {
		const key = 'NOVA kann dich ohne Profil nicht optimal unterstuetzen. Trotzdem ueberspringen?'
		expect(catalogue('de')[key]).toBe('NOVA kann dich ohne Profil nicht optimal unterstützen. Trotzdem überspringen?')
		for (const lang of ['fr', 'ru', 'ar']) {
			expect(catalogue(lang)[key], lang).not.toBe(catalogue('en')[key])
		}
	})
})

describe('translation dialog (audit #190)', () => {
	const question = { id: 4, text: 'Was ist DNS?', answers: [{ id: 11, text: 'Namensauflösung' }] }

	function dialog() {
		const data = TranslationDialog.data.call({})
		const instance = { ...data, question }
		for (const [name, fn] of Object.entries(TranslationDialog.methods)) instance[name] = fn.bind(instance)
		return instance
	}

	it('offers Arabic, right to left, next to de/en/ru', () => {
		globalThis.t = translate
		const instance = dialog()
		expect(instance.languages.map((l) => l.key)).toEqual(['de', 'en', 'ru', 'ar'])
		expect(instance.languages.find((l) => l.key === 'ar').dir).toBe('rtl')
		instance.initializeAnswerState()
		expect(instance.answerTranslations[11]).toEqual({ de: '', en: '', ru: '', ar: '' })
	})

	it('loads stored Arabic translations instead of skipping them', async () => {
		globalThis.t = translate
		const instance = dialog()
		instance.initializeAnswerState()
		axios.get.mockImplementation(async (url) => (url.includes('/questions/')
			? { data: [{ lang: 'ar', text: 'ما هو DNS؟', explanation: '', scenario: '' }] }
			: { data: [{ lang: 'ar', text: 'تحليل الأسماء' }] }))

		await instance.loadTranslations()

		expect(instance.questionTranslations.ar.text).toBe('ما هو DNS؟')
		expect(instance.answerTranslations[11].ar).toBe('تحليل الأسماء')
	})
})
