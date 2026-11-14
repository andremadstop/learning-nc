import { translate as t } from '@nextcloud/l10n'

/**
 * One name per campaign difficulty for the campaign card and the campaign intro. The intro had its
 * own untranslated table without 'intermediate' (the most common value) and called 'advanced'
 * 'Fortgeschritten' while the card said 'Experte' (audit #176).
 *
 * @param {string} difficulty difficulty value of the campaign file
 * @return {string}
 */
export function campaignDifficultyLabel(difficulty) {
	const labels = {
		beginner: t('learning', 'Einsteiger'),
		intermediate: t('learning', 'Fortgeschritten'),
		advanced: t('learning', 'Experte'),
		expert: t('learning', 'Experte'),
	}
	return labels[difficulty] || difficulty
}
