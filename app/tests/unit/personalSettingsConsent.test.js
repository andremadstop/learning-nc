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
import PersonalSettings from '../../src/components/PersonalSettings.vue'

const URL = '/apps/learning/api/profile/telos/consent'

function ctx(over = {}) {
	return {
		consentLoading: true,
		consentValid: false,
		consentProvider: '',
		consentRevoking: false,
		consentRevoked: false,
		consentError: '',
		...over,
	}
}

describe('PersonalSettings: AI consent revocation', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.delete.mockReset()
		axios.post.mockReset()
	})

	it('reads the consent status from the server', async () => {
		axios.get.mockResolvedValueOnce({ data: { valid: true, recipient: { name: 'Google Gemini' } } })
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(axios.get).toHaveBeenCalledWith(URL)
		expect(c.consentValid).toBe(true)
		expect(c.consentProvider).toBe('Google Gemini')
		expect(c.consentLoading).toBe(false)
	})

	it('revoking calls DELETE on the consent route and shows the consent as gone', async () => {
		axios.delete.mockResolvedValueOnce({ data: { status: 'ok', valid: false } })
		const c = ctx({ consentValid: true, consentProvider: 'Google Gemini' })
		await PersonalSettings.methods.revokeAiConsent.call(c)
		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(axios.delete).toHaveBeenCalledWith(URL)
		expect(axios.post).not.toHaveBeenCalled()
		expect(c.consentValid).toBe(false)
		expect(c.consentRevoked).toBe(true)
		expect(c.consentRevoking).toBe(false)
	})

	it('announces the revocation to the rest of the page (VirtuProf stops sending)', async () => {
		axios.delete.mockResolvedValueOnce({ data: { status: 'ok', valid: false } })
		const seen = []
		const handler = (e) => seen.push(e.detail.valid)
		window.addEventListener('learning:ai-consent-changed', handler)
		await PersonalSettings.methods.revokeAiConsent.call(ctx({ consentValid: true }))
		window.removeEventListener('learning:ai-consent-changed', handler)
		expect(seen).toEqual([false])
	})

	it('a failed revocation is reported and the consent still counts as given', async () => {
		axios.delete.mockRejectedValueOnce(new Error('500'))
		const c = ctx({ consentValid: true })
		await PersonalSettings.methods.revokeAiConsent.call(c)
		expect(c.consentValid).toBe(true)
		expect(c.consentRevoked).toBe(false)
		expect(c.consentError).not.toBe('')
		expect(c.consentRevoking).toBe(false)
	})
})
