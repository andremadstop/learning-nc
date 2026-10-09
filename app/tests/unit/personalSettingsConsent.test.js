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
		consentStored: false,
		consentStatusFailed: false,
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

// Review finding (Codex): valid=false does not mean "nothing stored". With AI switched off or a
// changed recipient the server reports valid=false while the consent is still on record
// (ai_consent_version) and comes back to life when the admin switches back. Settings must say so
// and offer the revocation; a failed status read must not read as "you did not consent".
describe('PersonalSettings: a stored consent that is not valid right now', () => {
	const state = (c) => PersonalSettings.computed.consentState.call(c)
	const canRevoke = (c) => PersonalSettings.computed.consentCanRevoke.call({ ...c, consentState: state(c) })

	beforeEach(() => {
		axios.get.mockReset()
		axios.delete.mockReset()
	})

	it('AI switched off with a consent on record: state "stored", revocation offered', async () => {
		axios.get.mockResolvedValueOnce({ data: { valid: false, reason: 'none', ai_enabled: false, ai_consent_version: '3.0', current_version: '3.0', recipient: null } })
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(c.consentValid).toBe(false)
		expect(c.consentStored).toBe(true)
		expect(state(c)).toBe('stored')
		expect(canRevoke(c)).toBe(true)
	})

	it('recipient changed with a consent on record: state "stored", revocation offered', async () => {
		axios.get.mockResolvedValueOnce({ data: { valid: false, reason: 'recipient_changed', ai_enabled: true, ai_consent_version: '3.0', current_version: '3.0', recipient: { name: 'Ollama' } } })
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(state(c)).toBe('stored')
		expect(canRevoke(c)).toBe(true)
	})

	it('nothing on record: state "none", no revocation button', async () => {
		axios.get.mockResolvedValueOnce({ data: { valid: false, reason: 'never', ai_enabled: true, ai_consent_version: null, current_version: '3.0', recipient: { name: 'Google Gemini' } } })
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(state(c)).toBe('none')
		expect(canRevoke(c)).toBe(false)
	})

	it('a valid consent: state "valid", revocation offered', async () => {
		axios.get.mockResolvedValueOnce({ data: { valid: true, ai_consent_version: '3.0', current_version: '3.0', recipient: { name: 'Google Gemini' } } })
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(state(c)).toBe('valid')
		expect(canRevoke(c)).toBe(true)
	})

	// An upgraded 5.6 user keeps ai_consent_version '2.0'. That consent can never count again
	// (current_version is 3.0), so "it can apply again" would be false: it reads as "none".
	it('a consent for an older text (upgrade, reason version) is not "stored": it can never count again', async () => {
		axios.get.mockResolvedValueOnce({ data: { valid: false, reason: 'version', ai_enabled: true, ai_consent_version: '2.0', current_version: '3.0', recipient: { name: 'Google Gemini' } } })
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(c.consentStored).toBe(false)
		expect(state(c)).toBe('none')
		expect(canRevoke(c)).toBe(false)
	})

	it('AI switched off with an outdated consent on record: "none" as well', async () => {
		axios.get.mockResolvedValueOnce({ data: { valid: false, reason: 'none', ai_enabled: false, ai_consent_version: '2.0', current_version: '3.0', recipient: null } })
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(state(c)).toBe('none')
	})

	it('status read failed: state "unknown" (not "you did not consent"), revocation offered', async () => {
		axios.get.mockRejectedValueOnce(new Error('500'))
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(c.consentLoading).toBe(false)
		expect(state(c)).toBe('unknown')
		expect(canRevoke(c)).toBe(true)
	})

	it('revoking a stored consent clears it: state "none" afterwards', async () => {
		axios.delete.mockResolvedValueOnce({ data: { status: 'ok', valid: false } })
		const c = ctx({ consentStored: true })
		await PersonalSettings.methods.revokeAiConsent.call(c)
		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(state(c)).toBe('none')
		expect(c.consentRevoked).toBe(true)
	})

	it('revoking after a failed status read settles the state to "none"', async () => {
		axios.delete.mockResolvedValueOnce({ data: { status: 'ok', valid: false } })
		const c = ctx({ consentStatusFailed: true })
		await PersonalSettings.methods.revokeAiConsent.call(c)
		expect(state(c)).toBe('none')
	})

	it('the template shows the revocation button by consentCanRevoke, not by consentValid', async () => {
		const fs = await import('node:fs')
		const src = fs.readFileSync(new globalThis.URL('../../src/components/PersonalSettings.vue', import.meta.url), 'utf8')
		expect(src).toMatch(/v-if="consentCanRevoke"[^>]*class="actions"|class="actions"[^>]*v-if="consentCanRevoke"/)
		expect(src).not.toMatch(/v-if="consentValid" class="actions"/)
	})
})

describe('PersonalSettings: data table and privacy notice follow the recipient', () => {
	it('hands the privacy notice the recipient the consent API reports', async () => {
		axios.get.mockResolvedValueOnce({ data: { valid: false, recipient: { name: 'Google Gemini', region: 'us' } } })
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(c.consentRecipient).toEqual({ name: 'Google Gemini', region: 'us' })
		expect(PersonalSettings.computed.privacyAiNotice.call(c)).toEqual({ active: true, provider: 'Google Gemini', region: 'us' })
	})

	it('AI switched off (no recipient) is a notice of its own, not a missing one', async () => {
		axios.get.mockResolvedValueOnce({ data: { valid: false, recipient: null } })
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(PersonalSettings.computed.privacyAiNotice.call(c)).toEqual({ active: false })
	})

	it('shows no notice while the status is loading or could not be read', async () => {
		expect(PersonalSettings.computed.privacyAiNotice.call(ctx())).toBeNull()
		axios.get.mockRejectedValueOnce(new Error('500'))
		const c = ctx()
		await PersonalSettings.methods.loadConsent.call(c)
		expect(PersonalSettings.computed.privacyAiNotice.call(c)).toBeNull()
	})
})
