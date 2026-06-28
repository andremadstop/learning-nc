<template>
  <div
    ref="wrapperRef"
    class="kosmologe-avatar-wrapper chibi-avatar-wrapper"
    :class="{ 'is-waving': waving }"
    :style="sizeStyle"
    @click="handleClick">
    <div
      class="kosmologe-avatar chibi-avatar"
      :class="[`animation-${animation}`, { 'is-waving': waving }]"
      :style="avatarSizeStyle">

      <svg
        viewBox="0 0 60 80"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        aria-label="Der Kosmologe">

        <defs>
          <radialGradient id="ko-galaxy" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stop-color="#d2efff" stop-opacity="0.98" />
            <stop offset="45%" stop-color="#5aa0e6" stop-opacity="0.8" />
            <stop offset="100%" stop-color="#1a2535" stop-opacity="0" />
          </radialGradient>
          <radialGradient id="ko-skin" cx="40%" cy="30%" r="78%">
            <stop offset="0%" stop-color="#ffdcb8" />
            <stop offset="62%" stop-color="#f3c7a6" />
            <stop offset="100%" stop-color="#e0a87f" />
          </radialGradient>
          <linearGradient id="ko-sweater" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#35466d" />
            <stop offset="100%" stop-color="#222f4c" />
          </linearGradient>
          <linearGradient id="ko-hair" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#4a3d2c" />
            <stop offset="100%" stop-color="#2a2014" />
          </linearGradient>
          <radialGradient id="ko-aura" cx="50%" cy="42%" r="58%">
            <stop offset="0%" stop-color="#6fd0ff" stop-opacity="0.32" />
            <stop offset="100%" stop-color="#6fd0ff" stop-opacity="0" />
          </radialGradient>
        </defs>

        <!-- Cosmic energy aura (power-element) -->
        <ellipse cx="30" cy="34" rx="29" ry="32" fill="url(#ko-aura)" />

        <!-- Sweater body (deep space navy) -->
        <rect x="14" y="40" width="32" height="32" rx="6" fill="url(#ko-sweater)" />
        <!-- Cyan rim light along shoulders -->
        <path d="M 14 46 Q 14 40 20 40 M 40 40 Q 46 40 46 46" fill="none" stroke="#6fd0ff" stroke-width="0.9" stroke-linecap="round" opacity="0.55" />
        <!-- Shirt collar peeking through V -->
        <path d="M 22 40 L 30 48 L 38 40" fill="#e4ebf5" stroke="#a9b4c8" stroke-width="0.5" stroke-linejoin="round" />
        <!-- Sweater cable-knit hint -->
        <path d="M 22 58 L 22 70 M 26 58 L 26 70 M 30 58 L 30 70 M 34 58 L 34 70 M 38 58 L 38 70"
              stroke="rgba(255,255,255,0.08)" stroke-width="0.7" />

        <!-- Mini-Galaxie statt Buch (brighter, with ring) -->
        <g data-prof-feature="book">
          <circle cx="30" cy="56" r="10" fill="url(#ko-galaxy)" />
          <!-- Orbital ring (planet-ring cue) -->
          <ellipse cx="30" cy="56" rx="11" ry="3.6" fill="none" stroke="#bfe6ff" stroke-width="0.8" opacity="0.75" transform="rotate(-18 30 56)" />
          <!-- Stars on top of galaxy -->
          <circle cx="26" cy="53" r="0.8" fill="#fff" />
          <circle cx="33" cy="55" r="0.6" fill="#fff" />
          <circle cx="29" cy="59" r="0.5" fill="#fff" />
          <circle cx="35" cy="51" r="0.7" fill="#bfe6ff" />
          <circle cx="24" cy="58" r="0.5" fill="#bfe6ff" />
          <circle cx="31" cy="52" r="0.5" fill="#fff" />
          <!-- Hands holding the galaxy -->
          <ellipse cx="19.5" cy="60" rx="3.2" ry="2.4" fill="url(#ko-skin)" />
          <ellipse cx="40.5" cy="60" rx="3.2" ry="2.4" fill="url(#ko-skin)" />
        </g>

        <!-- Wave-Arm -->
        <g ref="armRef" class="wave-arm">
          <path d="M 42 42 Q 52 34 50 24" stroke="#f0bf9a" stroke-width="4.5" fill="none" stroke-linecap="round" />
          <circle cx="50" cy="22" r="4" fill="url(#ko-skin)" />
        </g>

        <!-- Head -->
        <circle cx="30" cy="28" r="16" fill="url(#ko-skin)" />
        <ellipse cx="14.5" cy="28" rx="2" ry="2.8" fill="#e6ab82" />
        <ellipse cx="45.5" cy="28" rx="2" ry="2.8" fill="#e6ab82" />
        <!-- Warm cheeks -->
        <ellipse cx="21" cy="32.5" rx="2.6" ry="1.7" fill="#f0a07a" opacity="0.45" />
        <ellipse cx="39" cy="32.5" rx="2.6" ry="1.7" fill="#f0a07a" opacity="0.45" />

        <!-- Hair (short, dark, with subtle wave) -->
        <g class="hair">
          <path d="M 16 18 Q 18 8 26 10 Q 30 6 34 10 Q 42 8 44 18 Q 44 22 42 22 L 18 22 Q 16 22 16 18 Z"
                fill="url(#ko-hair)" stroke="#241c12" stroke-width="0.6" stroke-linejoin="round" />
          <!-- highlight sheen -->
          <path d="M 20 14 Q 26 10 32 13" fill="none" stroke="#6a5a44" stroke-width="1.1" stroke-linecap="round" opacity="0.7" />
          <!-- Sideburn hint -->
          <path d="M 16 22 Q 14 28 17 30" stroke="#3a3024" stroke-width="2" fill="none" stroke-linecap="round" />
          <path d="M 44 22 Q 46 28 43 30" stroke="#3a3024" stroke-width="2" fill="none" stroke-linecap="round" />
        </g>

        <!-- Eyebrows (warm, slight uplift) -->
        <g class="eyebrows">
          <path d="M 19.3 21.8 Q 23.5 19.2 27.7 21.4" stroke="#3a3024" stroke-width="1.6" fill="none" stroke-linecap="round" />
          <path d="M 32.3 21.4 Q 36.5 19.2 40.7 21.8" stroke="#3a3024" stroke-width="1.6" fill="none" stroke-linecap="round" />
        </g>

        <!-- Eyes -->
        <g class="pupils" data-prof-feature="pupils" :style="pupilsStyle">
          <ellipse cx="24" cy="27" rx="3.8" ry="3.2" fill="white" />
          <circle cx="24" cy="27" r="2.1" fill="#3b6db0" />
          <circle cx="24" cy="27" r="1.25" fill="#1a2535" />
          <circle cx="25.1" cy="25.9" r="0.7" fill="white" opacity="0.95" />

          <ellipse cx="36" cy="27" rx="3.8" ry="3.2" fill="white" />
          <circle cx="36" cy="27" r="2.1" fill="#3b6db0" />
          <circle cx="36" cy="27" r="1.25" fill="#1a2535" />
          <circle cx="37.1" cy="25.9" r="0.7" fill="white" opacity="0.95" />
        </g>

        <!-- Glasses with cyan glow -->
        <g class="glasses">
          <rect x="19.2" y="23.6" width="9.6" height="7" rx="2.5" fill="rgba(155,213,255,0.14)" stroke="#2c3e50" stroke-width="0.9" />
          <rect x="31.2" y="23.6" width="9.6" height="7" rx="2.5" fill="rgba(155,213,255,0.14)" stroke="#2c3e50" stroke-width="0.9" />
          <line x1="28.8" y1="27" x2="31.2" y2="27" stroke="#2c3e50" stroke-width="0.9" />
          <line x1="19.2" y1="26.6" x2="15" y2="25" stroke="#2c3e50" stroke-width="0.9" stroke-linecap="round" />
          <line x1="40.8" y1="26.6" x2="45" y2="25" stroke="#2c3e50" stroke-width="0.9" stroke-linecap="round" />
          <!-- Cyan glow inside lenses -->
          <circle cx="24" cy="27" r="1.8" fill="rgba(155,213,255,0.22)" />
          <circle cx="36" cy="27" r="1.8" fill="rgba(155,213,255,0.22)" />
          <!-- lens shine -->
          <path d="M 21 25 L 24 25" stroke="#cdecff" stroke-width="0.7" stroke-linecap="round" opacity="0.6" />
          <path d="M 33 25 L 36 25" stroke="#cdecff" stroke-width="0.7" stroke-linecap="round" opacity="0.6" />
        </g>

        <!-- Warm mouth -->
        <path class="mouth" d="M 23.5 33.5 Q 30 38.5 36.5 33.5" stroke="#6b3a20" stroke-width="1.5" fill="none" stroke-linecap="round" />

        <!-- Floating star sparks (power-element) -->
        <g class="energy-glyphs" aria-hidden="true">
          <circle cx="8" cy="16" r="1.4" fill="#bfe6ff" />
          <circle cx="8" cy="16" r="0.6" fill="#fff" />
          <circle cx="52" cy="13" r="1.2" fill="#bfe6ff" />
          <path d="M 51 9 L 51 11 M 50 10 L 52 10" stroke="#fff" stroke-width="0.5" stroke-linecap="round" />
          <circle cx="53" cy="34" r="1" fill="#6fd0ff" />
        </g>

        <!-- Celebrate particles -->
        <g class="celebrate-particles">
          <circle cx="7"  cy="22" r="2"   fill="#9bd5ff" />
          <circle cx="53" cy="18" r="1.6" fill="#fff" />
          <circle cx="30" cy="5"  r="1.8" fill="#3b6db0" />
          <circle cx="5"  cy="42" r="1.5" fill="#f3c7a6" />
          <circle cx="55" cy="40" r="2"   fill="#9bd5ff" />
        </g>
      </svg>
    </div>

    <div v-if="inviteCount > 0" class="chibi-invite-badge" :aria-label="`${inviteCount} Duel-Einladung(en)`">
      {{ inviteCount > 9 ? '9+' : inviteCount }}
    </div>
  </div>
</template>

<script>
import { useA11yStore } from '../stores/a11yStore.js'
import { playWave } from '../utils/character-animations.js'

export default {
	name: 'KosmologeAvatar',
	props: {
		animation: { type: String, default: 'idle' },
		hasMessage: { type: Boolean, default: false },
		inviteCount: { type: Number, default: 0 },
		size: { type: Number, default: 80 },
	},
	data() {
		return {
			mousemoveHandler: null,
			pupilOffsetX: 0,
			pupilOffsetY: 0,
			waveTimer: null,
			waving: false,
		}
	},
	computed: {
		pupilsStyle() {
			return `transform: translate(${this.pupilOffsetX}px, ${this.pupilOffsetY}px);`
		},
		sizeStyle() {
			const px = this.size + 'px'
			return { width: px, height: px }
		},
		avatarSizeStyle() {
			const h = this.size
			const w = Math.round((h * 60) / 80)
			return { width: w + 'px', height: h + 'px' }
		},
	},
	mounted() {
		this.mousemoveHandler = (event) => this.handleMouseMove(event)
		this.$refs.wrapperRef?.addEventListener('mousemove', this.mousemoveHandler)
	},
	beforeUnmount() {
		if (this.mousemoveHandler && this.$refs.wrapperRef) {
			this.$refs.wrapperRef.removeEventListener('mousemove', this.mousemoveHandler)
		}
		if (this.waveTimer) clearTimeout(this.waveTimer)
	},
	methods: {
		animationsDisabled() {
			const prefersReduced = typeof window !== 'undefined'
				&& typeof window.matchMedia === 'function'
				&& window.matchMedia('(prefers-reduced-motion: reduce)').matches
			if (prefersReduced) return true
			try {
				return useA11yStore().animationsEnabled === false
			} catch (e) {
				return false
			}
		},
		handleMouseMove(event) {
			if (this.animationsDisabled()) return
			const wrapper = this.$refs.wrapperRef
			if (!wrapper) return
			const rect = wrapper.getBoundingClientRect()
			if (!rect.width || !rect.height) return
			const centerX = rect.left + rect.width / 2
			const centerY = rect.top + rect.height / 2
			const dx = (event.clientX - centerX) / rect.width
			const dy = (event.clientY - centerY) / rect.height
			this.pupilOffsetX = Math.max(-3, Math.min(3, dx * 6))
			this.pupilOffsetY = Math.max(-2, Math.min(2, dy * 4))
		},
		handleClick() {
			this.$emit('click')
			const arm = this.$refs.armRef
			if (arm) playWave(arm)
			if (this.waveTimer) clearTimeout(this.waveTimer)
			this.waving = true
			this.waveTimer = setTimeout(() => { this.waving = false; this.waveTimer = null }, 1200)
		},
	},
}
</script>

<style scoped>
@import '../styles/chibi-avatar.css';
</style>
