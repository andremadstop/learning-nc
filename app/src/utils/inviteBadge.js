import { translate as t } from '@nextcloud/l10n'

/**
 * Screen-reader label of the duel-invitation badge on the VirtuProf avatars. It was the German
 * literal `${n} Duel-Einladung(en)` in five avatar components (audit #187, #196). The wording keeps
 * the number after a colon, so it reads right for 1 and for many without plural forms.
 *
 * @param {number} count open invitations
 * @return {string}
 */
export function inviteBadgeLabel(count) {
	return t('learning', 'Duell-Einladungen: {n}', { n: count })
}
