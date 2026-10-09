import { describe, expect, it, vi, beforeEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))

import axios from '@nextcloud/axios'
import consent from '../../data/ai-consent.json'
import {
	CONSENT_URL,
	fetchConsentStatus,
	saveConsent,
	revokeConsent,
	isConsentRefusal,
	onConsentChanged,
	notifyConsentChanged,
} from '../../src/utils/aiConsent.js'

/**
 * Request-payload contract (memory feedback_request_payload_contracts): the keys and types sent
 * here are exactly what TelosController::saveConsent(?string $version, ?string $recipient_fingerprint,
 * string $source) reads. A key the controller does not read answers 200 and silently does the wrong
 * thing, so the body is asserted key by key.
 */
describe('consent API contract', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.delete.mockReset()
	})

	it('uses the telos consent route', () => {
		expect(CONSENT_URL).toBe('/apps/learning/api/profile/telos/consent')
	})

	it('GET returns the status body unchanged (snake_case keys of the real API)', async () => {
		const body = { valid: false, reason: 'version', current_version: '3.0', recipient: { name: 'X', fingerprint: 'fp' }, previous_recipient_name: null }
		axios.get.mockResolvedValueOnce({ data: body })
		await expect(fetchConsentStatus()).resolves.toEqual(body)
		expect(axios.get).toHaveBeenCalledWith(CONSENT_URL)
	})

	it('POST sends exactly version, recipient_fingerprint and source - all strings', async () => {
		axios.post.mockResolvedValueOnce({ data: { status: 'ok', valid: true } })
		await saveConsent({ fingerprint: 'fp-shown' }, 'onboarding')
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe(CONSENT_URL)
		expect(Object.keys(body).sort()).toEqual(['recipient_fingerprint', 'source', 'version'])
		expect(body.version).toBe(consent.version)
		expect(body.recipient_fingerprint).toBe('fp-shown')
		expect(body.source).toBe('onboarding')
	})

	it('refuses to POST without the shown recipient (no fingerprint, no consent)', async () => {
		await expect(saveConsent(null, 'dialog')).rejects.toThrow()
		await expect(saveConsent({}, 'dialog')).rejects.toThrow()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('only knows the two sources the server accepts', async () => {
		await expect(saveConsent({ fingerprint: 'fp' }, 'settings')).rejects.toThrow()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('revocation is DELETE on the same route', async () => {
		axios.delete.mockResolvedValueOnce({ data: { status: 'ok', valid: false } })
		await revokeConsent()
		expect(axios.delete).toHaveBeenCalledWith(CONSENT_URL)
	})
})

describe('isConsentRefusal', () => {
	it('recognises 403 consent_required', () => {
		expect(isConsentRefusal({ response: { status: 403, data: { consent_required: true } } })).toBe(true)
	})
	it('recognises 409 recipient_changed (error or reason key)', () => {
		expect(isConsentRefusal({ response: { status: 409, data: { error: 'recipient_changed' } } })).toBe(true)
		expect(isConsentRefusal({ response: { status: 409, data: { reason: 'recipient_changed' } } })).toBe(true)
	})
	it('recognises a 200-body that carries consent_required (narrative)', () => {
		expect(isConsentRefusal({ data: { narrative: null, consent_required: true } })).toBe(true)
	})
	it('does not mistake other failures for a refusal', () => {
		expect(isConsentRefusal({ response: { status: 403, data: { error: 'forbidden' } } })).toBe(false)
		expect(isConsentRefusal({ response: { status: 409, data: { error: 'conflict' } } })).toBe(false)
		expect(isConsentRefusal({ response: { status: 500, data: {} } })).toBe(false)
		expect(isConsentRefusal(new Error('network'))).toBe(false)
		expect(isConsentRefusal(null)).toBe(false)
	})
})

describe('consent change notification', () => {
	it('tells every listener, and unsubscribing stops it', () => {
		const seen = []
		const off = onConsentChanged((valid) => seen.push(valid))
		notifyConsentChanged(true)
		off()
		notifyConsentChanged(false)
		expect(seen).toEqual([true])
	})
})
