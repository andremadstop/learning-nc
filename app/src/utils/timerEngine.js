/**
 * timerEngine.js — Pure JS helpers for timer countdown state.
 */

const TIMER_PHASE_COLORS = {
	safe: 'var(--lnc-green)',
	warning: 'var(--lnc-amber)',
	danger: 'var(--lnc-danger)',
}

function normalizeTimestampSeconds(value) {
	if (typeof value === 'number' && Number.isFinite(value)) {
		return value
	}

	if (typeof value === 'string' && value.trim()) {
		const asNumber = Number(value)
		if (Number.isFinite(asNumber)) {
			return asNumber
		}

		const parsed = Date.parse(value)
		if (!Number.isNaN(parsed)) {
			return parsed / 1000
		}
	}

	return 0
}

/**
 * Format seconds as M:SS.
 *
 * @param {number} seconds
 * @returns {string}
 */
export function formatTime(seconds) {
	const safeSeconds = Math.max(0, Math.floor(Number(seconds) || 0))
	const minutes = Math.floor(safeSeconds / 60)
	const remainder = safeSeconds % 60

	return `${minutes}:${String(remainder).padStart(2, '0')}`
}

/**
 * Compute countdown state from a server deadline.
 *
 * @param {number|string} deadlineAt
 * @param {number} totalSeconds
 * @param {number} now
 * @returns {{remaining: number, fraction: number, phase: 'safe'|'warning'|'danger', color: string, expired: boolean, lastTenSeconds: boolean, formattedTime: string}}
 */
export function computeTimerState(deadlineAt, totalSeconds, now = Date.now() / 1000) {
	const deadline = normalizeTimestampSeconds(deadlineAt)
	const safeNow = Number(now) || 0
	const safeTotal = Math.max(0, Number(totalSeconds) || 0)
	const remaining = Math.max(0, Math.floor(deadline - safeNow))
	const rawFraction = safeTotal > 0 ? remaining / safeTotal : 0
	const fraction = Math.max(0, Math.min(1, rawFraction))
	let phase = 'danger'

	if (fraction > 0.5) {
		phase = 'safe'
	} else if (fraction > 0.25) {
		phase = 'warning'
	}

	return {
		remaining,
		fraction,
		phase,
		color: TIMER_PHASE_COLORS[phase],
		expired: remaining === 0,
		lastTenSeconds: remaining > 0 && remaining <= 10,
		formattedTime: formatTime(remaining),
	}
}
