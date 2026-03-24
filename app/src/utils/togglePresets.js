/**
 * Toggle presets for SubnetCalculator result rows.
 *
 * Each preset defines which calculator rows are visible,
 * enabling progressive disclosure for learners at different levels.
 */

export const ROW_KEYS = [
	'network',
	'broadcast',
	'firstHost',
	'lastHost',
	'hostCount',
	'mask',
	'wildcard',
	'cidr',
	'ipClass',
	'isPrivate',
]

export const PRESETS = {
	all: [...ROW_KEYS],
	beginner: ['network', 'mask', 'cidr', 'hostCount'],
	advanced: ['network', 'broadcast', 'firstHost', 'lastHost', 'hostCount', 'mask', 'cidr'],
	basics: ['network', 'broadcast', 'mask', 'cidr', 'hostCount'],
}

/**
 * Returns a visibility map for all ROW_KEYS based on the given preset.
 * Unknown or undefined presets default to 'all'.
 *
 * @param {string} [presetName] - One of 'all', 'beginner', 'advanced', 'basics'
 * @returns {Record<string, boolean>} Object with ROW_KEYS as keys, boolean visibility
 */
export function getVisibleRows(presetName) {
	const visibleKeys = PRESETS[presetName] || PRESETS.all
	const result = {}
	for (const key of ROW_KEYS) {
		result[key] = visibleKeys.includes(key)
	}
	return result
}
