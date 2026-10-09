import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import consent from '../../data/ai-consent.json'

/**
 * Client side of the AI consent API (TelosController: GET / POST / DELETE
 * /api/profile/telos/consent). The only place that writes or revokes a consent; the dialog flow
 * lives in AiConsentPrompt.vue, which is the only caller of saveConsent().
 *
 * The API keeps snake_case keys: valid, reason, current_version, ai_consent_version, ai_enabled,
 * recipient{name,region,processing_local,intermediary,dpa_url,warning_key,fingerprint},
 * previous_recipient_name.
 */
export const CONSENT_URL = generateUrl('/apps/learning/api/profile/telos/consent')

// ConsentService::SOURCES
const SOURCES = ['dialog', 'onboarding']

const CHANGED_EVENT = 'learning:ai-consent-changed'

export async function fetchConsentStatus() {
	const res = await axios.get(CONSENT_URL)
	return res.data
}

/**
 * Store the consent for the recipient the user was shown. The server binds it to the version and
 * the recipient fingerprint and answers 409 when the admin changed the recipient meanwhile.
 */
export async function saveConsent(recipient, source = 'dialog') {
	if (!recipient || typeof recipient.fingerprint !== 'string' || recipient.fingerprint === '') {
		throw new Error('A consent needs the recipient the user was shown')
	}
	if (!SOURCES.includes(source)) {
		throw new Error('Unknown consent source')
	}
	const res = await axios.post(CONSENT_URL, {
		version: consent.version,
		recipient_fingerprint: recipient.fingerprint,
		source,
	})
	return res.data
}

export async function revokeConsent() {
	const res = await axios.delete(CONSENT_URL)
	return res.data
}

/**
 * True when an AI call was refused for lack of a valid consent: 403 consent_required from every
 * AI endpoint, 409 recipient_changed, or a 200 body that carries consent_required (narrative).
 * Accepts an axios error or an axios response.
 */
export function isConsentRefusal(errorOrResponse) {
	if (!errorOrResponse) return false
	const response = errorOrResponse.response || errorOrResponse
	const data = response && response.data
	if (!data || typeof data !== 'object') return false
	if (data.consent_required === true) return true
	return response.status === 409 && (data.error === 'recipient_changed' || data.reason === 'recipient_changed')
}

export function notifyConsentChanged(valid) {
	try {
		window.dispatchEvent(new CustomEvent(CHANGED_EVENT, { detail: { valid: !!valid } }))
	} catch (e) { /* no window (tests, SSR) */ }
}

/** @returns {() => void} unsubscribe */
export function onConsentChanged(callback) {
	const handler = (event) => callback(!!(event.detail && event.detail.valid))
	window.addEventListener(CHANGED_EVENT, handler)
	return () => window.removeEventListener(CHANGED_EVENT, handler)
}
