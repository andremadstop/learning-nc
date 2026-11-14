/**
 * JS port of the VideoProgressService rules a heartbeat meets on the server (isPlausiblePing ≥5 s,
 * first-ping cap min(15, 0.5·d), capInterval, mergeIntervals, coveredPct, 95 % threshold; integer
 * server seconds). Shared by the native player and the third-party player tests, which check the
 * OUTCOME: genuine watching completes, skipping does not.
 */
export function makeServer(duration) {
	const s = { intervals: [], lastPing: null, completed: false, pings: [] }
	const pct = () => Math.min(1, s.intervals.reduce((sum, [a, b]) => sum + (b - a), 0) / duration)
	const merge = (iv) => {
		const all = [...s.intervals, iv].sort((x, y) => x[0] - y[0])
		const out = []
		for (const [a, b] of all) {
			const last = out[out.length - 1]
			if (last && a <= last[1]) last[1] = Math.max(last[1], b)
			else out.push([a, b])
		}
		s.intervals = out
	}
	s.state = () => ({ covered_pct: s.completed ? 1 : pct(), completed: s.completed })
	s.heartbeat = (start, end) => {
		const now = Math.floor(Date.now() / 1000)
		s.pings.push([start, end])
		if (s.completed) return s.state()
		const st = Math.max(0, Number(start))
		if (!(Number(end) > st)) return s.state() // sanitizeInterval
		// isPlausiblePing: dropped, and the server says so (audit #111)
		if (s.lastPing !== null && now - s.lastPing < 5) return { ...s.state(), accepted: false }
		const first = s.lastPing === null
		const maxCredit = first ? Math.min(15, duration * 0.5) : Math.max(0, now - s.lastPing) + 1
		let e = Math.min(Number(end), duration)
		if (e > st && maxCredit > 0) {
			if (e - st > maxCredit) e = st + maxCredit
			merge([st, e])
		}
		s.lastPing = now
		if (!first && pct() >= 0.95) s.completed = true
		return s.state()
	}
	s.complete = () => {
		if (!s.completed && pct() >= 0.95) s.completed = true
		return s.state()
	}
	return s
}
