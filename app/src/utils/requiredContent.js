/**
 * GitHub #35 (Lückencheck B4): required content of a course — documents of the material folder
 * that learners confirm as read, and videos they watch. The exam unlocks once all of it is done,
 * when the course switches that on. Pure helpers for the settings and the learner view.
 */
import { generateUrl } from '@nextcloud/router'

/** The source types the server accepts (CourseVideoController::SOURCE_TYPES). */
export const REQUIRED_CONTENT_SOURCE_TYPES = ['nc-files', 'vimeo', 'youtube-nocookie', 'document']

export const VIDEO_SOURCE_TYPES = ['nc-files', 'vimeo', 'youtube-nocookie']

/**
 * The course_documents id a document row stores, or null for any other reference (a legacy path).
 * Mirrors CourseVideoController::documentIdFromRef.
 *
 * @param {*} ref - video_ref of a registry row
 * @return {number|null}
 */
export function documentIdFromRef(ref) {
	const value = String(ref ?? '').trim()
	if (!/^\d{1,18}$/.test(value)) {
		return null
	}
	const id = Number(value)
	return id > 0 ? id : null
}

/**
 * The request body of POST /api/courses/{id}/videos for a material document.
 *
 * @param {object} doc - a row of GET /documents ({id, file_name})
 * @param {string} title - optional title, the file name when empty
 * @return {{sourceType: string, videoRef: string, title: string, durationSeconds: null, sortOrder: number}}
 */
export function documentPayload(doc, title = '', sortOrder = 0) {
	return {
		sourceType: 'document',
		videoRef: String(doc.id),
		title: String(title || '').trim() || String(doc.file_name || ''),
		durationSeconds: null,
		sortOrder,
	}
}

/**
 * The duration of a video in seconds from the two fields of the form, or null when not positive.
 *
 * @param {*} minutes - minutes field
 * @param {*} seconds - seconds field
 * @return {number|null}
 */
export function durationFromFields(minutes, seconds) {
	const m = Math.max(0, Math.floor(Number(minutes) || 0))
	const s = Math.max(0, Math.floor(Number(seconds) || 0))
	const total = m * 60 + s
	return total > 0 ? total : null
}

/**
 * The request body of POST /api/courses/{id}/videos for a video.
 *
 * @param {object} form - {sourceType, ref, title, minutes, seconds}
 * @return {{sourceType: string, videoRef: string, title: string, durationSeconds: (number|null), sortOrder: number}}
 */
export function videoPayload(form, sortOrder = 0) {
	return {
		sourceType: form.sourceType,
		videoRef: String(form.ref || '').trim(),
		title: String(form.title || '').trim(),
		durationSeconds: durationFromFields(form.minutes, form.seconds),
		sortOrder,
	}
}

/**
 * Whether the video form can be sent: a known video type, a reference and a positive duration
 * (the server refuses a gated video without one, it could never be completed).
 *
 * @param {object} form - {sourceType, ref, minutes, seconds}
 * @return {boolean}
 */
export function videoFormComplete(form) {
	return VIDEO_SOURCE_TYPES.includes(form?.sourceType)
		&& String(form?.ref || '').trim() !== ''
		&& durationFromFields(form?.minutes, form?.seconds) !== null
}

/**
 * Material documents that can still be added: servable (in the current material folder) and not
 * already required.
 *
 * @param {Array} documents - rows of GET /documents
 * @param {Array} items - registry rows of GET /videos
 * @return {Array}
 */
export function addableDocuments(documents, items) {
	const taken = new Set()
	for (const item of items || []) {
		if (item?.source_type === 'document') {
			const id = documentIdFromRef(item.video_ref)
			if (id !== null) {
				taken.add(id)
			}
		}
	}
	return (documents || []).filter((doc) => doc && doc.downloadable === true && !taken.has(Number(doc.id)))
}

/**
 * The link a learner opens a required document with: the material download, or null when the
 * server sent no servable document id.
 *
 * @param {number} courseId - course id
 * @param {*} documentId - document_id of GET video-progress/course
 * @return {string|null}
 */
export function requiredDocumentUrl(courseId, documentId) {
	const id = documentIdFromRef(documentId)
	if (id === null) {
		return null
	}
	return generateUrl('/apps/learning/api/courses/{courseId}/documents/{documentId}/download', {
		courseId,
		documentId: id,
	})
}

/**
 * "confirmed/total" for the coverage list, a dash for a course without required content.
 *
 * @param {*} confirmed - required_content_confirmed
 * @param {*} total - required_content_total
 * @return {string}
 */
export function formatContentProgress(confirmed, total) {
	const all = Number(total) || 0
	if (all <= 0) {
		return '—'
	}
	return `${Math.min(all, Number(confirmed) || 0)}/${all}`
}
