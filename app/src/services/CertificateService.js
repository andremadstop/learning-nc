import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * API client for the student's own issued certificates (Phase 155).
 *
 * Thin axios wrapper over the authenticated, owner-scoped CertificateController.
 * Consumed by the student certificate UI (155-06). No business logic here.
 */

/**
 * List the current user's issued certificates (newest first).
 * Server enforces ownership — only the authenticated user's certificates are returned.
 * @return {Promise<Array<object>>} certificate rows (verification_id, course_id, issued_at, expires_at, revoked, …)
 */
export async function listCertificates() {
	const response = await axios.get(generateUrl('/apps/learning/api/certificates'))
	return response.data
}

/**
 * Fetch a single owned certificate by its verification id.
 * Returns 403 if it belongs to another user, 404 if it does not exist (IDOR guard server-side).
 * @param {string} verificationId — the certificate's public verification id
 * @return {Promise<object>} the certificate row
 */
export async function getCertificate(verificationId) {
	const response = await axios.get(
		generateUrl(`/apps/learning/api/certificates/${verificationId}`),
	)
	return response.data
}

/**
 * Build the download URL for an owned certificate (used as a plain link/href by the 155-06 button).
 * Default 'jsonld' → an Open Badges 3.0 JSON-LD EnvelopedVerifiableCredential file (.json, CERT-09);
 * pass 'jwt' for the raw compact VC-JWT.
 * @param {string} verificationId — the certificate's public verification id
 * @param {string} [format='jsonld'] — 'jsonld' (default) or 'jwt'
 * @return {string} the generated download URL
 */
export function downloadUrl(verificationId, format = 'jsonld') {
	const base = generateUrl(`/apps/learning/api/certificates/${verificationId}/download`)
	return format === 'jwt' ? `${base}?format=jwt` : base
}
