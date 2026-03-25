/**
 * hudEngine.js — Pure JS engine for HUD state computation.
 *
 * No DOM, Vue, or side effects. All functions are deterministic and testable.
 */

const DEFAULT_VISIBLE_ITEMS = 6

const REPUTATION_DOMAINS = [
	{
		domain: 'security',
		color: 'var(--lnc-cyan)',
		label: 'Security',
	},
	{
		domain: 'management',
		color: 'var(--lnc-amber)',
		label: 'Management',
	},
	{
		domain: 'team',
		color: 'var(--lnc-green)',
		label: 'Team',
	},
]

/**
 * Return a compact item display model with overflow information.
 *
 * @param {Array<string>} items
 * @param {number} maxVisible
 * @returns {{visible: Array<string>, overflow: number, all: Array<string>}}
 */
export function computeVisibleItems(items, maxVisible = DEFAULT_VISIBLE_ITEMS) {
	const all = Array.isArray(items) ? items.slice() : []
	const safeMaxVisible = Math.max(0, Number(maxVisible) || 0)

	return {
		visible: all.slice(0, safeMaxVisible),
		overflow: Math.max(0, all.length - safeMaxVisible),
		all,
	}
}

/**
 * Backwards-compatible alias used in some Phase 82 notes.
 *
 * @param {Array<string>} items
 * @param {number} maxVisible
 * @returns {{visible: Array<string>, overflow: number, all: Array<string>}}
 */
export function computeItemDisplay(items, maxVisible = DEFAULT_VISIBLE_ITEMS) {
	return computeVisibleItems(items, maxVisible)
}

/**
 * Normalize reputation values into a fixed set of mini-bar descriptors.
 *
 * @param {Record<string, number>} reputation
 * @returns {Array<{domain: string, value: number, color: string, label: string}>}
 */
export function computeReputationBars(reputation) {
	const safeReputation = reputation && typeof reputation === 'object' ? reputation : {}

	return REPUTATION_DOMAINS.map(meta => ({
		...meta,
		value: Number(safeReputation[meta.domain]) || 0,
	}))
}

/**
 * Compute act progress from graph node metadata.
 *
 * @param {{nodes?: Array<{act?: number|string}>}|null|undefined} fullGraph
 * @param {number|string} currentActNumber
 * @returns {{current: number, total: number, actNames: Array<string>}}
 */
export function computeActProgress(fullGraph, currentActNumber) {
	const nodes = Array.isArray(fullGraph?.nodes) ? fullGraph.nodes : []
	const actValues = Array.from(
		new Set(
			nodes
				.map(node => Number(node?.act))
				.filter(value => Number.isFinite(value) && value > 0),
		),
	).sort((a, b) => a - b)

	const normalizedCurrent = Number(currentActNumber) || actValues[0] || 1
	const total = actValues.length || 1

	return {
		current: normalizedCurrent,
		total,
		actNames: (actValues.length ? actValues : [normalizedCurrent]).map(act => `Akt ${act}`),
	}
}

/**
 * Compute a score delta descriptor for simple HUD animations.
 *
 * @param {number} previousScore
 * @param {number} currentScore
 * @returns {{delta: number, direction: 'up'|'down'|'none'}}
 */
export function computeScoreDelta(previousScore, currentScore) {
	const previous = Number(previousScore) || 0
	const current = Number(currentScore) || 0
	const delta = current - previous

	if (delta > 0) {
		return { delta, direction: 'up' }
	}
	if (delta < 0) {
		return { delta, direction: 'down' }
	}

	return { delta: 0, direction: 'none' }
}

/**
 * Compute per-domain reputation changes between two state snapshots.
 *
 * @param {Record<string, number>} previousRep
 * @param {Record<string, number>} currentRep
 * @returns {Record<string, number>}
 */
export function computeReputationDeltas(previousRep, currentRep) {
	const previous = previousRep && typeof previousRep === 'object' ? previousRep : {}
	const current = currentRep && typeof currentRep === 'object' ? currentRep : {}
	const keys = new Set([
		...REPUTATION_DOMAINS.map(meta => meta.domain),
		...Object.keys(previous),
		...Object.keys(current),
	])
	const deltas = {}

	for (const key of keys) {
		deltas[key] = (Number(current[key]) || 0) - (Number(previous[key]) || 0)
	}

	return deltas
}

/**
 * Build the top-level HUD state from graph state and campaign state.
 *
 * @param {object|null|undefined} stateBag
 * @param {object|null|undefined} gameState
 * @returns {{items: {visible: Array<string>, overflow: number, all: Array<string>}, reputation: Array, score: number, act: number, role: string}}
 */
export function computeHudState(stateBag, gameState) {
	const safeStateBag = stateBag && typeof stateBag === 'object' ? stateBag : {}
	const safeGameState = gameState && typeof gameState === 'object' ? gameState : {}

	return {
		items: computeVisibleItems(safeStateBag.items || [], DEFAULT_VISIBLE_ITEMS),
		reputation: computeReputationBars(safeStateBag.reputation || {}),
		score: Number(safeGameState.score) || 0,
		act: Number(safeGameState.act_number ?? safeGameState.act) || 1,
		role: safeGameState.character_class || safeGameState.role || '',
	}
}
