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
