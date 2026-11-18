/**
 * Pure helpers for the course materials page (GitHub #35).
 */

/**
 * The documents endpoint answers with an envelope since GitHub #35 (documents plus what the
 * page needs to stay honest); a plain array is the older answer.
 *
 * @param {*} data - response body of GET /documents
 * @return {{documents: Array, canManageFiles: (boolean|null), pdfTextExtraction: (boolean|null)}}
 */
export function parseDocumentsResponse(data) {
	if (Array.isArray(data)) {
		return { documents: data, canManageFiles: null, pdfTextExtraction: null }
	}
	return {
		documents: Array.isArray(data?.documents) ? data.documents : [],
		canManageFiles: typeof data?.can_manage_files === 'boolean' ? data.can_manage_files : null,
		pdfTextExtraction: typeof data?.pdf_text_extraction === 'boolean' ? data.pdf_text_extraction : null,
	}
}

/**
 * GitHub #90: reasons (DocumentService::STALE_REASONS) meaning the file is not in the linked
 * material folder any more. "Scan folder" and the instructor's × take such an entry out of the
 * list; learners never see one.
 */
export const STALE_REASONS = ['outside_folder', 'missing', 'folder_missing']

/**
 * Whether an entry's file is no longer in the material folder.
 *
 * @param {object} doc - a row of GET /documents
 * @return {boolean}
 */
export function isStaleDocument(doc) {
	return STALE_REASONS.includes(doc?.unavailable_reason)
}
