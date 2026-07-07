/**
 * Aufbereitung von Duell-Einladungen für die VirtuProf-Hilfeansicht.
 * Aus VirtuProf.vue extrahiert (Zero-Behavior-Change). Die Übersetzungsfunktion
 * `vt` wird injiziert (vorher `this.vt`).
 */

/**
 * Menschlich lesbares Status-Label (roher i18n-Key, wird vom Aufrufer via vt übersetzt).
 * @param {object} invite
 * @returns {string}
 */
export function inviteStatusLabel(invite) {
	if (invite.can_open_duel) {
		return 'Ready'
	}
	return String(invite.status || 'open')
		.charAt(0).toUpperCase() + String(invite.status || 'open').slice(1)
}

/**
 * Baut die Anzeige-Karte für eine Einladung.
 * @param {object} invite
 * @param {(key: string, params?: object) => string} vt Übersetzungsfunktion
 * @returns {object}
 */
export function mapInviteCard(invite, vt) {
	const isIncoming = invite.direction === 'incoming'
	const partnerUid = isIncoming ? invite.inviter_uid : invite.invitee_uid
	const itemActions = []

	if (invite.can_accept) {
		itemActions.push({ label: vt('Accept'), type: 'accept-invite', inviteId: invite.id })
	}
	if (invite.can_decline) {
		itemActions.push({ label: vt('Decline'), type: 'decline-invite', inviteId: invite.id })
	}
	if (invite.can_cancel) {
		itemActions.push({ label: vt('Cancel invite'), type: 'cancel-invite', inviteId: invite.id })
	}
	if (invite.can_open_duel) {
		itemActions.push({
			label: vt('Open duel'),
			type: 'open-duel',
			courseId: invite.course_id,
			duelCode: invite.duel_code,
		})
	}

	return {
		id: invite.id,
		direction: isIncoming ? 'incoming' : 'outgoing',
		status: invite.status,
		statusLabel: vt(inviteStatusLabel(invite)),
		title: isIncoming
			? vt('Challenge from {user}', { user: partnerUid })
			: vt('Challenge to {user}', { user: partnerUid }),
		subtitle: vt('Pool: {pool}', { pool: invite.pool_name || ('#' + invite.pool_id) }),
		updatedAt: invite.updated_at || invite.created_at,
		message: invite.can_open_duel
			? vt('This duel is ready. Open it from here and play it inside the course duel tab.')
			: isIncoming
				? vt('A classmate challenged you to a duel. Accept it to jump straight into the duel lobby.')
				: vt('Your duel invite is waiting for a response from the opponent.'),
		itemActions,
	}
}
