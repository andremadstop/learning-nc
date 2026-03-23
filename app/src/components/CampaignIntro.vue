<template>
	<div
		class="campaign-intro"
		:class="{ 'campaign-intro--reduced': reducedMotion }"
		:data-campaign="campaignId"
	>
		<div class="campaign-intro__overlay">
			<div class="campaign-intro__content">
				<!-- Icon -->
				<div class="campaign-intro__icon-wrap">
					<svg
						class="campaign-intro__icon"
						viewBox="0 0 64 64"
						xmlns="http://www.w3.org/2000/svg"
						aria-hidden="true"
					>
						<template v-if="iconType === 'shield'">
							<path d="M32 4L8 16v16c0 14.4 10.24 27.84 24 32 13.76-4.16 24-17.6 24-32V16L32 4z" fill="none" :stroke="iconColor" stroke-width="2.5" />
							<path d="M32 10L14 20v12c0 11.52 8.19 22.27 18 25.2V10z" :fill="iconColor" opacity="0.15" />
							<path d="M27 32l5 5 9-9" fill="none" :stroke="iconAccent" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
						</template>
						<template v-else-if="iconType === 'terminal'">
							<rect x="6" y="10" width="52" height="40" rx="4" fill="none" :stroke="iconColor" stroke-width="2.5" />
							<path d="M6 20h52" :stroke="iconColor" stroke-width="2" />
							<circle cx="14" cy="15" r="2" :fill="iconAccent" />
							<circle cx="22" cy="15" r="2" :fill="iconColor" opacity="0.5" />
							<path d="M16 30l8 6-8 6" fill="none" :stroke="iconAccent" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
							<path d="M30 42h16" :stroke="iconColor" stroke-width="2.5" stroke-linecap="round" opacity="0.6" />
						</template>
						<template v-else-if="iconType === 'server'">
							<rect x="10" y="6" width="44" height="16" rx="3" fill="none" :stroke="iconColor" stroke-width="2.5" />
							<rect x="10" y="24" width="44" height="16" rx="3" fill="none" :stroke="iconColor" stroke-width="2.5" />
							<rect x="10" y="42" width="44" height="16" rx="3" fill="none" :stroke="iconColor" stroke-width="2.5" />
							<circle cx="18" cy="14" r="2.5" :fill="iconAccent" />
							<circle cx="18" cy="32" r="2.5" :fill="iconAccent" />
							<circle cx="18" cy="50" r="2.5" :fill="iconColor" opacity="0.5" />
							<path d="M28 14h18M28 32h18M28 50h12" :stroke="iconColor" stroke-width="2" stroke-linecap="round" opacity="0.4" />
						</template>
						<template v-else-if="iconType === 'brain'">
							<path d="M32 56V36" :stroke="iconColor" stroke-width="2.5" stroke-linecap="round" />
							<path d="M22 18c-6 0-10 5-10 11s4 11 10 11h20c6 0 10-5 10-11s-4-11-10-11c-2 0-4 .6-5.5 1.6" fill="none" :stroke="iconColor" stroke-width="2.5" />
							<path d="M26 22c0-3 2.7-6 6-6s6 3 6 6" fill="none" :stroke="iconAccent" stroke-width="2.5" stroke-linecap="round" />
							<circle cx="27" cy="27" r="2" :fill="iconAccent" />
							<circle cx="37" cy="27" r="2" :fill="iconAccent" />
						</template>
						<template v-else-if="iconType === 'lock'">
							<rect x="14" y="28" width="36" height="28" rx="4" fill="none" :stroke="iconColor" stroke-width="2.5" />
							<path d="M22 28V18c0-5.52 4.48-10 10-10s10 4.48 10 10v10" fill="none" :stroke="iconColor" stroke-width="2.5" stroke-linecap="round" />
							<circle cx="32" cy="40" r="3" :fill="iconAccent" />
							<path d="M32 43v5" :stroke="iconAccent" stroke-width="2.5" stroke-linecap="round" />
						</template>
					</svg>
				</div>

				<!-- Title -->
				<h1 class="campaign-intro__title">{{ title }}</h1>

				<!-- Difficulty badge -->
				<span
					class="campaign-intro__badge"
					:class="'campaign-intro__badge--' + difficulty"
					ref="badgeEl"
				>{{ difficultyLabel }}</span>
			</div>
		</div>
	</div>
</template>

<script>
const ICON_MAP = {
	solarwinds: 'shield',
	equifax: 'shield',
	colonial_pipeline: 'shield',
	wannacry: 'terminal',
	log4shell: 'terminal',
	cysa_zero_day: 'terminal',
	a_plus_erster_tag: 'server',
	grosser_ausfall: 'server',
	der_neue_standort: 'server',
	linux_server_down: 'terminal',
	ki_fluesterer: 'brain',
	das_erbe: 'lock',
	einbruch_im_netz: 'lock',
	ransomware: 'lock',
}

const DIFFICULTY_LABELS = {
	beginner: 'Einsteiger',
	normal: 'Normal',
	advanced: 'Fortgeschritten',
	expert: 'Experte',
}

export default {
	name: 'CampaignIntro',

	props: {
		campaignId: {
			type: String,
			required: true,
		},
		title: {
			type: String,
			required: true,
		},
		difficulty: {
			type: String,
			default: 'normal',
		},
	},

	data() {
		return {
			reducedMotion: false,
			doneTimer: null,
		}
	},

	computed: {
		iconType() {
			return ICON_MAP[this.campaignId] || 'shield'
		},

		iconColor() {
			return 'var(--lnc-cyan)'
		},

		iconAccent() {
			return 'var(--lnc-primary)'
		},

		difficultyLabel() {
			return DIFFICULTY_LABELS[this.difficulty] || this.difficulty
		},
	},

	mounted() {
		this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

		if (this.reducedMotion) {
			// Static display, emit done after brief pause
			this.doneTimer = setTimeout(() => {
				this.$emit('done')
			}, 1000)
		} else {
			// Listen for last animation to end, with fallback timer
			const badge = this.$refs.badgeEl
			if (badge) {
				const onEnd = () => {
					badge.removeEventListener('animationend', onEnd)
					this.$emit('done')
				}
				badge.addEventListener('animationend', onEnd)
			}
			// Fallback: emit after 4s regardless
			this.doneTimer = setTimeout(() => {
				this.$emit('done')
			}, 4000)
		}
	},

	beforeDestroy() {
		if (this.doneTimer) {
			clearTimeout(this.doneTimer)
		}
	},
}
</script>

<style scoped>
.campaign-intro {
	position: fixed;
	inset: 0;
	z-index: 100;
	display: flex;
	align-items: center;
	justify-content: center;
}

.campaign-intro__overlay {
	position: absolute;
	inset: 0;
	background: var(--lnc-bg-canvas, #0a0e17);
	display: flex;
	align-items: center;
	justify-content: center;
}

.campaign-intro__content {
	text-align: center;
	max-width: 520px;
	padding: 2rem;
}

/* ── Icon ────────────────────────────────────────────────────────── */

.campaign-intro__icon-wrap {
	width: 96px;
	height: 96px;
	margin: 0 auto 1.5rem;
	animation: intro-icon-pulse 1.5s ease-out forwards;
}

.campaign-intro__icon {
	width: 100%;
	height: 100%;
}

/* ── Title ───────────────────────────────────────────────────────── */

.campaign-intro__title {
	font-size: 1.75rem;
	font-weight: 700;
	color: var(--lnc-text-primary, #e8ecf4);
	margin: 0 0 1rem;
	opacity: 0;
	animation: intro-title-fade 1.5s ease-out 1s forwards;
}

/* ── Badge ───────────────────────────────────────────────────────── */

.campaign-intro__badge {
	display: inline-block;
	padding: 0.25rem 0.75rem;
	border-radius: var(--lnc-radius-sm, 8px);
	font-size: 0.8rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	opacity: 0;
	animation: intro-badge-appear 1s ease-out 2.5s forwards;
}

.campaign-intro__badge--beginner {
	background: var(--lnc-green, #22c55e);
	color: var(--lnc-bg-canvas, #0a0e17);
}

.campaign-intro__badge--normal {
	background: var(--lnc-cyan, #06b6d4);
	color: var(--lnc-bg-canvas, #0a0e17);
}

.campaign-intro__badge--advanced {
	background: var(--lnc-amber, #f59e0b);
	color: var(--lnc-bg-canvas, #0a0e17);
}

.campaign-intro__badge--expert {
	background: var(--lnc-danger, #ef4444);
	color: var(--lnc-text-primary, #e8ecf4);
}

/* ── Keyframes ───────────────────────────────────────────────────── */

@keyframes intro-icon-pulse {
	0% {
		transform: scale(0.8);
		opacity: 0;
	}
	100% {
		transform: scale(1);
		opacity: 1;
	}
}

@keyframes intro-title-fade {
	0% {
		opacity: 0;
		transform: translateY(12px);
	}
	100% {
		opacity: 1;
		transform: translateY(0);
	}
}

@keyframes intro-badge-appear {
	0% {
		opacity: 0;
	}
	100% {
		opacity: 1;
	}
}

/* ── Reduced Motion ──────────────────────────────────────────────── */

@media (prefers-reduced-motion: reduce) {
	.campaign-intro__icon-wrap,
	.campaign-intro__title,
	.campaign-intro__badge {
		animation: none;
		opacity: 1;
		transform: none;
	}
}

.campaign-intro--reduced .campaign-intro__icon-wrap,
.campaign-intro--reduced .campaign-intro__title,
.campaign-intro--reduced .campaign-intro__badge {
	animation: none;
	opacity: 1;
	transform: none;
}
</style>
