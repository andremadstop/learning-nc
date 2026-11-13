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
