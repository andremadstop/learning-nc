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
 * Returns a uniform 404 if it does not exist OR belongs to another user (IDOR guard server-side).
 * @param {string} verificationId — the certificate's public verification id
 * @return {Promise<object>} the certificate row
 */
export async function getCertificate(verificationId) {
	const response = await axios.get(
		generateUrl(`/apps/learning/api/certificates/${encodeURIComponent(verificationId)}`),
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
	const base = generateUrl(`/apps/learning/api/certificates/${encodeURIComponent(verificationId)}/download`)
	return format === 'jwt' ? `${base}?format=jwt` : base
}

/**
 * GitHub #42: one certificate of a course for its instructor (compliance report → View).
 * Same row shape as getCertificate(), plus revoked / revoked_at / expired. The server lets only
 * an instructor of THIS course through (403 otherwise, 404 for a certificate of another course).
 * @param {number} courseId — the course whose report lists the certificate
 * @param {string} verificationId — the certificate's public verification id
 * @return {Promise<object>} the certificate row
 */
export async function getCourseCertificate(courseId, verificationId) {
	const response = await axios.get(
		generateUrl('/apps/learning/api/courses/{courseId}/certificates/{verificationId}', { courseId, verificationId }),
	)
	return response.data
}

/**
 * GitHub #42: the learner's certificate file for the course instructor (same file as downloadUrl()).
 * @param {number} courseId — the course whose report lists the certificate
 * @param {string} verificationId — the certificate's public verification id
 * @param {string} [format='jsonld'] — 'jsonld' (default) or 'jwt'
 * @return {string} the generated download URL
 */
export function courseDownloadUrl(courseId, verificationId, format = 'jsonld') {
	const base = generateUrl('/apps/learning/api/courses/{courseId}/certificates/{verificationId}/download', { courseId, verificationId })
	return format === 'jwt' ? `${base}?format=jwt` : base
}

/**
 * GitHub #42: every certificate of the filtered compliance report as one ZIP.
 * @param {number} courseId — the course
 * @param {string} [query=''] — the report's filter query (buildCertReportQuery), same as the CSV
 * @return {string} the generated download URL
 */
export function certReportZipUrl(courseId, query = '') {
	const base = generateUrl('/apps/learning/api/courses/{courseId}/cert-report/export/zip', { courseId })
	return base + (query ? '?' + query : '')
}
