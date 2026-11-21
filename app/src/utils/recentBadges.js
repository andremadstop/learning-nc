/**
 * GitHub #101: the achievements on "Heute". The badges come from /api/v1/user/state (the same list
 * the achievement gallery in a pool's statistics reads); the server returns them in definition
 * order, so the "most recent first" order is made here.
 *
 * Every unlocked badge counts, archived (legacy) ones too: they are the learner's own and fall to
 * the end because they are old. A badge without a time stays after every dated one.
 */

/** How many achievements "Heute" shows before "show all". */
export const RECENT_BADGE_LIMIT = 6

/**
 * @param {unknown} value earned_at as the server sends it (unix seconds, number or numeric string)
 * @return {number} seconds, 0 when unknown
 */
export function earnedAtSeconds(value) {
	const seconds = Number(value)
	return Number.isFinite(seconds) && seconds > 0 ? seconds : 0
}

/**
 * The unlocked badges, most recently unlocked first; ties by badge id so the order is stable.
 *
 * @param {Array<object>|unknown} badges the badges of /api/v1/user/state
 * @param {number|null} limit at most this many; null for all
 * @return {Array<object>}
 */
export function recentEarnedBadges(badges, limit = RECENT_BADGE_LIMIT) {
	const earned = (Array.isArray(badges) ? badges : [])
		.filter((badge) => badge && badge.earned === true)
		.sort((left, right) => {
			const diff = earnedAtSeconds(right.earned_at) - earnedAtSeconds(left.earned_at)
			if (diff !== 0) {
				return diff
			}
			return String(left.badge_id || '').localeCompare(String(right.badge_id || ''))
		})
	return limit === null ? earned : earned.slice(0, Math.max(0, limit))
}
