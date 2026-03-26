<template>
	<transition name="onb-fade">
	<div
		v-if="visible"
		class="onboarding-intro"
		:class="{ 'onboarding-intro--reduced': reducedMotion }"
	>
		<div class="onboarding-intro__bg">
			<!-- ── Phase 1: Splash ─────────────────────────── -->
			<transition name="onb-content" mode="out-in">
				<div v-if="phase === 'splash'" key="splash" class="onb-center">
					<div class="onb-avatar-wrap">
						<svg class="onb-avatar" viewBox="0 0 60 80" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<ellipse cx="30" cy="72" rx="22" ry="6" fill="var(--lnc-cyan, #06b6d4)" opacity="0.15" />
							<rect x="12" y="30" width="36" height="40" rx="8" fill="var(--lnc-cyan, #06b6d4)" opacity="0.12" stroke="var(--lnc-cyan, #06b6d4)" stroke-width="1.5" />
							<circle cx="30" cy="22" r="16" fill="var(--lnc-bg-surface, #141926)" stroke="var(--lnc-cyan, #06b6d4)" stroke-width="2" />
							<circle cx="23" cy="20" r="3" fill="var(--lnc-text-primary, #e8ecf4)" />
							<circle cx="37" cy="20" r="3" fill="var(--lnc-text-primary, #e8ecf4)" />
							<circle cx="24" cy="19.5" r="1.5" fill="var(--lnc-cyan, #06b6d4)" />
							<circle cx="38" cy="19.5" r="1.5" fill="var(--lnc-cyan, #06b6d4)" />
							<path d="M26 28q4 3 8 0" stroke="var(--lnc-cyan, #06b6d4)" stroke-width="1.5" fill="none" stroke-linecap="round" />
						</svg>
					</div>
					<h1 class="onb-splash-title">{{ vt('Welcome to DevCloud!') }}</h1>
					<p class="onb-splash-subtitle">{{ vt('Your personal learning space') }}</p>
				</div>

				<!-- ── Phase 2: Slides ─────────────────────────── -->
				<div v-else-if="phase === 'slides'" key="slides" class="onb-center onb-slides-wrap">
					<!-- Progress dots -->
					<div class="onb-progress">
						<button
							v-for="(slide, idx) in slides"
							:key="slide.id"
							type="button"
							:class="['onb-dot', { active: idx <= slideIndex, current: idx === slideIndex }]"
							:aria-label="(idx + 1) + ' / ' + slides.length"
							@click="goToSlide(idx)" />
					</div>

					<!-- Slide content with direction transition -->
					<transition :name="slideDir" mode="out-in">
						<div :key="currentSlide.id" class="onb-slide">
							<span class="onb-slide-icon">{{ currentSlide.icon }}</span>
							<h2 class="onb-slide-title">{{ vt(currentSlide.titleKey) }}</h2>
							<p class="onb-slide-text">{{ vt(currentSlide.textKey) }}</p>
						</div>
					</transition>

					<!-- Nav -->
					<div class="onb-nav">
						<button
							v-if="slideIndex > 0"
							type="button"
							class="onb-btn onb-btn--ghost"
							@click="prevSlide">
							{{ vt('Back') }}
						</button>
						<span v-else />

						<span class="onb-counter">{{ slideIndex + 1 }} / {{ slides.length }}</span>

						<button
							type="button"
							class="onb-btn onb-btn--primary"
							@click="isLastSlide ? finish() : nextSlide()">
							{{ isLastSlide ? vt('Start learning') : vt('Next') }}
						</button>
					</div>

					<button
						v-if="!isLastSlide"
						type="button"
						class="onb-skip"
						@click="finish">
						{{ vt('Skip tour') }}
					</button>
				</div>
			</transition>
		</div>
	</div>
	</transition>
</template>

<script>
import { translateVirtuProf, detectVirtuProfLanguage } from '../utils/virtuprof-i18n.js'
import { getSlidesForRole } from '../utils/onboarding-slides.js'

export default {
	name: 'OnboardingIntro',

	props: {
		role: {
			type: String,
			default: 'student',
		},
	},

	data() {
		return {
			reducedMotion: false,
			visible: true,
			phase: 'splash',
			slideIndex: 0,
			slideDir: 'slide-next',
			splashTimer: null,
		}
	},

	computed: {
		language() {
			return detectVirtuProfLanguage()
		},
		slides() {
			return getSlidesForRole(this.role)
		},
		currentSlide() {
			return this.slides[this.slideIndex] || this.slides[0]
		},
		isLastSlide() {
			return this.slideIndex >= this.slides.length - 1
		},
	},

	mounted() {
		this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
		this.splashTimer = setTimeout(() => {
			this.phase = 'slides'
		}, this.reducedMotion ? 1200 : 4000)

		this.handleKeydown = (e) => {
			if (this.phase !== 'slides') return
			if (e.key === 'ArrowRight' || e.key === ' ') {
				e.preventDefault()
				this.isLastSlide ? this.finish() : this.nextSlide()
			} else if (e.key === 'ArrowLeft') {
				e.preventDefault()
				this.prevSlide()
			} else if (e.key === 'Escape') {
				this.finish()
			}
		}
		document.addEventListener('keydown', this.handleKeydown)
	},

	beforeDestroy() {
		if (this.splashTimer) clearTimeout(this.splashTimer)
		document.removeEventListener('keydown', this.handleKeydown)
	},

	methods: {
		vt(key, params = {}) {
			return translateVirtuProf(this.language, key, params)
		},
		nextSlide() {
			if (this.slideIndex < this.slides.length - 1) {
				this.slideDir = 'slide-next'
				this.slideIndex += 1
			}
		},
		prevSlide() {
			if (this.slideIndex > 0) {
				this.slideDir = 'slide-prev'
				this.slideIndex -= 1
			}
		},
		goToSlide(idx) {
			this.slideDir = idx > this.slideIndex ? 'slide-next' : 'slide-prev'
			this.slideIndex = idx
		},
		finish() {
			this.visible = false
			setTimeout(() => {
				this.$emit('done')
			}, 600)
		},
	},
}
</script>

<style scoped>
/* ── Fullscreen shell ────────────────────────────────── */
.onboarding-intro {
	position: fixed;
	inset: 0;
	z-index: 10000;
}

.onboarding-intro__bg {
	position: absolute;
	inset: 0;
	background: var(--lnc-bg-canvas, #0a0e17);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 24px;
}

.onb-center {
	text-align: center;
	max-width: 520px;
	width: 100%;
}

/* ── Phase transition (splash ↔ slides) ──────────────── */
.onb-content-enter-active {
	transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
}
.onb-content-leave-active {
	transition: opacity 0.5s ease;
}
.onb-content-enter {
	opacity: 0;
	transform: translateY(16px);
}
.onb-content-leave-to {
	opacity: 0;
}

/* ── Splash phase ────────────────────────────────────── */
.onb-avatar-wrap {
	width: 96px;
	height: 128px;
	margin: 0 auto 1.5rem;
	animation: onb-avatar-appear 1.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.onb-avatar {
	width: 100%;
	height: 100%;
	filter: drop-shadow(0 8px 24px rgba(6, 182, 212, 0.3));
}

.onb-splash-title {
	font-size: 1.75rem;
	font-weight: 700;
	color: var(--lnc-text-primary, #e8ecf4);
	margin: 0 0 0.5rem;
	opacity: 0;
	animation: onb-text-fade 1.5s cubic-bezier(0.16, 1, 0.3, 1) 1.4s forwards;
}

.onb-splash-subtitle {
	font-size: 1rem;
	color: var(--lnc-text-secondary, #8b95a8);
	margin: 0;
	opacity: 0;
	animation: onb-text-fade 1.5s cubic-bezier(0.16, 1, 0.3, 1) 2.4s forwards;
}

@keyframes onb-avatar-appear {
	0%  { transform: scale(0.5) translateY(24px); opacity: 0; }
	60% { transform: scale(1.04) translateY(-2px); opacity: 1; }
	100%{ transform: scale(1) translateY(0); opacity: 1; }
}

@keyframes onb-text-fade {
	0%  { opacity: 0; transform: translateY(12px); }
	100%{ opacity: 1; transform: translateY(0); }
}

/* ── Slides phase ────────────────────────────────────── */
.onb-slides-wrap {
	padding: 0 8px;
}

.onb-progress {
	display: flex;
	gap: 6px;
	justify-content: center;
	margin-bottom: 28px;
}

.onb-dot {
	appearance: none;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	border: none;
	padding: 0;
	background: rgba(255, 255, 255, 0.15);
	cursor: pointer;
	transition: background 0.2s, transform 0.2s;
}

.onb-dot.active {
	background: var(--lnc-cyan, #06b6d4);
}

.onb-dot.current {
	transform: scale(1.4);
	background: var(--lnc-cyan, #06b6d4);
}

.onb-slide {
	min-height: 200px;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
}

.onb-slide-icon {
	font-size: 48px;
	margin-bottom: 16px;
	display: block;
}

.onb-slide-title {
	font-size: 1.35rem;
	font-weight: 700;
	color: var(--lnc-text-primary, #e8ecf4);
	margin: 0 0 12px;
}

.onb-slide-text {
	font-size: 0.95rem;
	line-height: 1.65;
	color: var(--lnc-text-secondary, #8b95a8);
	margin: 0;
	max-width: 420px;
	white-space: pre-line;
}

/* ── Slide direction transitions ─────────────────────── */
.slide-next-enter-active,
.slide-next-leave-active,
.slide-prev-enter-active,
.slide-prev-leave-active {
	transition: opacity 0.3s ease, transform 0.3s ease;
}

.slide-next-enter  { opacity: 0; transform: translateX(40px); }
.slide-next-leave-to { opacity: 0; transform: translateX(-40px); }
.slide-prev-enter  { opacity: 0; transform: translateX(-40px); }
.slide-prev-leave-to { opacity: 0; transform: translateX(40px); }

/* ── Nav ─────────────────────────────────────────────── */
.onb-nav {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-top: 32px;
	gap: 12px;
}

.onb-counter {
	font-size: 12px;
	color: rgba(255, 255, 255, 0.35);
	font-variant-numeric: tabular-nums;
}

.onb-btn {
	appearance: none;
	border: none;
	border-radius: 10px;
	padding: 10px 22px;
	font: inherit;
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s, opacity 0.15s, transform 0.1s;
}

.onb-btn:active {
	transform: scale(0.97);
}

.onb-btn--primary {
	background: var(--lnc-cyan, #06b6d4);
	color: var(--lnc-bg-canvas, #0a0e17);
}

.onb-btn--primary:hover {
	filter: brightness(1.15);
}

.onb-btn--ghost {
	background: transparent;
	color: rgba(255, 255, 255, 0.5);
}

.onb-btn--ghost:hover {
	color: rgba(255, 255, 255, 0.8);
}

.onb-skip {
	display: block;
	width: 100%;
	margin-top: 18px;
	appearance: none;
	border: none;
	background: transparent;
	color: rgba(255, 255, 255, 0.3);
	font: inherit;
	font-size: 13px;
	cursor: pointer;
	text-align: center;
	transition: color 0.15s;
}

.onb-skip:hover {
	color: rgba(255, 255, 255, 0.6);
}

/* ── Fadeout (whole component) ────────────────────────── */
.onb-fade-leave-active {
	transition: opacity 0.6s ease;
}

.onb-fade-leave-to {
	opacity: 0;
}

/* ── Mobile ──────────────────────────────────────────── */
@media (max-width: 480px) {
	.onb-slide-icon { font-size: 40px; }
	.onb-slide-title { font-size: 1.15rem; }
	.onb-slide { min-height: 170px; }
	.onb-splash-title { font-size: 1.4rem; }
}

/* ── Reduced motion ──────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
	.onb-avatar-wrap,
	.onb-splash-title,
	.onb-splash-subtitle {
		animation: none;
		opacity: 1;
		transform: none;
	}

	.onb-content-enter-active,
	.onb-content-leave-active,
	.slide-next-enter-active,
	.slide-next-leave-active,
	.slide-prev-enter-active,
	.slide-prev-leave-active {
		transition-duration: 0.15s;
	}

	.slide-next-enter,
	.slide-next-leave-to,
	.slide-prev-enter,
	.slide-prev-leave-to {
		transform: none;
	}
}

.onboarding-intro--reduced .onb-avatar-wrap,
.onboarding-intro--reduced .onb-splash-title,
.onboarding-intro--reduced .onb-splash-subtitle {
	animation: none;
	opacity: 1;
	transform: none;
}
</style>
