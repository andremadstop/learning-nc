import { describe, it, expect, vi, beforeEach } from 'vitest'

// Charakterisierungs-Netz für die DSGVO-kritischen Chat/Consent-Flows von VirtuProf.
// Gebaut VOR der Mixin-Extraktion (Phase A2). Muss danach OHNE Änderung grün bleiben —
// das ist der Beweis, dass die Extraktion verhaltensneutral war.
//
// WICHTIG (advisor): Zugriff location-agnostisch über die GEMERGTEN Options
// (Komponente + Mixins), damit der Test nicht bricht, sobald handleChatSend in ein
// Mixin wandert (Vue merged Mixins erst zur Instanziierung, nicht ins Options-Objekt).

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))

import axios from '@nextcloud/axios'
import VirtuProf from '../../src/components/VirtuProf.vue'

function mergedOptions(comp) {
	const mixins = comp.mixins || []
	const data = Object.assign({}, ...mixins.map(m => (m.data ? m.data() : {})), comp.data ? comp.data() : {})
	const methods = Object.assign({}, ...mixins.map(m => m.methods || {}), comp.methods || {})
	return { data, methods }
}

function createInstance(overrides = {}) {
	const { data, methods } = mergedOptions(VirtuProf)
	const instance = { ...data, $emit: vi.fn(), $refs: {}, ...overrides }
	for (const [name, fn] of Object.entries(methods)) {
		instance[name] = fn.bind(instance)
	}
	// Zentrale Cross-Cutter, die im Component-Kern bleiben, für Isolation stubben:
	instance.applyReaction = vi.fn()
	instance.vt = (key) => key
	return instance
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('handleChatSend — Consent-Gate (DSGVO)', () => {
	it('bei Versions-Mismatch: KEIN LLM-Call, Nachricht gepuffert, Dialog offen', async () => {
		const instance = createInstance({
			aiConsentVersion: 'v1',
			consentData: { version: 'v2' },
			chatLoading: false,
			chatMessages: [],
			pendingChatMessage: null,
			showAiConsentDialog: false,
			visible: false,
			isMinimized: false,
			helpView: null,
		})
		await instance.handleChatSend('bitte erklär mir das')
		// Die zentrale DSGVO-Garantie: kein Netzwerk-Call vor Zustimmung.
		expect(axios.post).not.toHaveBeenCalled()
		expect(instance.showAiConsentDialog).toBe(true)
		expect(instance.pendingChatMessage).toBe('bitte erklär mir das')
		// Nachricht darf NICHT im Verlauf landen.
		expect(instance.chatMessages).toHaveLength(0)
	})

	it('bei gültigem Consent: sendet und hängt User- + Assistant-Nachricht an', async () => {
		axios.post.mockResolvedValueOnce({ data: { answer: 'Das ist die Antwort.' } })
		const instance = createInstance({
			aiConsentVersion: 'v2',
			consentData: { version: 'v2' },
			chatLoading: false,
			chatMessages: [],
			currentContext: {},
			hintLevel: 0,
		})
		await instance.handleChatSend('meine frage')
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(instance.chatMessages).toEqual([
			{ role: 'user', text: 'meine frage' },
			{ role: 'assistant', text: 'Das ist die Antwort.', speakable: true },
		])
		expect(instance.chatLoading).toBe(false)
	})

	it('leere Nachricht oder laufender Request → no-op', async () => {
		const busy = createInstance({ aiConsentVersion: 'v2', consentData: { version: 'v2' }, chatLoading: true, chatMessages: [] })
		await busy.handleChatSend('x')
		expect(axios.post).not.toHaveBeenCalled()
		expect(busy.chatMessages).toHaveLength(0)
	})
})

describe('handleChatSend — Fehlerpfade', () => {
	const cases = [
		[400, 'Your message could not be processed. Please try a shorter question.'],
		[503, 'AI is currently disabled. Please contact your admin.'],
		[429, 'Too many requests. Please wait a moment before asking again.'],
		[500, 'Something went wrong. Please try again later.'],
	]
	for (const [status, text] of cases) {
		it(`HTTP ${status} → passende Fehlermeldung`, async () => {
			axios.post.mockRejectedValueOnce({ response: { status } })
			const instance = createInstance({
				aiConsentVersion: 'v2', consentData: { version: 'v2' },
				chatLoading: false, chatMessages: [], currentContext: {},
			})
			await instance.handleChatSend('frage')
			const last = instance.chatMessages[instance.chatMessages.length - 1]
			expect(last).toEqual({ role: 'assistant', text, speakable: true })
			expect(instance.chatLoading).toBe(false)
		})
	}
})

describe('handleChatSend — Hint-Level', () => {
	it('Hint-Anfrage mit aktivem Fragekontext erhöht hintLevel (max 3)', async () => {
		axios.post.mockResolvedValue({ data: { answer: 'ok' } })
		const instance = createInstance({
			aiConsentVersion: 'v2', consentData: { version: 'v2' },
			chatLoading: false, chatMessages: [], hintLevel: 0,
			currentContext: { questionContext: 'irgendwas' },
		})
		await instance.handleChatSend('tipp')
		expect(instance.hintLevel).toBe(1)
		expect(axios.post.mock.calls[0][1].hintLevel).toBe(1)
	})

	it('Hint-Anfrage ohne Fragekontext erhöht hintLevel NICHT', async () => {
		axios.post.mockResolvedValue({ data: { answer: 'ok' } })
		const instance = createInstance({
			aiConsentVersion: 'v2', consentData: { version: 'v2' },
			chatLoading: false, chatMessages: [], hintLevel: 0, currentContext: {},
		})
		await instance.handleChatSend('tipp')
		expect(instance.hintLevel).toBe(0)
	})
})

describe('handleConsentAccept / handleConsentDecline', () => {
	it('Accept speichert Version, schließt Dialog und sendet gepufferte Nachricht nach', async () => {
		axios.post
			.mockResolvedValueOnce({ data: {} }) // consent-POST
			.mockResolvedValueOnce({ data: { answer: 'nachgereicht' } }) // nachgesendeter chat-POST
		const instance = createInstance({
			aiConsentVersion: 'v1',
			consentData: { version: 'v2' },
			showAiConsentDialog: true,
			pendingChatMessage: 'gepufferte frage',
			chatLoading: false,
			chatMessages: [],
			currentContext: {},
		})
		await instance.handleConsentAccept()
		expect(instance.aiConsentVersion).toBe('v2')
		expect(instance.showAiConsentDialog).toBe(false)
		expect(instance.pendingChatMessage).toBe(null)
		// gepufferte Nachricht wurde nachgesendet (2. POST = chat)
		expect(axios.post).toHaveBeenCalledTimes(2)
		expect(instance.chatMessages.some(m => m.role === 'user' && m.text === 'gepufferte frage')).toBe(true)
	})

	it('Decline schließt Dialog und verwirft die gepufferte Nachricht ohne Call', () => {
		const instance = createInstance({
			showAiConsentDialog: true,
			pendingChatMessage: 'wird verworfen',
		})
		instance.handleConsentDecline()
		expect(instance.showAiConsentDialog).toBe(false)
		expect(instance.pendingChatMessage).toBe(null)
		expect(axios.post).not.toHaveBeenCalled()
	})
})
