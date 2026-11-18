/**
 * GitHub #84 / #87: what the instructor's member list says about a learner's certification cycle,
 * from one row of GET /api/courses/{id}/certification-cycles.
 *
 * - 'revoked' / 'expired' / 'attempts': the learner is stuck, a new cycle can be opened (reason);
 * - 'cycle_open': a cycle an instructor opened is open, nothing to do;
 * - null: nothing to show.
 *
 * @param {{reason: ?string, new_cycle: ?object, exhausted_pool_ids: ?number[]}|undefined} state the row
 * @return {?string}
 */
export function memberCycleStatus(state) {
	if (!state) {
		return null
	}
	if (state.reason === 'revoked' || state.reason === 'expired' || state.reason === 'attempts') {
		return state.reason
	}
	return state.new_cycle ? 'cycle_open' : null
}

/**
 * GitHub #87: attempts left on a pool for the learner, from the attempt_policy of the pass status
 * or the exam result (total_limit, used_by_pool). null = no total limit.
 *
 * @param {object|null|undefined} policy attempt_policy
 * @param {number} poolId the pool
 * @return {?number}
 */
export function attemptsLeft(policy, poolId) {
	const limit = Number(policy?.total_limit)
	if (!policy || !Number.isInteger(limit) || limit <= 0) {
		return null
	}
	const used = Number(policy.used_by_pool?.[poolId] ?? policy.used_by_pool?.[String(poolId)] ?? 0)
	return Math.max(0, limit - (Number.isFinite(used) ? used : 0))
}
