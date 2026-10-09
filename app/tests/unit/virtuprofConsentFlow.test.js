import { describe, expect, it, vi, beforeEach } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
	isRTL: () => false,
}))

import axios from '@nextcloud/axios'
import VirtuProf from '../../src/components/VirtuProf.vue'

/**
 * 5.7.0 D3: VirtuProf no longer writes the consent itself. AiConsentPrompt (the dialog) does;
 * VirtuProf only decides WHEN to ask: no valid consent (server status), or an AI answer that
 * says consent_required / recipient_changed. A consent refused by the server must reopen the
 * dialog, keep the message for a resend, and never loop.
 */
function chatContext(overrides = {}) {
	return {
		consentValid: true,
		showAiConsentDialog: false,
		pendingChatMessage: null,
		lastRefusedMessage: null,
		visible: true,
		isMinimized: false,
		chatMessages: [],
		chatLoading: false,
		currentContext: null,
		hintLevel: 0,
		applyReaction: vi.fn(),
		isHintRequest: () => false,
		vt: (text) => text,
		...overrides,
	}
}

describe('VirtuProf consent gate', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('trusts only the server status: a valid flag, not a stored version string', () => {
		const ctx = chatContext({ consentValid: true })
		VirtuProf.methods.applyConsentStatus.call(ctx, { valid: false, reason: 'recipient_changed', ai_consent_version: '3.0' })
		expect(ctx.consentValid).toBe(false)
		VirtuProf.methods.applyConsentStatus.call(ctx, { valid: true, ai_consent_version: '3.0' })
		expect(ctx.consentValid).toBe(true)
	})

	it('opens the dialog and sends nothing while the consent is not valid', async () => {
		const ctx = chatContext({ consentValid: false })
		await VirtuProf.methods.handleChatSend.call(ctx, 'Was ist ein VLAN?')
		expect(ctx.showAiConsentDialog).toBe(true)
		expect(ctx.pendingChatMessage).toBe('Was ist ein VLAN?')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it.each([
		['403 consent_required', { response: { status: 403, data: { consent_required: true } } }],
		['409 recipient_changed', { response: { status: 409, data: { error: 'recipient_changed' } } }],
	])('%s from the chat reopens the dialog and keeps the message', async (_label, err) => {
		axios.post.mockRejectedValueOnce(err)
		const ctx = chatContext()
		await VirtuProf.methods.handleChatSend.call(ctx, 'Was ist ein VLAN?')
		expect(ctx.consentValid).toBe(false)
		expect(ctx.showAiConsentDialog).toBe(true)
		expect(ctx.pendingChatMessage).toBe('Was ist ein VLAN?')
		// the unsent message is not left in the history (it is re-sent after consent)
		expect(ctx.chatMessages).toHaveLength(0)
		expect(ctx.chatLoading).toBe(false)
	})

	it('does not loop: a second refusal of the same message ends in an error text, not another dialog', async () => {
		axios.post.mockRejectedValue({ response: { status: 403, data: { consent_required: true } } })
		const ctx = chatContext({ lastRefusedMessage: 'Was ist ein VLAN?' })
		await VirtuProf.methods.handleChatSend.call(ctx, 'Was ist ein VLAN?')
		expect(ctx.showAiConsentDialog).toBe(false)
		expect(ctx.chatMessages.at(-1).role).toBe('assistant')
	})
})

describe('VirtuProf reacts to the dialog', () => {
	it('granted: closes the dialog, marks the consent valid and sends the waiting message', () => {
		const ctx = chatContext({ consentValid: false, showAiConsentDialog: true, pendingChatMessage: 'Frage', handleChatSend: vi.fn() })
		VirtuProf.methods.handleConsentGranted.call(ctx, { written: true })
		expect(ctx.showAiConsentDialog).toBe(false)
		expect(ctx.consentValid).toBe(true)
		expect(ctx.pendingChatMessage).toBeNull()
		expect(ctx.handleChatSend).toHaveBeenCalledWith('Frage')
	})

	it('declined: closes the dialog, forgets the waiting message, consent stays invalid', () => {
		const ctx = chatContext({ consentValid: false, showAiConsentDialog: true, pendingChatMessage: 'Frage' })
		VirtuProf.methods.handleConsentDeclined.call(ctx)
		expect(ctx.showAiConsentDialog).toBe(false)
		expect(ctx.pendingChatMessage).toBeNull()
		expect(ctx.consentValid).toBe(false)
	})

	it('unavailable (AI switched off meanwhile): closes the dialog and says so instead of staying silent', () => {
		const ctx = chatContext({ consentValid: false, showAiConsentDialog: true, pendingChatMessage: 'Frage' })
		VirtuProf.methods.handleConsentUnavailable.call(ctx, { reason: 'no_recipient' })
		expect(ctx.showAiConsentDialog).toBe(false)
		expect(ctx.pendingChatMessage).toBeNull()
		expect(ctx.chatMessages.at(-1).text).toBe('AI is currently disabled. Please contact your admin.')
	})

	it('a revocation elsewhere on the page makes the consent invalid here too', () => {
		const ctx = chatContext({ consentValid: true })
		VirtuProf.methods.handleConsentChanged.call(ctx, false)
		expect(ctx.consentValid).toBe(false)
	})
})
