import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
})
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	getLanguage: () => 'de',
}))
vi.mock('../../src/components/AccessibleDialog.vue', () => ({
	default: {
		name: 'AccessibleDialog',
		props: ['name'],
		emits: ['closing'],
		template: '<div role="dialog"><h2>{{ name }}</h2><slot /><div class="actions"><slot name="actions" /></div></div>',
	},
}))

import axios from '@nextcloud/axios'
import consent from '../../data/ai-consent.json'
import AiConsentPrompt from '../../src/components/AiConsentPrompt.vue'

const URL = '/apps/learning/api/profile/telos/consent'
const GEMINI = { name: 'Google Gemini', region: 'us', processing_local: false, intermediary: false, fingerprint: 'fp-gemini' }
const OLLAMA = { name: 'Ollama', region: 'local', processing_local: true, intermediary: false, fingerprint: 'fp-ollama' }
const status = (over = {}) => ({ valid: false, reason: 'never', current_version: '3.0', ai_enabled: true, recipient: GEMINI, previous_recipient_name: null, ...over })

let host, app, events

async function flush() {
	for (let i = 0; i < 6; i++) await Promise.resolve()
	await nextTick()
}

async function mountPrompt(props = {}) {
	events = []
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({
		render: () => h(AiConsentPrompt, {
			source: 'dialog',
			onGranted: (p) => events.push(['granted', p]),
			onDeclined: () => events.push(['declined']),
			onUnavailable: (p) => events.push(['unavailable', p.reason]),
			...props,
		}),
	})
	app.config.globalProperties.t = (_a, s) => s
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
	return host
}

const acceptButton = () => host.querySelector('.actions button:last-child')
const declineButton = () => host.querySelector('.actions button:first-child')

beforeEach(() => {
	document.body.innerHTML = ''
	axios.get.mockReset()
	axios.post.mockReset()
	axios.delete.mockReset()
})
afterEach(() => { app?.unmount(); app = null; host?.remove() })

describe('AiConsentPrompt', () => {
	it('loads the status itself and shows the dialog for the reported recipient', async () => {
		axios.get.mockResolvedValueOnce({ data: status() })
		const el = await mountPrompt()
		expect(axios.get).toHaveBeenCalledWith(URL)
		expect(el.textContent).toContain('Die Daten gehen an: Google Gemini')
	})

	it('shows the reason header the server reports', async () => {
		axios.get.mockResolvedValueOnce({ data: status({ reason: 'recipient_changed', previous_recipient_name: 'Ollama' }) })
		const el = await mountPrompt()
		expect(el.textContent).toContain('von Ollama auf Google Gemini')
	})

	it('accept posts version, the shown fingerprint and the source, then emits granted', async () => {
		axios.get.mockResolvedValueOnce({ data: status() })
		axios.post.mockResolvedValueOnce({ data: { status: 'ok', valid: true } })
		await mountPrompt({ source: 'onboarding' })

		acceptButton().click()
		await flush()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(URL, { version: consent.version, recipient_fingerprint: 'fp-gemini', source: 'onboarding' })
		expect(events).toEqual([['granted', { written: true }]])
	})

	it('announces a granted consent to the rest of the page', async () => {
		axios.get.mockResolvedValueOnce({ data: status() })
		axios.post.mockResolvedValueOnce({ data: { status: 'ok', valid: true } })
		const seen = []
		const handler = (e) => seen.push(e.detail.valid)
		window.addEventListener('learning:ai-consent-changed', handler)
		await mountPrompt()
		acceptButton().click()
		await flush()
		window.removeEventListener('learning:ai-consent-changed', handler)
		expect(seen).toEqual([true])
	})

	it('409 recipient_changed: no consent, dialog stays, shows the NEW recipient and an alert', async () => {
		axios.get.mockResolvedValueOnce({ data: status() })
		axios.post.mockRejectedValueOnce({ response: { status: 409, data: { error: 'recipient_changed', reason: 'recipient_changed', recipient: OLLAMA } } })
		axios.get.mockResolvedValueOnce({ data: status({ reason: 'recipient_changed', recipient: OLLAMA, previous_recipient_name: 'Google Gemini' }) })
		const el = await mountPrompt()

		acceptButton().click()
		await flush()

		expect(events).toEqual([])
		expect(el.textContent).toContain('Die Daten gehen an: Ollama')
		expect(el.querySelector('[role="alert"]').textContent).toContain('geändert')

		// the second accept carries the fingerprint of the recipient now shown, not the old one
		axios.post.mockResolvedValueOnce({ data: { status: 'ok', valid: true } })
		acceptButton().click()
		await flush()
		expect(axios.post.mock.calls[1][1].recipient_fingerprint).toBe('fp-ollama')
		expect(events).toEqual([['granted', { written: true }]])
	})

	it('a failed save is no consent: error shown, nothing granted', async () => {
		axios.get.mockResolvedValueOnce({ data: status() })
		axios.post.mockRejectedValueOnce(new Error('HTTP 500'))
		const el = await mountPrompt()
		acceptButton().click()
		await flush()
		expect(events).toEqual([])
		expect(el.querySelector('[role="alert"]')).not.toBeNull()
	})

	it('decline emits declined and writes nothing', async () => {
		axios.get.mockResolvedValueOnce({ data: status() })
		await mountPrompt()
		declineButton().click()
		await flush()
		expect(events).toEqual([['declined']])
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('unavailable when AI is switched off (no recipient): no dialog', async () => {
		axios.get.mockResolvedValueOnce({ data: status({ recipient: null, ai_enabled: false }) })
		const el = await mountPrompt()
		expect(events).toEqual([['unavailable', 'no_recipient']])
		expect(el.querySelector('[role="dialog"]')).toBeNull()
	})

	it('a consent that is already valid is reported as granted without writing', async () => {
		axios.get.mockResolvedValueOnce({ data: status({ valid: true }) })
		const el = await mountPrompt()
		expect(events).toEqual([['granted', { written: false }]])
		expect(el.querySelector('[role="dialog"]')).toBeNull()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('unavailable when the status cannot be loaded (fail closed, never a consent)', async () => {
		axios.get.mockRejectedValueOnce(new Error('offline'))
		await mountPrompt()
		expect(events).toEqual([['unavailable', 'error']])
		expect(axios.post).not.toHaveBeenCalled()
	})
})
