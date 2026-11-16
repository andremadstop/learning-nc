import { translate as t } from '@nextcloud/l10n'

/**
 * GitHub #39: the server refuses Training, Leitner and the exam with 403 and gate "mode" when the
 * learner reaches the pool only through courses that switched the mode off. That can happen from
 * the pool window (e.g. a pool opened from the Skill Map), whose tabs do not know the course
 * switches. Returns the message in the reader's language, or null for any other error.
 *
 * @param {unknown} error axios error
 * @return {string|null}
 */
export function modeClosedMessage(error) {
	const response = error?.response
	if (response?.status === 403 && response?.data?.gate === 'mode') {
		return t('learning', 'Dieser Lernmodus ist in deinem Kurs ausgeschaltet.')
	}
	return null
}

/**
 * GitHub #35 (B4): the course locks its exam until the learner completed all required content
 * (403 with gate "video"). The raw server text named a content id in English.
 *
 * @param {unknown} error axios error
 * @return {string|null}
 */
export function requiredContentMessage(error) {
	const response = error?.response
	if (response?.status === 403 && response?.data?.gate === 'video') {
		return t('learning', 'Die Prüfung ist erst offen, wenn du alle Pflichtinhalte abgeschlossen hast. Du findest sie im Lernraum unter „Lernmaterial“.')
	}
	return null
}
