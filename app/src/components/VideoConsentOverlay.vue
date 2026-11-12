<template>
	<!--
		DSGVO Art.13 consent gate for THIRD-PARTY embeds (Vimeo / YouTube) ONLY.
		nc-files needs no consent (own Nextcloud) and never mounts this component.

		HARD RULE: before consent this template contains ZERO third-party references —
		no <iframe>, no <link rel="preconnect"/"preload">, no third-party <img>/poster,
		no SDK <script>. The embed + SDK are injected imperatively in accept() only.
		This is the load-bearing "no request/cookie before accept" guarantee.

		After consent the provider's player API reports the watch progress; it goes to the
		server as heartbeats exactly like the native player (videoHeartbeatMixin).
	-->
	<div class="video-consent">
		<div v-if="!consented" class="video-consent__gate">
			<h4 class="video-consent__title">
				{{ providerLabel }}
			</h4>
			<p class="video-consent__text">
				{{ t('learning', 'Dieses Video wird von einem externen Anbieter ({provider}) geladen. Beim Klick auf „Laden und ansehen" wird eine Verbindung zu {provider} aufgebaut und dabei können personenbezogene Daten (u.a. deine IP-Adresse) an {provider} übertragen werden.', { provider: providerName }) }}
			</p>
			<p class="video-consent__text">
				{{ t('learning', 'Nach deiner Zustimmung lädt diese Seite den Player und das Player-Skript von {provider}. Das Skript meldet der App, welche Abschnitte du angesehen hast; nur so kann ein Pflichtvideo als abgeschlossen gelten.', { provider: providerName }) }}
			</p>
			<p class="video-consent__text video-consent__text--muted">
				{{ t('learning', 'Vor deiner Zustimmung wird nichts von {provider} geladen — kein Skript, kein Cookie, keine Verbindung.', { provider: providerName }) }}
			</p>
			<div class="video-consent__actions">
				<button
					ref="acceptButton"
					type="button"
					class="video-consent__accept"
					@click="accept">
					{{ t('learning', 'Laden und ansehen') }}
				</button>
			</div>
		</div>

		<div v-else class="video-consent__embed">
			<!-- The provider iframe/SDK is appended to this element in accept(). -->
			<div ref="embedHost" class="video-consent__host"></div>
			<p class="video-consent__sr-status" aria-live="polite" role="status">
				{{ srStatus }}
			</p>
			<p v-if="trackingFailed" class="video-consent__tracking-error" role="alert">
				{{ t('learning', 'Der Player von {provider} hat sich nicht gemeldet. Dein Fortschritt bei diesem Video kann gerade nicht erfasst werden — lade die Seite neu und versuche es noch einmal.', { provider: providerName }) }}
			</p>
			<p v-else class="video-consent__gate-status" :class="isComplete ? 'video-consent__gate-status--complete' : ''">
				<span aria-hidden="true">{{ isComplete ? '✓' : '●' }}</span>
				{{ gateLabel }}
			</p>
			<p class="video-consent__note">
				{{ t('learning', 'Externer Player geladen. Ein Vorspul-Schutz ist bei Drittanbieter-Playern technisch nicht möglich — dein Abschluss wird serverseitig geprüft.') }}
			</p>
		</div>
	</div>
</template>

<script>
/**
 * Loading third-party players AFTER consent, zero dependencies (SDKs lazy-load
 * from the provider CDN at runtime). Privacy hardening:
 *   - YouTube: youtube-nocookie.com/embed/{id} (never youtube.com) + dnt=1; the IFrame
 *     Player API script (www.youtube.com/iframe_api) only wraps that frame
 *   - Vimeo:   player.vimeo.com/video/{id}?dnt=1, wrapped by player.vimeo.com/api/player.js
 *
 * Progress: the provider API reports play / position / pause / end; the played seconds go
 * to the server as heartbeats like the native player, and the server alone decides
 * completion (audit 2026-11, H6: without this a gated Vimeo / YouTube video could never
 * be completed). A jump in the reported position is a seek: the skipped part is not
 * credited.
 *
 * NOTE (honest limitation): seek-prevention on a third-party embed is impossible —
 * we cannot intercept the provider's own scrubber; skipping only costs coverage.
 */
import { markRaw } from 'vue'
import { attachProviderPlayer } from '../utils/videoProviderSdk.js'
import videoHeartbeatMixin from '../videoHeartbeatMixin.js'

// A reported position further away than this from the expected one is a seek, not playback
// (generous: up to 2x playback speed plus provider event jitter).
const SEEK_TOLERANCE_SECONDS = 2

export default {
	name: 'VideoConsentOverlay',

	mixins: [videoHeartbeatMixin],

	props: {
		sourceType: {
			type: String,
			required: true,
			validator(value) {
				return ['vimeo', 'youtube-nocookie'].includes(value)
			},
		},
		// Provider video id (YouTube id or Vimeo numeric id).
		embedId: {
			type: String,
			required: true,
		},
		title: {
			type: String,
			default: '',
		},
		// Registry content id — the heartbeat key (same endpoints as VideoPlayer).
		contentId: {
			type: Number,
			required: true,
		},
		durationSeconds: {
			type: Number,
			default: 0,
		},
		initialCoveredPct: {
			type: Number,
			default: 0,
		},
		initialCompleted: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['consented', 'embed-loaded', 'completed', 'progress'],

	data() {
		return {
			consented: false,
			isPlaying: false,
			currentTime: 0,
			lastPositionAt: null,
			providerDuration: 0,
			provider: null,
			trackingFailed: false,
			destroyed: false,
		}
	},

	computed: {
		providerName() {
			return this.sourceType === 'vimeo' ? 'Vimeo' : 'YouTube'
		},
		providerLabel() {
			return this.title
				? this.title
				: t('learning', '{provider}-Video', { provider: this.providerName })
		},
		effectiveDuration() {
			return this.providerDuration > 0 ? this.providerDuration : (this.durationSeconds || 0)
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
		// The mixin's hook has already closed the open segment and flushed it.
		this.destroyed = true
		if (this.provider) {
			this.provider.destroy()
			this.provider = null
		}
	},

	methods: {
		accept() {
			this.consented = true
			this.$emit('consented', { sourceType: this.sourceType, embedId: this.embedId })
			// Wait for the embed host to render, then inject the iframe imperatively.
			this.$nextTick(() => this.injectEmbed())
		},
		injectEmbed() {
			const host = this.$refs.embedHost
			if (!host) {
				return
			}
			const iframe = document.createElement('iframe')
			iframe.setAttribute('allowfullscreen', 'true')
			iframe.setAttribute('title', this.providerLabel)
			iframe.setAttribute('loading', 'lazy')
			iframe.style.width = '100%'
			iframe.style.aspectRatio = '16 / 9'
			iframe.style.border = '0'

			if (this.sourceType === 'youtube-nocookie') {
				iframe.setAttribute(
					'allow',
					'accelerometer; encrypted-media; gyroscope; picture-in-picture',
				)
				// youtube-nocookie.com (privacy-enhanced) + dnt=1; NEVER youtube.com. enablejsapi +
				// origin let the IFrame Player API (loaded below, after consent) talk to this frame.
				const origin = encodeURIComponent(window.location.origin)
				iframe.src = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(this.embedId)}?dnt=1&rel=0&enablejsapi=1&origin=${origin}`
			} else {
				iframe.setAttribute('allow', 'fullscreen; picture-in-picture')
				iframe.src = `https://player.vimeo.com/video/${encodeURIComponent(this.embedId)}?dnt=1`
			}

			host.appendChild(iframe)
			this.$emit('embed-loaded', { sourceType: this.sourceType, embedId: this.embedId })
			this.startTracking(iframe)
		},
		async startTracking(iframe) {
			try {
				const provider = await attachProviderPlayer(this.sourceType, iframe, {
					play: (s) => this.onProviderPlay(s),
					time: (s) => this.onProviderTime(s),
					pause: (s) => this.onProviderPause(s),
					end: (s) => this.onProviderEnd(s),
					duration: (s) => this.onProviderDuration(s),
				})
				if (this.destroyed) {
					provider.destroy()
					return
				}
				this.provider = markRaw(provider)
			} catch (e) {
				// No API, no progress: say so instead of showing a gate status that never moves.
				this.trackingFailed = true
			}
		},
		playbackPosition() {
			return this.currentTime
		},
		onProviderDuration(seconds) {
			if (seconds !== null && seconds > 0) {
				this.providerDuration = seconds
			}
		},
		onProviderPlay(seconds) {
			if (this.segmentStart !== null) {
				// already playing (a repeated play event): treat it as a position report
				this.isPlaying = true
				this.onProviderTime(seconds)
				return
			}
			if (seconds !== null) {
				this.currentTime = seconds
			}
			this.lastPositionAt = Date.now()
			this.isPlaying = true
			this.srStatus = t('learning', 'Wiedergabe läuft')
			this.openSegment()
		},
		onProviderTime(seconds) {
			if (seconds === null) {
				return
			}
			const now = Date.now()
			if (this.segmentStart !== null && this.isSeek(seconds, now)) {
				// Close and flush what was played before the jump; the skipped part is not credited.
				this.closeOpenSegment()
				this.flushHeartbeat(true)
				this.currentTime = seconds
				this.lastPositionAt = now
				if (this.isPlaying) {
					this.openSegment()
				}
				return
			}
			this.currentTime = seconds
			this.lastPositionAt = now
			if (this.segmentStart !== null) {
				this.recordSegment(this.segmentStart, seconds, false)
			}
			this.flushHeartbeat(false)
		},
		isSeek(seconds, now) {
			if (seconds < this.currentTime - 1) {
				return true
			}
			const elapsed = this.lastPositionAt === null ? 0 : Math.max(0, now - this.lastPositionAt) / 1000
			return seconds - this.currentTime > elapsed * 2 + SEEK_TOLERANCE_SECONDS
		},
		onProviderPause(seconds) {
			const wasPlaying = this.isPlaying
			this.isPlaying = false
			if (wasPlaying) {
				this.srStatus = t('learning', 'Pausiert')
			}
			this.onProviderTime(seconds)
			this.closeOpenSegment()
			this.flushHeartbeat(true)
		},
		async onProviderEnd(seconds) {
			this.isPlaying = false
			this.srStatus = t('learning', 'Video beendet')
			this.onProviderTime(seconds)
			this.closeOpenSegment()
			// Deliver the last seconds BEFORE asking for completion (same order as VideoPlayer).
			await this.drainHeartbeats()
			// The client NEVER asserts completion — it asks the server to re-decide.
			this.requestCompletion()
		},
	},
}
</script>

<style scoped>
.video-consent__gate {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 20px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover);
}

.video-consent__title {
	margin: 0;
	font-size: 1.05em;
	font-weight: 700;
	color: var(--color-main-text);
}

.video-consent__text {
	margin: 0;
	font-size: 0.9em;
	line-height: 1.5;
	color: var(--color-main-text);
}

.video-consent__text--muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.video-consent__actions {
	margin-top: 6px;
}

.video-consent__accept {
	min-height: 44px;
	padding: 0 18px;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
	cursor: pointer;
}

.video-consent__accept:hover {
	background: var(--color-primary-element-hover);
}

.video-consent__accept:focus-visible {
	outline: 3px solid var(--color-primary-element);
	outline-offset: 2px;
}

.video-consent__host {
	width: 100%;
}

.video-consent__sr-status {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}

.video-consent__gate-status {
	margin: 8px 0 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.video-consent__gate-status--complete {
	color: var(--color-success-text, var(--color-success));
	font-weight: 600;
}

.video-consent__tracking-error {
	margin: 8px 0 0;
	font-size: 0.85em;
	color: var(--color-error-text, var(--color-error));
}

.video-consent__note {
	margin: 8px 0 0;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}
</style>
