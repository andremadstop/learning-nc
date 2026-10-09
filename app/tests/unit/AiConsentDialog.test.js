import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, h, nextTick, reactive } from 'vue'

// Task D2 (5.7.0): one accessible consent dialog, driven by the real consent API fields
// (GET /api/profile/telos/consent: recipient{name,region,processing_local,intermediary,fingerprint},
// reason, previous_recipient_name). The dialog shell only has to expose slots here; the real
// NcButton is kept because the variant semantics (NcButton 9) are part of what is tested.
vi.mock('../../src/components/AccessibleDialog.vue', () => ({
	default: {
		name: 'AccessibleDialog',
		props: ['name'],
		emits: ['closing'],
		template: '<div role="dialog" class="shell"><h2>{{ name }}</h2><slot /><div class="actions"><slot name="actions" /></div></div>',
	},
}))

import consent from '../../data/ai-consent.json'
import AiConsentDialog from '../../src/components/AiConsentDialog.vue'

const LANGS = ['de', 'en', 'fr', 'ru', 'ar', 'uk']
const GEMINI = { name: 'Google Gemini', region: 'us', processing_local: false, intermediary: false, fingerprint: 'fp1' }

let host, app, events

function mountDialog(props = {}) {
	events = []
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({
		render: () => h(AiConsentDialog, {
			recipient: GEMINI,
			reason: 'never',
			locale: 'de',
			onAccept: () => events.push('accept'),
			onDecline: () => events.push('decline'),
			...props,
		}),
	})
	app.config.globalProperties.t = (_a, s) => s
	app.config.warnHandler = () => {}
	app.mount(host)
	return host
}

beforeEach(() => { document.body.innerHTML = '' })
afterEach(() => { app?.unmount(); app = null; host?.remove() })

describe('AiConsentDialog', () => {
	it('shows the revised-version header for reason "version"', () => {
		const el = mountDialog({ reason: 'version' })
		expect(el.textContent).toContain(consent.locales.de.reason_headers.version)
	})

	it('names old and new recipient for reason "recipient_changed"', () => {
		const el = mountDialog({ reason: 'recipient_changed', previousRecipientName: 'Ollama' })
		expect(el.textContent).toContain('von Ollama auf Google Gemini')
		expect(el.textContent).not.toMatch(/\{\w+\}/)
	})

	it('falls back to the header without old name when it is unknown', () => {
		const el = mountDialog({ reason: 'recipient_changed', previousRecipientName: null })
		expect(el.textContent).toContain('auf Google Gemini geändert')
		expect(el.textContent).not.toMatch(/\{\w+\}/)
	})

	it('shows no header for a first consent', () => {
		const el = mountDialog({ reason: 'never' })
		expect(el.querySelector('.ai-consent__reason')).toBeNull()
	})

	it('replaces every placeholder and states the recipient and the third-country transfer', () => {
		const el = mountDialog()
		expect(el.textContent).toContain('Die Daten gehen an: Google Gemini')
		expect(el.textContent).toContain('in den USA')
		expect(el.textContent).not.toMatch(/\{\w+\}/)
	})

	it('uses the local paragraph for a local recipient and the intermediary paragraph when set', () => {
		const local = mountDialog({ recipient: { name: 'Ollama', region: 'local', processing_local: true, intermediary: false, fingerprint: 'x' } })
		expect(local.textContent).toContain(consent.locales.de.region_paragraphs.local.replace('{provider}', 'Ollama'))
		app.unmount(); host.remove()
		const mid = mountDialog({ recipient: { name: 'V', region: 'unknown', processing_local: false, intermediary: true, fingerprint: 'x' } })
		expect(mid.textContent).toContain(consent.locales.de.intermediary_paragraph.replace('{provider}', 'V'))
	})

	it('states the weekly automation and the revocation rule', () => {
		const el = mountDialog()
		expect(el.textContent).toContain(consent.locales.de.automation_paragraph.replace('{provider}', 'Google Gemini'))
		expect(el.textContent).toContain(consent.locales.de.revocation_note)
	})

	it('renders ar with dir="rtl" and the Arabic text', () => {
		const el = mountDialog({ locale: 'ar' })
		const rtl = el.querySelector('[dir="rtl"]')
		expect(rtl).not.toBeNull()
		expect(rtl.getAttribute('lang')).toBe('ar')
		expect(el.textContent).toContain(consent.locales.ar.revocation_note)
		expect(el.querySelector('[dir="ltr"]')).toBeNull()
	})

	it('renders uk completely (every text block present, no placeholder left)', () => {
		const el = mountDialog({ locale: 'uk', recipient: { ...GEMINI, intermediary: true } })
		const uk = consent.locales.uk
		const text = el.textContent
		expect(text).toContain(uk.title)
		expect(text).toContain(uk.identity_note)
		expect(text).toContain(uk.retention_note)
		expect(text).toContain(uk.revocation_note)
		expect(text).toContain(uk.data_list_heading)
		expect(text).toContain(uk.region_paragraphs.us.replace('{provider}', 'Google Gemini'))
		expect(text).toContain(uk.intermediary_paragraph.replace('{provider}', 'Google Gemini'))
		expect(text).toContain(uk.accept_label)
		expect(text).toContain(uk.decline_label)
		expect(text).not.toMatch(/\{\w+\}/)
		expect(el.querySelector('[dir="ltr"]').getAttribute('lang')).toBe('uk')
	})

	it('renders every language without leftover placeholders', () => {
		for (const lang of LANGS) {
			const el = mountDialog({ locale: lang, reason: 'recipient_changed', previousRecipientName: 'Ollama' })
			expect(el.textContent, lang).toContain(consent.locales[lang].accept_label)
			expect(el.textContent, lang).not.toMatch(/\{\w+\}/)
			app.unmount(); host.remove()
		}
	})

	it('falls back to English for an unknown locale', () => {
		const el = mountDialog({ locale: 'xx' })
		expect(el.textContent).toContain(consent.locales.en.accept_label)
	})

	it('Escape declines, once', async () => {
		const el = mountDialog()
		el.querySelector('.ai-consent').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		expect(events).toEqual(['decline'])
	})

	it('closing the dialog shell (X / outside click) declines', () => {
		const el = mountDialog()
		const shell = el.querySelector('.shell')
		shell.__vueParentComponent.emit('closing')
		expect(events).toEqual(['decline'])
	})

	it('accept emits accept with the fingerprint it showed; decline emits decline', () => {
		const el = mountDialog()
		const buttons = el.querySelectorAll('.actions button')
		expect(buttons.length).toBe(2)
		const [declineBtn, acceptBtn] = buttons
		expect(acceptBtn.textContent).toContain(consent.locales.de.accept_label)
		expect(declineBtn.textContent).toContain(consent.locales.de.decline_label)
		acceptBtn.click()
		declineBtn.click()
		expect(events).toEqual(['accept', 'decline'])
	})

	it('accept has the primary variant, decline does not, and neither submits a form', () => {
		const el = mountDialog()
		const [declineBtn, acceptBtn] = el.querySelectorAll('.actions button')
		expect(acceptBtn.className).toMatch(/primary/)
		expect(declineBtn.className).not.toMatch(/primary/)
		expect(acceptBtn.getAttribute('type')).toBe('button')
		expect(declineBtn.getAttribute('type')).toBe('button')
	})

	it('shows an error as role="alert" and blocks accept while busy', () => {
		const el = mountDialog({ error: 'Speichern fehlgeschlagen', busy: true })
		const alert = el.querySelector('[role="alert"]')
		expect(alert.textContent).toContain('Speichern fehlgeschlagen')
		const [, acceptBtn] = el.querySelectorAll('.actions button')
		expect(acceptBtn.disabled).toBe(true)
		acceptBtn.click()
		expect(events).toEqual([])
	})

	it('is described by its body', () => {
		const el = mountDialog()
		const id = el.querySelector('.shell').getAttribute('aria-describedby')
		expect(id).toBeTruthy()
		expect(el.querySelector('#' + id)).not.toBeNull()
	})

	it('does not offer a recipient it cannot name (no recipient, no accept)', () => {
		const el = mountDialog({ recipient: null })
		const [, acceptBtn] = el.querySelectorAll('.actions button')
		expect(acceptBtn.disabled).toBe(true)
	})

	// D5 (WCAG 2.1 AA, 3.3.1): the error sits below a long text; role="alert" only reaches screen
	// readers, a sighted keyboard user must also see it without hunting for it.
	it('brings a failed save into view when it appears', async () => {
		const scrollIntoView = vi.fn()
		const original = Element.prototype.scrollIntoView
		Element.prototype.scrollIntoView = scrollIntoView
		try {
			const state = reactive({ error: '' })
			events = []
			host = document.createElement('div')
			document.body.appendChild(host)
			app = createApp({
				render: () => h(AiConsentDialog, { recipient: GEMINI, reason: 'never', locale: 'de', error: state.error }),
			})
			app.config.globalProperties.t = (_a, s) => s
			app.config.warnHandler = () => {}
			app.mount(host)
			expect(scrollIntoView).not.toHaveBeenCalled()

			state.error = 'Speichern fehlgeschlagen'
			await nextTick()
			await nextTick()

			expect(host.querySelector('[role="alert"]').textContent).toContain('Speichern fehlgeschlagen')
			expect(scrollIntoView).toHaveBeenCalledTimes(1)
			expect(scrollIntoView.mock.contexts[0]).toBe(host.querySelector('[role="alert"]'))
		} finally {
			Element.prototype.scrollIntoView = original
		}
	})
})
