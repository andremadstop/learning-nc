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
		onEnded() {
			this.isPlaying = false
			this.srStatus = t('learning', 'Video beendet')
			this.closeOpenSegment()
			this.flushHeartbeat(true)
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
		},
		closeOpenSegment() {
			const el = this.$refs.video
			if (this.segmentStart !== null && el) {
				this.recordSegment(this.segmentStart, el.currentTime, true)
			}
			this.segmentStart = null
		},
		recordSegment(start, end, closing) {
			this.intervals = addSegment(this.intervals, start, end)
			this.coveredPct = coveredFraction(this.intervals, this.effectiveDuration)
			if (closing) {
				this.segmentStart = null
			}
		},
		flushHeartbeat(force) {
			const now = Date.now()
			if (!force && !shouldFlushHeartbeat(this.lastFlushMs, now)) {
				return
			}
			const merged = this.intervals
			const last = merged[merged.length - 1]
			if (!last) {
				return
			}
			this.lastFlushMs = now
			// Report the most-recent covered segment; the server merges authoritatively.
			this.postHeartbeat(last.start, last.end)
		},
		async postHeartbeat(start, end) {
			try {
				const response = await axios.post(
					generateUrl('/apps/learning/api/video-progress/heartbeat'),
					{ contentId: this.contentId, start: Math.floor(start), end: Math.floor(end) },
				)
				this.applyServerProgress(response.data)
			} catch (e) {
				// Heartbeats are best-effort; the server is the gate, a dropped ping is non-fatal.
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
