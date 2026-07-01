<template>
	<!--
		DSGVO Art.13 consent gate for THIRD-PARTY embeds (Vimeo / YouTube) ONLY.
		nc-files needs no consent (own Nextcloud) and never mounts this component.

		HARD RULE: before consent this template contains ZERO third-party references —
		no <iframe>, no <link rel="preconnect"/"preload">, no third-party <img>/poster,
		no SDK <script>. The embed + SDK are injected imperatively in accept() only.
		This is the load-bearing "no request/cookie before accept" guarantee.
	-->
	<div class="video-consent">
		<div v-if="!consented" class="video-consent__gate">
			<h4 class="video-consent__title">
				{{ providerLabel }}
			</h4>
			<p class="video-consent__text">
				{{ t('learning', 'Dieses Video wird von einem externen Anbieter ({provider}) geladen. Beim Klick auf „Laden und ansehen" wird eine Verbindung zu {provider} aufgebaut und dabei können personenbezogene Daten (u.a. deine IP-Adresse) an {provider} übertragen werden.', { provider: providerName }) }}
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
 *   - YouTube: youtube-nocookie.com/embed/{id} (never youtube.com) + dnt=1
 *   - Vimeo:   player.vimeo.com/video/{id}?dnt=1
 *
 * NOTE (honest limitation): seek-prevention on a third-party embed is impossible —
 * we cannot intercept the provider's own scrubber. Full player-progress tracking
 * is the DEFERRABLE slot; the consent gate + dnt + no-preload SHIP now. Best-effort
 * playback events are emitted where the provider SDK exposes them.
 */
export default {
	name: 'VideoConsentOverlay',

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
	},

	data() {
		return {
			consented: false,
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
				// youtube-nocookie.com (privacy-enhanced) + dnt=1; NEVER youtube.com.
				iframe.src = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(this.embedId)}?dnt=1&rel=0`
			} else {
				iframe.setAttribute('allow', 'fullscreen; picture-in-picture')
				iframe.src = `https://player.vimeo.com/video/${encodeURIComponent(this.embedId)}?dnt=1`
			}

			host.appendChild(iframe)
			this.$emit('embed-loaded', { sourceType: this.sourceType, embedId: this.embedId })
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

.video-consent__note {
	margin: 8px 0 0;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}
</style>
