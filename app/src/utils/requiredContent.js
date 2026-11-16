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
 * Fresh-eyes C5: the practising modes a gated course keeps open while its exam waits for the
 * required content. The exam lock note and the instructor's gate hint name only these; a course
 * with Leitner off said "Training and Leitner are already open to you". Same rule as the learner
 * tabs (lernraumTabIds): a mode is on unless mode_config switches it off.
 *
 * @param {object|null|undefined} course - the course detail (snake_case fields)
 * @return {{training: boolean, leitner: boolean}}
 */
export function practiseModesOpen(course) {
	const modeConfig = course?.mode_config || {}
	return { training: modeConfig.training !== false, leitner: modeConfig.leitner !== false }
}

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

/** The id formats the server accepts (CourseVideoController::EMBED_ID_PATTERNS). */
export const EMBED_ID_PATTERNS = {
	'youtube-nocookie': /^[A-Za-z0-9_-]{11}$/,
	vimeo: /^\d{1,12}$/,
}

/**
 * The provider's video id from what the instructor entered: the id itself or a pasted address
 * (youtu.be/ID, youtube.com/watch?v=ID, /embed/ID, /shorts/ID, vimeo.com/ID, player.vimeo.com/video/ID).
 * The players insert the id into the embed address, a whole URL would load no player.
 * Other source types keep their reference unchanged.
 *
 * @param {string} sourceType - source type of the row
 * @param {*} input - the entered reference
 * @return {string|null} the id, or null when an embed reference holds no valid id
 */
export function providerVideoId(sourceType, input) {
	const ref = String(input ?? '').trim()
	const pattern = EMBED_ID_PATTERNS[sourceType]
	if (!pattern) {
		return ref
	}
	if (pattern.test(ref)) {
		return ref
	}
	let url
	try {
		url = new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(ref) ? ref : `https://${ref}`)
	} catch (e) {
		return null
	}
	const host = url.hostname.toLowerCase().replace(/^(www\.|m\.)/, '')
	const parts = url.pathname.split('/').filter(Boolean)
	let candidate = null
	if (sourceType === 'youtube-nocookie') {
		if (host === 'youtu.be') {
			candidate = parts[0]
		} else if (['youtube.com', 'youtube-nocookie.com'].includes(host)) {
			candidate = parts[0] === 'watch'
				? url.searchParams.get('v')
				: (['embed', 'shorts', 'live', 'v'].includes(parts[0]) ? parts[1] : null)
		}
	} else if (host === 'vimeo.com') {
		candidate = parts.find((part) => pattern.test(part))
	} else if (host === 'player.vimeo.com' && parts[0] === 'video') {
		candidate = parts[1]
	}
	return candidate && pattern.test(candidate) ? candidate : null
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
		videoRef: providerVideoId(form.sourceType, form.ref) ?? '',
		title: String(form.title || '').trim(),
		durationSeconds: durationFromFields(form.minutes, form.seconds),
		sortOrder,
	}
}

/**
 * Whether the video form can be sent: a known video type, a reference (for an embed one that holds
 * a valid provider id) and a positive duration
 * (the server refuses a gated video without one, it could never be completed).
 *
 * @param {object} form - {sourceType, ref, minutes, seconds}
 * @return {boolean}
 */
export function videoFormComplete(form) {
	return VIDEO_SOURCE_TYPES.includes(form?.sourceType)
		&& String(form?.ref || '').trim() !== ''
		&& providerVideoId(form?.sourceType, form?.ref) !== null
		&& durationFromFields(form?.minutes, form?.seconds) !== null
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

/**
 * GitHub #75: the material document ids a course requires (registry rows of GET /videos).
 *
 * @param {Array} items - registry rows
 * @return {Set<number>}
 */
export function requiredDocumentIds(items) {
	const ids = new Set()
	for (const item of items || []) {
		if (item?.source_type !== 'document') {
			continue
		}
		const id = documentIdFromRef(item.document_id ?? item.video_ref)
		if (id !== null) {
			ids.add(id)
		}
	}
	return ids
}

/**
 * GitHub #75: the registry row of a material document, or null.
 *
 * @param {Array} items - registry rows
 * @param {*} documentId - course_documents id
 * @return {object|null}
 */
export function registryRowForDocument(items, documentId) {
	const wanted = documentIdFromRef(documentId)
	if (wanted === null) {
		return null
	}
	return (items || []).find((item) => item?.source_type === 'document'
		&& documentIdFromRef(item.document_id ?? item.video_ref) === wanted) || null
}

/**
 * GitHub #75: the state of the "Required" header checkbox over the documents that can be ticked
 * (the ones members can open). 'all' unticks all, anything else ticks all.
 *
 * @param {Array} documents - rows of GET /documents
 * @param {Set<number>} requiredIds - requiredDocumentIds()
 * @return {'all'|'some'|'none'}
 */
export function requiredHeaderState(documents, requiredIds) {
	const selectable = (documents || []).filter((doc) => doc?.downloadable === true)
	if (selectable.length === 0) {
		return 'none'
	}
	const ticked = selectable.filter((doc) => requiredIds.has(Number(doc.id))).length
	if (ticked === 0) {
		return 'none'
	}
	return ticked === selectable.length ? 'all' : 'some'
}

/**
 * GitHub #75: the learner's line under a required document.
 *
 * @param {object} item - normalized row of GET video-progress/course ({completed, updated, confirmAgain})
 * @return {'done'|'updated'|'again'|'open'}
 */
export function documentConfirmState(item) {
	if (item?.completed === true) {
		return 'done'
	}
	if (item?.updated === true) {
		return 'updated'
	}
	if (item?.confirmAgain === true) {
		return 'again'
	}
	return 'open'
}
