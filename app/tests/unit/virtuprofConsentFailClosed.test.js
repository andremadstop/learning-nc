import { describe, expect, it, vi, beforeEach } from 'vitest'

// Same bootstrapping as virtuprofOnboardingDeclined.test.js: VirtuProf pulls in child
// components that rely on the global `t` and on `translate` from @nextcloud/l10n.
vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
}))

import axios from '@nextcloud/axios'
import VirtuProf from '../../src/components/VirtuProf.vue'

/**
 * 5.5.2 — fail-closed consent.
 *
 * handleConsentAccept() used to set aiConsentVersion and send the waiting chat message even
 * when saving the consent failed ("still allow this session"). The server never recorded a
 * consent, yet the message went to the AI provider. A failed save must not count as consent.
 */
function makeContext() {
	return {
		consentData: { version: '2.0' },
		aiConsentVersion: null,
		pendingChatMessage: 'Was ist ein VLAN?',
		showAiConsentDialog: true,
		consentSaveError: null,
		handleChatSend: vi.fn(),
		vt: (text) => text,
	}
}

describe('VirtuProf consent is fail-closed', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('does not count a failed save as consent and does not send the waiting message', async () => {
		axios.post.mockRejectedValueOnce(new Error('HTTP 500'))
		const ctx = makeContext()

		await VirtuProf.methods.handleConsentAccept.call(ctx)

		expect(ctx.aiConsentVersion).toBeNull()
		expect(ctx.handleChatSend).not.toHaveBeenCalled()
		expect(ctx.showAiConsentDialog).toBe(true)
		expect(ctx.consentSaveError).toBeTruthy()
		expect(ctx.pendingChatMessage).toBe('Was ist ein VLAN?')
	})

	it('records consent and sends the waiting message after a successful save', async () => {
		axios.post.mockResolvedValueOnce({ data: { status: 'ok' } })
		const ctx = makeContext()

		await VirtuProf.methods.handleConsentAccept.call(ctx)

		expect(ctx.aiConsentVersion).toBe('2.0')
		expect(ctx.showAiConsentDialog).toBe(false)
		expect(ctx.consentSaveError).toBeNull()
		expect(ctx.handleChatSend).toHaveBeenCalledWith('Was ist ein VLAN?')
	})

	it('declining clears a previous save error', () => {
		const ctx = { ...makeContext(), consentSaveError: 'x' }

		VirtuProf.methods.handleConsentDecline.call(ctx)

		expect(ctx.consentSaveError).toBeNull()
		expect(ctx.showAiConsentDialog).toBe(false)
	})
})
