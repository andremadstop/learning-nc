import { describe, expect, it, vi, beforeEach } from 'vitest'

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
import OnboardingRedesign from '../../src/components/OnboardingRedesign.vue'

/**
 * 5.7.0 review B1: a consent is bound to the recipient the user was shown. The client sends back
 * the recipient fingerprint from GET /api/profile/telos/consent; the server never fills it in,
 * and a consent the server does not report as valid does not count on the client either.
 */
const URL = '/apps/learning/api/profile/telos/consent'

function virtuProfContext(overrides = {}) {
	return {
		consentData: { version: '3.0' },
		aiConsentVersion: null,
		consentRecipientFingerprint: null,
		pendingChatMessage: null,
		showAiConsentDialog: true,
		consentSaveError: null,
		handleChatSend: vi.fn(),
		vt: (text) => text,
		...overrides,
	}
}

describe('VirtuProf consent carries the recipient', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('posts the fingerprint it was shown, with source dialog', async () => {
		axios.post.mockResolvedValueOnce({ data: { status: 'ok', valid: true } })
		const ctx = virtuProfContext({ consentRecipientFingerprint: 'fp-shown' })

		await VirtuProf.methods.handleConsentAccept.call(ctx)

		expect(axios.get).not.toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledWith(URL, { version: '3.0', recipient_fingerprint: 'fp-shown', source: 'dialog' })
		expect(ctx.aiConsentVersion).toBe('3.0')
	})

	it('asks the server for the recipient first when it does not know one', async () => {
		axios.get.mockResolvedValueOnce({ data: { valid: false, recipient: { name: 'Google Gemini', fingerprint: 'fp-server' } } })
		axios.post.mockResolvedValueOnce({ data: { status: 'ok', valid: true } })
		const ctx = virtuProfContext()

		await VirtuProf.methods.handleConsentAccept.call(ctx)

		expect(axios.post).toHaveBeenCalledWith(URL, { version: '3.0', recipient_fingerprint: 'fp-server', source: 'dialog' })
	})

	it('does not count a consent the server refused because the recipient changed', async () => {
		axios.post.mockRejectedValueOnce({ response: { status: 409, data: { reason: 'recipient_changed', recipient: { fingerprint: 'fp-new' } } } })
		const ctx = virtuProfContext({ consentRecipientFingerprint: 'fp-old' })

		await VirtuProf.methods.handleConsentAccept.call(ctx)

		expect(ctx.aiConsentVersion).toBeNull()
		expect(ctx.consentSaveError).toBeTruthy()
		expect(ctx.showAiConsentDialog).toBe(true)
		expect(ctx.consentRecipientFingerprint).toBeNull()
	})

	it('trusts the server status: a stored current version that is not valid is no consent', () => {
		const ctx = virtuProfContext({ aiConsentVersion: 'stale' })

		VirtuProf.methods.applyConsentStatus.call(ctx, {
			ai_consent_version: '3.0', valid: false, reason: 'recipient_changed', recipient: { fingerprint: 'fp-now' },
		})
		expect(ctx.aiConsentVersion).toBeNull()
		expect(ctx.consentRecipientFingerprint).toBe('fp-now')

		VirtuProf.methods.applyConsentStatus.call(ctx, { ai_consent_version: '3.0', valid: true, recipient: { fingerprint: 'fp-now' } })
		expect(ctx.aiConsentVersion).toBe('3.0')
	})
})

describe('Onboarding consent carries the recipient', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	function onboardingContext(aiConsent) {
		return { store: { profileChoices: { aiConsent } } }
	}

	it('posts the fingerprint from the status, with source onboarding', async () => {
		axios.get.mockResolvedValueOnce({ data: { recipient: { name: 'Google Gemini', fingerprint: 'fp-server' } } })
		axios.post.mockResolvedValueOnce({ data: { status: 'ok' } })

		await OnboardingRedesign.methods.saveAiConsent.call(onboardingContext('yes'))

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe(URL)
		expect(body.recipient_fingerprint).toBe('fp-server')
		expect(body.source).toBe('onboarding')
		expect(typeof body.version).toBe('string')
	})

	it('stores nothing while AI is switched off (no recipient to consent to)', async () => {
		axios.get.mockResolvedValueOnce({ data: { recipient: null, ai_enabled: false } })

		await OnboardingRedesign.methods.saveAiConsent.call(onboardingContext('yes'))

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('asks nothing when the user declined', async () => {
		await OnboardingRedesign.methods.saveAiConsent.call(onboardingContext('no'))

		expect(axios.get).not.toHaveBeenCalled()
		expect(axios.post).not.toHaveBeenCalled()
	})
})
