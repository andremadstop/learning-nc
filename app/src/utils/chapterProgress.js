/**
 * Progress per schedule chapter_ref, from the pools of GET /my-progress.
 *
 * A schedule entry matches a pool by the pool's chapter key (what the schedule editor suggests)
 * or by its name. Pools sharing a key are added up, so a chapter split over two pools shows
 * their combined mastery instead of whichever pool came last.
 *
 * @param {Array<object>} pools - rows with chapter_key, pool_name, mastered, total_questions
 * @return {Object<string, number>} chapter_ref => percent (0-100)
 */
export function buildChapterProgressMap(pools) {
	const totals = {}
	const add = (ref, pool) => {
		if (!ref) return
		const entry = totals[ref] || (totals[ref] = { mastered: 0, total: 0 })
		entry.mastered += Number(pool.mastered) || 0
		entry.total += Number(pool.total_questions) || 0
	}
	for (const pool of pools || []) {
		add(pool.chapter_key, pool)
		if (pool.pool_name && pool.pool_name !== pool.chapter_key) {
			add(pool.pool_name, pool)
		}
	}
	const map = {}
	for (const [ref, { mastered, total }] of Object.entries(totals)) {
		if (total > 0) {
			map[ref] = Math.round(mastered / total * 100)
		}
	}
	return map
}
