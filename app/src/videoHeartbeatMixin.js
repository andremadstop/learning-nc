/**
 * videoHeartbeatMixin — the watch-progress reporting shared by the native player (VideoPlayer) and
 * the third-party player (VideoConsentOverlay, Vimeo / YouTube after consent).
 *
 * The SERVER decides completion (VideoProgressService: merged intervals ≥ 95 %, pings < 5 s apart
 * dropped, each ping's length capped to the real time since the previous one). This mixin only
 * collects the seconds that were actually played, sends them as consecutive chunks no closer than
 * the server accepts, and asks the server to re-decide completion at the end.
 *
 * The host component provides:
 *   - props `contentId` (registry id), `initialCoveredPct`, `initialCompleted`
 *   - data `currentTime` (last known position) and computed `effectiveDuration`
 *   - method `playbackPosition()`: the player's current position in seconds, or null when the
 *     player is gone
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { addSegment, coveredFraction, shouldFlushHeartbeat } from './utils/videoInterval.js'

// The server drops a ping that arrives less than 5 s (integer server seconds) after the last
// accepted one, without moving its baseline. The client only sends when it is sure the ping is
// accepted, with a margin for latency; anything newer stays pending for the next ping.
export const MIN_ACCEPTED_PING_GAP_MS = 6000
// Upper bound for the pending chunks drained before asking the server to complete.
export const MAX_DRAIN_PINGS = 20

export default {
	data() {
		return {
			intervals: [],
			segmentStart: null,
			// Watch time not yet sent to the server, and how far the open segment is already queued.
			pendingSegments: [],
			segmentQueuedUpTo: null,
			deferredFlush: null,
			lastFlushMs: null,
			coveredPct: this.initialCoveredPct || 0,
			isComplete: this.initialCompleted === true,
			srStatus: '',
			completeSent: false,
		}
	},

	beforeUnmount() {
		this.closeOpenSegment()
		// If the last ping was under 6 s ago this schedules one deferred ping; it still delivers the
		// final seconds after the player is gone (its progress emit is then a no-op).
		this.flushHeartbeat(true)
	},

	methods: {
		openSegment() {
			const position = this.playbackPosition()
			this.segmentStart = position !== null ? position : this.currentTime
			this.segmentQueuedUpTo = null
		},
		closeOpenSegment() {
			const position = this.playbackPosition()
			if (this.segmentStart !== null && position !== null) {
				this.recordSegment(this.segmentStart, position, true)
			}
			this.segmentStart = null
			this.segmentQueuedUpTo = null
		},
		recordSegment(start, end, closing) {
			this.intervals = addSegment(this.intervals, start, end)
			this.coveredPct = coveredFraction(this.intervals, this.effectiveDuration)
			// Queue only the part of the segment that is new since the last call. The server caps a
			// ping's length from its START to the real time since the previous ping, so the client must
			// send what was played since then, not the merged [0,t] (audit 2026-11).
			const from = this.segmentQueuedUpTo !== null ? Math.max(start, this.segmentQueuedUpTo) : start
			if (end > from) {
				this.pendingSegments = addSegment(this.pendingSegments, from, end)
				this.segmentQueuedUpTo = end
			}
			if (closing) {
				this.segmentStart = null
				this.segmentQueuedUpTo = null
			}
		},
		nextSendableChunk() {
			return this.pendingSegments.find(seg => Math.floor(seg.end) > Math.floor(seg.start)) || null
		},
		flushHeartbeat(force) {
			const now = Date.now()
			if (!force && !shouldFlushHeartbeat(this.lastFlushMs, now)) {
				return Promise.resolve()
			}
			if (this.deferredFlush) {
				return this.deferredFlush.promise
			}
			if (!this.nextSendableChunk()) {
				return Promise.resolve()
			}
			const sinceLast = this.lastFlushMs === null ? Infinity : now - this.lastFlushMs
			if (sinceLast < MIN_ACCEPTED_PING_GAP_MS) {
				// A ping now would be dropped by the server. Keep the seconds pending and send them
				// as soon as a ping is accepted again (pause / seek / end right after a heartbeat).
				const deferred = {}
				deferred.promise = new Promise((resolve) => {
					deferred.timer = setTimeout(() => {
						this.deferredFlush = null
						resolve(this.sendNextChunk())
					}, MIN_ACCEPTED_PING_GAP_MS - sinceLast)
				})
				this.deferredFlush = deferred
				return deferred.promise
			}
			return this.sendNextChunk()
		},
		sendNextChunk() {
			const chunk = this.nextSendableChunk()
			if (!chunk) {
				return Promise.resolve()
			}
			this.pendingSegments = this.pendingSegments.filter(seg => seg !== chunk)
			this.lastFlushMs = Date.now()
			return this.postHeartbeat(chunk.start, chunk.end)
		},
		async drainHeartbeats() {
			for (let i = 0; i < MAX_DRAIN_PINGS && this.nextSendableChunk(); i++) {
				await this.flushHeartbeat(true)
			}
		},
		async postHeartbeat(start, end) {
			try {
				const response = await axios.post(
					generateUrl('/apps/learning/api/video-progress/heartbeat'),
					{ contentId: this.contentId, start: Math.floor(start), end: Math.floor(end) },
				)
				this.applyServerProgress(response.data)
			} catch (e) {
				// Heartbeats are best-effort; the server is the gate. Keep the seconds for the next ping.
				this.pendingSegments = addSegment(this.pendingSegments, start, end)
			}
		},
		async requestCompletion() {
			if (this.completeSent) {
				return
			}
			this.completeSent = true
			try {
				const response = await axios.post(
					generateUrl('/apps/learning/api/video-progress/complete'),
					{ contentId: this.contentId },
				)
				this.applyServerProgress(response.data)
			} catch (e) {
				this.completeSent = false
			}
		},
		applyServerProgress(data) {
			if (!data || typeof data !== 'object') {
				return
			}
			if (typeof data.covered_pct === 'number') {
				this.coveredPct = data.covered_pct
			}
			if (data.completed === true && !this.isComplete) {
				this.isComplete = true
				this.srStatus = t('learning', 'Pflichtvideo abgeschlossen')
				this.$emit('completed', this.contentId)
			}
			this.$emit('progress', { contentId: this.contentId, coveredPct: this.coveredPct, completed: this.isComplete })
		},
	},
}
