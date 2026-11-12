<template>
	<div class="video-player" :class="{ 'video-player--complete': isComplete }">
		<!--
			WCAG 2.1 AA native <video> player (VIDEO-08). No autoplay; custom keyboard-
			operable controls; a persistent aria-live region; an always-present WebVTT
			subtitle track (empty data-URI track when the course ships no captions).
			The <video> keeps its own native controls OFF — our custom controls carry
			consistent aria labels + a visible focus ring at AA contrast.
		-->
		<video
			ref="video"
			class="video-player__media"
			preload="metadata"
			:poster="poster || undefined"
			:aria-label="mediaLabel"
			@loadedmetadata="onLoadedMetadata"
			@timeupdate="onTimeUpdate"
			@play="onPlay"
			@pause="onPause"
			@seeking="onSeeking"
			@seeked="onSeeked"
			@ended="onEnded">
			<source :src="streamUrl" type="video/mp4">
			<track
				kind="subtitles"
				srclang="de"
				:label="t('learning', 'Untertitel')"
				:src="subtitleSrc"
				default>
		</video>

		<!-- Persistent live region: ALWAYS in the DOM; only its text changes so screen readers reliably announce play/pause state transitions. -->
		<p class="video-player__sr-status" aria-live="polite" role="status">
			{{ srStatus }}
		</p>

		<div class="video-player__controls">
			<button
				ref="playButton"
				type="button"
				class="video-player__btn video-player__btn--play"
				:aria-label="isPlaying ? t('learning', 'Pause') : t('learning', 'Abspielen')"
				:aria-pressed="isPlaying ? 'true' : 'false'"
				@click="togglePlay">
				<span aria-hidden="true">{{ isPlaying ? '❚❚' : '▶' }}</span>
			</button>

			<input
				ref="seek"
				type="range"
				class="video-player__seek"
				min="0"
				:max="durationForSeek"
				step="1"
				:value="currentTime"
				:aria-label="t('learning', 'Suchleiste')"
				:aria-valuetext="seekValueText"
				@input="onSeekInput"
				@keydown="onSeekKeydown">

			<span class="video-player__time" aria-hidden="true">
				{{ formatTime(currentTime) }} / {{ formatTime(effectiveDuration) }}
			</span>
		</div>

		<div class="video-player__gate" :class="gateClass">
			<span class="video-player__gate-icon" aria-hidden="true">{{ isComplete ? '✓' : '●' }}</span>
			<span class="video-player__gate-label">{{ gateLabel }}</span>
		</div>

		<p class="video-player__hint">
			{{ t('learning', 'Tastatur: Leertaste = Abspielen/Pause, Pfeil links/rechts = 5 Sekunden springen.') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	addSegment,
	coveredFraction,
	EMPTY_VTT_DATA_URI,
	shouldFlushHeartbeat,
} from '../utils/videoInterval.js'

const SEEK_STEP_SECONDS = 5
// The server drops a ping that arrives less than 5 s (integer server seconds) after the last
// accepted one, without moving its baseline. The client only sends when it is sure the ping is
// accepted, with a margin for latency; anything newer stays pending for the next ping.
const MIN_ACCEPTED_PING_GAP_MS = 6000
// Upper bound for the pending chunks drained before asking the server to complete.
const MAX_DRAIN_PINGS = 20

export default {
	name: 'VideoPlayer',

	props: {
		// Registry content id — also the stream + heartbeat key.
		contentId: {
			type: Number,
			required: true,
		},
		durationSeconds: {
			type: Number,
			default: 0,
		},
		// Optional pre-known progress from the server (covered fraction 0..1).
		initialCoveredPct: {
			type: Number,
			default: 0,
		},
		initialCompleted: {
			type: Boolean,
			default: false,
		},
		subtitleRef: {
			type: String,
			default: '',
		},
		poster: {
			type: String,
			default: '',
		},
		title: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			isPlaying: false,
			currentTime: 0,
			mediaDuration: 0,
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

	computed: {
		streamUrl() {
			return generateUrl('/apps/learning/api/video-stream/{contentId}', { contentId: this.contentId })
		},
		subtitleSrc() {
			// A <track> with an empty src is ignored by browsers — always mount a
			// valid (possibly empty) WebVTT track so the element is present + WCAG-inspectable.
			return this.subtitleRef && this.subtitleRef.length > 0 ? this.subtitleRef : EMPTY_VTT_DATA_URI
		},
		effectiveDuration() {
			return this.mediaDuration > 0 ? this.mediaDuration : (this.durationSeconds || 0)
		},
		durationForSeek() {
			return Math.max(1, Math.floor(this.effectiveDuration))
		},
		mediaLabel() {
			return this.title
				? t('learning', 'Video: {title}', { title: this.title })
				: t('learning', 'Kursvideo')
		},
		seekValueText() {
			return t('learning', '{current} von {total}', {
				current: this.formatTime(this.currentTime),
				total: this.formatTime(this.effectiveDuration),
			})
		},
		gateClass() {
			return this.isComplete ? 'video-player__gate--complete' : 'video-player__gate--pending'
		},
		gateLabel() {
			if (this.isComplete) {
				return t('learning', 'Abgeschlossen')
			}
			const pct = Math.round((this.coveredPct || 0) * 100)
			return t('learning', 'Angesehen: {pct}%', { pct })
		},
	},

	beforeUnmount() {
		this.closeOpenSegment()
		// If the last ping was under 6 s ago this schedules one deferred ping; it still delivers the
		// final seconds after the player is gone (its progress emit is then a no-op).
		this.flushHeartbeat(true)
	},

	methods: {
		onLoadedMetadata() {
			const el = this.$refs.video
			if (el && Number.isFinite(el.duration)) {
				this.mediaDuration = el.duration
			}
		},
		togglePlay() {
			const el = this.$refs.video
			if (!el) {
				return
			}
			if (el.paused) {
				el.play()
			} else {
				el.pause()
			}
		},
		onPlay() {
			this.isPlaying = true
			this.srStatus = t('learning', 'Wiedergabe läuft')
			this.openSegment()
		},
		onPause() {
			this.isPlaying = false
			this.srStatus = t('learning', 'Pausiert')
			this.closeOpenSegment()
			this.flushHeartbeat(true)
		},
		onTimeUpdate() {
			const el = this.$refs.video
			if (!el) {
				return
			}
			this.currentTime = el.currentTime
			// extend the open segment and flush on the client throttle
			if (this.segmentStart !== null) {
				this.recordSegment(this.segmentStart, el.currentTime, false)
			}
			this.flushHeartbeat(false)
		},
		onSeeking() {
			// Close AND flush the segment we were playing before the jump — otherwise a
			// mid-video segment abandoned by another seek inside the throttle window would
			// never reach the server, under-counting coverage on seek-heavy watch patterns.
			this.closeOpenSegment()
			this.flushHeartbeat(true)
		},
		onSeeked() {
			const el = this.$refs.video
			if (el) {
				this.currentTime = el.currentTime
				if (this.isPlaying) {
					this.openSegment()
				}
			}
		},
		async onEnded() {
			this.isPlaying = false
			this.srStatus = t('learning', 'Video beendet')
			this.closeOpenSegment()
			// Deliver the last seconds BEFORE asking for completion; sent concurrently, complete()
			// could be decided on coverage that still lacks them.
			await this.drainHeartbeats()
			// The client NEVER asserts completion — it asks the server to re-decide.
			this.requestCompletion()
		},
		onSeekInput(event) {
			const el = this.$refs.video
			const value = Number(event.target.value)
			if (el && Number.isFinite(value)) {
				el.currentTime = value
				this.currentTime = value
			}
		},
		onSeekKeydown(event) {
			// ArrowLeft/Right seek ±5s (native range already does small steps, but we
			// make the 5s contract explicit + announce it).
			if (event.key === 'ArrowLeft') {
				event.preventDefault()
				this.seekBy(-SEEK_STEP_SECONDS)
			} else if (event.key === 'ArrowRight') {
				event.preventDefault()
				this.seekBy(SEEK_STEP_SECONDS)
			} else if (event.key === ' ' || event.key === 'Spacebar') {
				event.preventDefault()
				this.togglePlay()
			}
		},
		seekBy(delta) {
			const el = this.$refs.video
			if (!el) {
				return
			}
			const next = Math.max(0, Math.min(this.effectiveDuration, el.currentTime + delta))
			el.currentTime = next
			this.currentTime = next
		},
		openSegment() {
			const el = this.$refs.video
			this.segmentStart = el ? el.currentTime : this.currentTime
			this.segmentQueuedUpTo = null
		},
		closeOpenSegment() {
			const el = this.$refs.video
			if (this.segmentStart !== null && el) {
				this.recordSegment(this.segmentStart, el.currentTime, true)
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
		formatTime(seconds) {
			const total = Math.max(0, Math.floor(Number(seconds) || 0))
			const mins = Math.floor(total / 60)
			const secs = total % 60
			return `${mins}:${secs.toString().padStart(2, '0')}`
		},
	},
}
</script>

<style scoped>
.video-player {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.video-player__media {
	width: 100%;
	max-height: 60vh;
	background: #000;
	border-radius: var(--border-radius-large, 12px);
}

/* Visually-hidden but screen-reader-available status. Kept in the flow (not
   display:none) so aria-live announcements fire. */
.video-player__sr-status {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
	border: 0;
}

.video-player__controls {
	display: flex;
	align-items: center;
	gap: 12px;
}

.video-player__btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 44px;
	min-height: 44px;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	/* AA contrast: primary element bg with on-primary text. */
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 1.1em;
	cursor: pointer;
}

.video-player__btn:hover {
	background: var(--color-primary-element-hover);
}

.video-player__btn:focus-visible,
.video-player__seek:focus-visible {
	outline: 3px solid var(--color-primary-element);
	outline-offset: 2px;
}

.video-player__seek {
	flex: 1;
	height: 6px;
	cursor: pointer;
}

.video-player__time {
	font-variant-numeric: tabular-nums;
	font-size: 0.9em;
	color: var(--color-main-text);
	min-width: 90px;
	text-align: end;
}

.video-player__gate {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	align-self: flex-start;
	padding: 4px 12px;
	border-radius: 999px;
	font-size: 0.85em;
	font-weight: 700;
}

.video-player__gate--pending {
	background: color-mix(in srgb, var(--color-warning) 18%, transparent);
	color: var(--color-warning);
}

.video-player__gate--complete {
	background: color-mix(in srgb, var(--color-success) 18%, transparent);
	color: var(--color-success);
}

.video-player__hint {
	margin: 0;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}
</style>
