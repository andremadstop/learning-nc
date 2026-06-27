/**
 * Phase 155 (Plan 06): pure, framework-free helpers for the student certificate view.
 *
 * The signed credential is a compact VC-JWT (the server returns it verbatim in
 * `credential_json`; there is NO server-side decode). These helpers decode the JWT
 * payload for DISPLAY ONLY — they do NOT verify the signature (cryptographic
 * verification is the public Phase 157 verify route). They are deliberately decoupled
 * from Vue / @nextcloud so they can be unit-tested in `tests/unit` (the project has no
 * component-mounting test harness — logic lives in utils, by convention).
 */

/**
 * UTF-8-safe base64url decode of the JWT payload segment → the OB3 credential object.
 *
 * `atob` alone mojibakes non-ASCII bytes (German umlauts, Arabic) — the course title
 * and recipient name are German-first, so the TextDecoder round-trip is load-bearing.
 *
 * @param {string} jwt - compact VC-JWT (`header.payload.signature`)
 * @return {object|null} the decoded credential object, or null if the JWT is malformed
 */
export function decodeCredential(jwt) {
	if (!jwt || typeof jwt !== 'string') {
		return null
	}
	const parts = jwt.split('.')
	if (parts.length !== 3) {
		return null
	}
	try {
		const b64 = parts[1].replace(/-/g, '+').replace(/_/g, '/')
		const pad = b64.length % 4 === 0 ? '' : '='.repeat(4 - (b64.length % 4))
		const binary = atob(b64 + pad)
		const bytes = Uint8Array.from(binary, (ch) => ch.charCodeAt(0))
		const json = new TextDecoder('utf-8').decode(bytes)
		return JSON.parse(json)
	} catch (e) {
		return null
	}
}

/**
 * Parse `score:<n>; threshold:<n>` out of the OB3 `result.resultDescription` string
 * (the issuance shape from 155-04). Either value may be absent.
 *
 * @param {string} resultDescription
 * @return {{score: (number|null), threshold: (number|null)}}
 */
export function parseResult(resultDescription) {
	const out = { score: null, threshold: null }
	if (typeof resultDescription !== 'string') {
		return out
	}
	const scoreMatch = resultDescription.match(/score:\s*([0-9]+(?:\.[0-9]+)?)/i)
	const thresholdMatch = resultDescription.match(/threshold:\s*([0-9]+(?:\.[0-9]+)?)/i)
	if (scoreMatch) {
		out.score = Number(scoreMatch[1])
	}
	if (thresholdMatch) {
		out.threshold = Number(thresholdMatch[1])
	}
	return out
}

/**
 * Flatten the frozen OB3 credential into the fields the view renders. Every value here
 * is read AS-IS from the signed payload — none of it is re-translated (the signed
 * fields stay frozen; only the viewer chrome is localized live).
 *
 * @param {object|null} credential - decoded OB3 credential (see decodeCredential)
 * @return {object} display fields (empty strings / nulls when absent)
 */
export function extractCertificateFields(credential) {
	const subject = credential?.credentialSubject || {}
	const achievement = subject.achievement || {}
	const issuer = credential?.issuer || {}
	const result = Array.isArray(subject.result) ? subject.result[0] : (subject.result || {})
	const { score, threshold } = parseResult(result?.resultDescription)

	return {
		courseTitle: achievement.name || '',
		courseDescription: achievement.description || '',
		recipientName: subject.name || '',
		narrative: achievement?.criteria?.narrative || '',
		score,
		threshold,
		issuerName: issuer.name || '',
		issuerLogo: issuer?.image?.id || issuer?.image || '',
		issuerId: issuer.id || '',
		validFrom: credential?.validFrom || '',
		validUntil: credential?.validUntil || '',
	}
}

/**
 * Build the PUBLIC verification URL the QR encodes. The verify route itself lands in
 * Phase 157, but its shape is frozen now: `<nc_base>/apps/learning/verify/<verificationId>`.
 *
 * @param {string} baseUrl - the instance origin (e.g. window.location.origin)
 * @param {string} verificationId
 * @return {string} absolute verification URL
 */
export function buildVerifyUrl(baseUrl, verificationId) {
	const trimmed = String(baseUrl || '').replace(/\/+$/, '')
	return `${trimmed}/apps/learning/verify/${verificationId}`
}

/**
 * Build a prefilled LinkedIn "Add to Profile" URL (CERT-13). Client-side only, no backend.
 *
 * @param {object} params
 * @param {string} params.courseTitle - the certification name (signed, frozen)
 * @param {string} params.issuerName - the issuing organization
 * @param {string} params.verificationId - certificate id (LinkedIn certId)
 * @param {string} params.certUrl - the public verification URL
 * @param {string} [params.issueDate] - ISO date string (validFrom); year/month are derived
 * @return {string} a well-formed linkedin.com/profile/add URL
 */
export function buildLinkedInUrl({ courseTitle, issuerName, verificationId, certUrl, issueDate }) {
	const params = new URLSearchParams({
		startTask: 'CERTIFICATION_NAME',
		name: courseTitle || '',
		organizationName: issuerName || '',
		certUrl: certUrl || '',
		certId: verificationId || '',
	})
	const date = issueDate ? new Date(issueDate) : null
	if (date && !Number.isNaN(date.getTime())) {
		params.set('issueYear', String(date.getFullYear()))
		params.set('issueMonth', String(date.getMonth() + 1))
	}
	return `https://www.linkedin.com/profile/add?${params.toString()}`
}
