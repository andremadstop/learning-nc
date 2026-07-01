/**
 * videoInterval.js — pure, DOM-free helpers for the client-side video watch tracker
 * (Phase 162, VIDEO-04 client half / VIDEO-05 consent decision).
 *
 * The SERVER is always the source of truth for gate completion (VideoProgressService,
 * 95% merged-interval threshold, <5s ping discard). These helpers only:
 *   - accumulate + merge the [start, end] segments the client has actually played,
 *   - decide WHEN to flush a throttled heartbeat (so we never spam the server), and
 *   - decide whether a third-party embed may load yet (consent gate).
 *
 * Kept as a standalone module (mirrors src/utils/dialogFocus.js) so Vitest can cover
 * the logic without mounting a component or touching a <video> element.
 */

/**
 * Clamp a raw [start, end] pair into a sane, positive-length segment.
 * Returns null for anything that isn't a real forward interval.
 *
 * @param {number} start seconds
 * @param {number} end seconds
 * @return {?{start: number, end: number}}
 */
export function normalizeSegment(start, end) {
	const s = Number(start)
	const e = Number(end)
	if (!Number.isFinite(s) || !Number.isFinite(e)) {
		return null
	}
	const lo = Math.max(0, Math.min(s, e))
	const hi = Math.max(0, Math.max(s, e))
	if (hi - lo <= 0) {
		return null
	}
	return { start: lo, end: hi }
}

/**
 * Merge a list of [start, end] segments into a minimal set of non-overlapping,
 * ascending intervals. Touching intervals ([0,5] + [5,9]) are coalesced.
 *
 * @param {Array<{start: number, end: number}>} segments
 * @return {Array<{start: number, end: number}>}
 */
export function mergeIntervals(segments) {
	const clean = (Array.isArray(segments) ? segments : [])
		.map((seg) => (seg ? normalizeSegment(seg.start, seg.end) : null))
		.filter((seg) => seg !== null)
		.sort((a, b) => a.start - b.start)

	const merged = []
	for (const seg of clean) {
		const last = merged[merged.length - 1]
		if (last && seg.start <= last.end) {
			last.end = Math.max(last.end, seg.end)
		} else {
			merged.push({ start: seg.start, end: seg.end })
		}
	}
	return merged
}

/**
 * Add one segment to an existing merged interval list and re-merge.
 * Pure: returns a new array, does not mutate the input.
 *
 * @param {Array<{start: number, end: number}>} intervals already-merged
 * @param {number} start seconds
 * @param {number} end seconds
 * @return {Array<{start: number, end: number}>}
 */
export function addSegment(intervals, start, end) {
	const seg = normalizeSegment(start, end)
	const base = Array.isArray(intervals) ? intervals : []
	if (seg === null) {
		return mergeIntervals(base)
	}
	return mergeIntervals([...base, seg])
}

/**
 * Total covered seconds across a merged (or unmerged) interval list.
 *
 * @param {Array<{start: number, end: number}>} intervals
 * @return {number}
 */
export function coveredSeconds(intervals) {
	return mergeIntervals(intervals).reduce((sum, seg) => sum + (seg.end - seg.start), 0)
}

/**
 * Fraction [0, 1] of the video the client has covered. Guards a null/0 duration
 * (returns 0 — the gate can never open on a mis-configured duration, matching the
 * server's isDurationValidForGate rule).
 *
 * @param {Array<{start: number, end: number}>} intervals
 * @param {?number} durationSeconds
 * @return {number}
 */
export function coveredFraction(intervals, durationSeconds) {
	const d = Number(durationSeconds)
	if (!Number.isFinite(d) || d <= 0) {
		return 0
	}
	return Math.min(1, coveredSeconds(intervals) / d)
}

/**
 * Throttle decision for heartbeat POSTs. Returns true only when enough wall-clock
 * time has passed since the last flush. The server independently discards pings
 * <5s apart, so this client throttle (default 10s) is purely noise reduction.
 *
 * A null/undefined lastSentMs (first ping) always flushes.
 *
 * @param {?number} lastSentMs epoch ms of the previous flush, or null
 * @param {number} nowMs current epoch ms
 * @param {number} [minGapMs=10000] minimum gap between flushes
 * @return {boolean}
 */
export function shouldFlushHeartbeat(lastSentMs, nowMs, minGapMs = 10000) {
	const now = Number(nowMs)
	if (!Number.isFinite(now)) {
		return false
	}
	if (lastSentMs === null || lastSentMs === undefined || !Number.isFinite(Number(lastSentMs))) {
		return true
	}
	return now - Number(lastSentMs) >= minGapMs
}

/**
 * Source types that require DSGVO Art.13 consent before ANY third-party asset loads.
 * nc-files streams from the app's own Nextcloud → no consent needed.
 */
export const CONSENT_REQUIRED_SOURCES = ['vimeo', 'youtube-nocookie']

/**
 * Consent-gate decision for the player. Returns:
 *   - 'allowed' for nc-files / document (no third party), always,
 *   - 'blocked' for vimeo / youtube-nocookie until the user has explicitly consented,
 *   - 'allowed' for vimeo / youtube-nocookie once consent is given.
 *
 * The player MUST NOT inject any SDK script or iframe while this returns 'blocked'.
 *
 * @param {string} sourceType
 * @param {boolean} consentGiven
 * @return {'allowed'|'blocked'}
 */
export function consentGateDecision(sourceType, consentGiven) {
	if (CONSENT_REQUIRED_SOURCES.includes(sourceType)) {
		return consentGiven === true ? 'allowed' : 'blocked'
	}
	return 'allowed'
}

/**
 * A valid, empty WebVTT subtitle track as a data: URI. Browsers ignore a <track>
 * with an empty/absent src, so when a course video ships no captions we still
 * mount a *real* (empty) track — the element is present and WCAG-inspectable.
 */
export const EMPTY_VTT_DATA_URI = 'data:text/vtt;charset=utf-8,WEBVTT%0A%0A'
