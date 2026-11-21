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

/**
 * GitHub #43: the course's certificate layout with its stamp / signature as data URLs, for the
 * preview in the course settings (instructors of the course only).
 * @param {number} courseId — the course
 * @return {Promise<{intro: string, show: object, signer_name: string, signer_role: string, stamp: (string|null), signature: (string|null)}>}
 */
export async function getCertLayout(courseId) {
	const response = await axios.get(generateUrl(`/apps/learning/api/courses/${courseId}/cert-layout`))
	return response.data
}

/**
 * GitHub #43: take a PNG from the instructor's Nextcloud files as stamp or signature.
 * The server reads it through the instructor's own folder and checks type and size.
 * @param {number} courseId — the course
 * @param {'stamp'|'signature'} kind — which image
 * @param {string} path — path in the user's files, as the file picker returns it
 * @return {Promise<object>} the course's layout with images (same shape as getCertLayout)
 */
export async function importCertLayoutImage(courseId, kind, path) {
	const response = await axios.post(
		generateUrl(`/apps/learning/api/courses/${courseId}/cert-layout/images/${encodeURIComponent(kind)}`),
		{ path },
	)
	return response.data
}

/**
 * GitHub #43: remove the course's stamp or signature. Certificates already issued keep theirs.
 * @param {number} courseId — the course
 * @param {'stamp'|'signature'} kind — which image
 * @return {Promise<object>} the course's layout with images
 */
export async function removeCertLayoutImage(courseId, kind) {
	const response = await axios.delete(
		generateUrl(`/apps/learning/api/courses/${courseId}/cert-layout/images/${encodeURIComponent(kind)}`),
	)
	return response.data
}

/**
 * GitHub #78: the logos of the library the course can use and the one it uses (instructors only).
 * @param {number} courseId — the course
 * @return {Promise<{logos: Array<{id: string, name: string}>, selected: (string|null)}>}
 */
export async function getCertLogos(courseId) {
	const response = await axios.get(generateUrl('/apps/learning/api/courses/{courseId}/cert-layout/logos', { courseId }))
	return response.data
}

/**
 * GitHub #78: a library logo's image for the choice in the course settings (an img src).
 * @param {number} courseId — the course
 * @param {string} logoId — the logo's id
 * @return {string}
 */
export function certLogoImageUrl(courseId, logoId) {
	return generateUrl('/apps/learning/api/courses/{courseId}/cert-layout/logos/{logoId}', { courseId, logoId })
}

/**
 * GitHub #78: use a library logo on the course's certificates; '' = the Theming logo again.
 * @param {number} courseId — the course
 * @param {string} logoId — the logo's id or ''
 * @return {Promise<object>} the course's layout with images (same shape as getCertLayout)
 */
export async function selectCertLogo(courseId, logoId) {
	const response = await axios.put(generateUrl('/apps/learning/api/courses/{courseId}/cert-layout/logo', { courseId }), { logoId })
	return response.data
}

/**
 * GitHub #78: the certificate logo library (administrators only).
 * @return {Promise<{logos: Array<{id: string, name: string, mime: string, size: number, courses: number}>, max: number}>}
 */
export async function listLibraryLogos() {
	const response = await axios.get(generateUrl('/apps/learning/api/settings/admin/cert-logos'))
	return response.data
}

/**
 * GitHub #78: upload a logo into the library. The server checks the content (PNG, JPEG or WebP).
 * @param {string} name — the name instructors choose it by
 * @param {File} file — the chosen file
 * @return {Promise<object>} the library, plus `added` (the new logo's id)
 */
export async function uploadLibraryLogo(name, file) {
	const form = new FormData()
	form.append('name', name)
	form.append('logo', file)
	const response = await axios.post(generateUrl('/apps/learning/api/settings/admin/cert-logos'), form)
	return response.data
}

/**
 * @param {string} id — the logo's id
 * @param {string} name — the new name
 * @param {string} revision — the `revision` of the list the name was changed in (GitHub #102);
 *   a list changed since is refused with 409 library_changed
 * @return {Promise<object>} the library
 */
export async function renameLibraryLogo(id, name, revision) {
	const response = await axios.put(generateUrl('/apps/learning/api/settings/admin/cert-logos/{id}', { id }), { name, revision })
	return response.data
}

/**
 * GitHub #78: delete a library logo. Courses that use it go back to the Theming logo.
 * @param {string} id — the logo's id
 * @param {string} revision — the `revision` of the list the logo was deleted from (GitHub #102),
 *   as a query parameter; a list changed since is refused with 409 library_changed
 * @return {Promise<object>} the library, plus `courses_reset`
 */
export async function deleteLibraryLogo(id, revision) {
	const response = await axios.delete(generateUrl('/apps/learning/api/settings/admin/cert-logos/{id}', { id }), { params: { revision } })
	return response.data
}

/**
 * @param {string} id — the logo's id
 * @return {string} the img src of a library logo in the admin list
 */
export function libraryLogoImageUrl(id) {
	return generateUrl('/apps/learning/api/settings/admin/cert-logos/{id}/image', { id })
}

/**
 * GitHub #93: the backgrounds the course can use (library and built-in), the one it uses and its strength.
 * @param {number} courseId — the course
 * @return {Promise<{backgrounds: Array<{id: string, name: string}>, builtins: string[], selected: string, intensity: number}>}
 */
export async function getCertBackgrounds(courseId) {
	const response = await axios.get(generateUrl('/apps/learning/api/courses/{courseId}/cert-layout/backgrounds', { courseId }))
	return response.data
}

/**
 * GitHub #93: a library background's image for the choice in the course settings (an img src).
 * @param {number} courseId — the course
 * @param {string} backgroundId — the background's id
 * @return {string}
 */
export function certBackgroundImageUrl(courseId, backgroundId) {
	return generateUrl('/apps/learning/api/courses/{courseId}/cert-layout/backgrounds/{backgroundId}', { courseId, backgroundId })
}

/**
 * GitHub #93: use a background on the course's certificates.
 * @param {number} courseId — the course
 * @param {string} backgroundId — a library background's id, 'builtin:<id>', or '' for none
 * @param {number} intensity — 10–100 in steps of 10
 * @return {Promise<object>} the course's layout with images (same shape as getCertLayout)
 */
export async function selectCertBackground(courseId, backgroundId, intensity) {
	const response = await axios.put(generateUrl('/apps/learning/api/courses/{courseId}/cert-layout/background', { courseId }), { backgroundId, intensity })
	return response.data
}

/**
 * GitHub #93: the certificate background library (administrators only).
 * @return {Promise<{backgrounds: Array<{id: string, name: string, mime: string, size: number, courses: number}>, max: number}>}
 */
export async function listLibraryBackgrounds() {
	const response = await axios.get(generateUrl('/apps/learning/api/settings/admin/cert-backgrounds'))
	return response.data
}

/**
 * GitHub #93: upload a background into the library. The server checks the content and re-encodes it.
 * @param {string} name — the name instructors choose it by
 * @param {File} file — the chosen file
 * @return {Promise<object>} the library, plus `added` (the new background's id)
 */
export async function uploadLibraryBackground(name, file) {
	const form = new FormData()
	form.append('name', name)
	form.append('background', file)
	const response = await axios.post(generateUrl('/apps/learning/api/settings/admin/cert-backgrounds'), form)
	return response.data
}

/**
 * @param {string} id — the background's id
 * @param {string} name — the new name
 * @param {string} revision — the `revision` of the list the name was changed in (GitHub #102);
 *   a list changed since is refused with 409 library_changed
 * @return {Promise<object>} the library
 */
export async function renameLibraryBackground(id, name, revision) {
	const response = await axios.put(generateUrl('/apps/learning/api/settings/admin/cert-backgrounds/{id}', { id }), { name, revision })
	return response.data
}

/**
 * GitHub #93: delete a library background. Courses that use it get no background.
 * @param {string} id — the background's id
 * @param {string} revision — the `revision` of the list the background was deleted from (GitHub #102),
 *   as a query parameter; a list changed since is refused with 409 library_changed
 * @return {Promise<object>} the library, plus `courses_reset`
 */
export async function deleteLibraryBackground(id, revision) {
	const response = await axios.delete(generateUrl('/apps/learning/api/settings/admin/cert-backgrounds/{id}', { id }), { params: { revision } })
	return response.data
}

/**
 * @param {string} id — the background's id
 * @return {string} the img src of a library background in the admin list
 */
export function libraryBackgroundImageUrl(id) {
	return generateUrl('/apps/learning/api/settings/admin/cert-backgrounds/{id}/image', { id })
}

/**
 * GitHub #84 / #87: for every student member of the course, whether the instructor can open a new
 * certification cycle and why. Instructors of the course only (404 otherwise).
 * @param {number} courseId — the course
 * @return {Promise<Array<{member_id: number, reason: string|null, new_cycle: {reason: string, since: number}|null, exhausted_pool_ids: number[]}>>}
 */
export async function getCertificationCycles(courseId) {
	const response = await axios.get(
		generateUrl('/apps/learning/api/courses/{courseId}/certification-cycles', { courseId }),
	)
	return Array.isArray(response.data?.members) ? response.data.members : []
}

/**
 * GitHub #84 / #87: open a new certification cycle for one student member (the course member row
 * id). 409 'nothing_to_reset' when the learner is not stuck.
 * @param {number} courseId — the course
 * @param {number} memberId — the course member row
 * @return {Promise<{reason: string, since: number}>}
 */
export async function openCertificationCycle(courseId, memberId) {
	const response = await axios.post(
		generateUrl('/apps/learning/api/courses/{courseId}/members/{memberId}/new-cycle', { courseId, memberId }),
	)
	return response.data
}
