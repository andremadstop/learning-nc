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
          <radialGradient id="kosmologe-galaxy" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stop-color="#9bd5ff" stop-opacity="0.95" />
            <stop offset="55%" stop-color="#3b6db0" stop-opacity="0.55" />
            <stop offset="100%" stop-color="#1a2535" stop-opacity="0" />
          </radialGradient>
        </defs>

        <!-- Sweater body (deep navy) -->
        <rect x="14" y="40" width="32" height="32" rx="6" fill="#2c3a5c" />
        <!-- Shirt collar peeking through V -->
        <path d="M 22 40 L 30 48 L 38 40" fill="#dfe7f2" stroke="#a9b4c8" stroke-width="0.5" stroke-linejoin="round" />
        <!-- Sweater cable-knit hint -->
        <path d="M 22 56 L 22 70 M 26 56 L 26 70 M 30 56 L 30 70 M 34 56 L 34 70 M 38 56 L 38 70"
              stroke="rgba(255,255,255,0.08)" stroke-width="0.7" />

        <!-- Mini-Galaxie statt Buch -->
        <g data-prof-feature="book">
          <circle cx="30" cy="56" r="9" fill="url(#kosmologe-galaxy)" />
          <!-- Stars on top of galaxy -->
          <circle cx="26" cy="53" r="0.8" fill="#fff" />
          <circle cx="33" cy="55" r="0.6" fill="#fff" />
          <circle cx="29" cy="59" r="0.5" fill="#fff" />
          <circle cx="35" cy="51" r="0.7" fill="#9bd5ff" />
          <circle cx="24" cy="58" r="0.5" fill="#9bd5ff" />
          <!-- Hands holding the galaxy -->
          <ellipse cx="20" cy="60" rx="3.2" ry="2.4" fill="#f3c7a6" />
          <ellipse cx="40" cy="60" rx="3.2" ry="2.4" fill="#f3c7a6" />
        </g>

        <!-- Wave-Arm -->
        <g ref="armRef" class="wave-arm">
          <path d="M 42 42 Q 52 34 50 24" stroke="#f3c7a6" stroke-width="4.5" fill="none" stroke-linecap="round" />
          <circle cx="50" cy="22" r="4" fill="#f3c7a6" />
        </g>

        <!-- Head -->
        <circle cx="30" cy="28" r="16" fill="#f3c7a6" />
        <ellipse cx="14.5" cy="28" rx="2" ry="2.8" fill="#ecb48f" />
        <ellipse cx="45.5" cy="28" rx="2" ry="2.8" fill="#ecb48f" />

        <!-- Hair (short, dark, with subtle wave) -->
        <g class="hair">
          <path d="M 16 18 Q 18 8 26 10 Q 30 6 34 10 Q 42 8 44 18 Q 44 22 42 22 L 18 22 Q 16 22 16 18 Z"
                fill="#3a3024" stroke="#241c12" stroke-width="0.6" stroke-linejoin="round" />
          <!-- Sideburn hint -->
          <path d="M 16 22 Q 14 28 17 30" stroke="#3a3024" stroke-width="2" fill="none" stroke-linecap="round" />
          <path d="M 44 22 Q 46 28 43 30" stroke="#3a3024" stroke-width="2" fill="none" stroke-linecap="round" />
        </g>

        <!-- Eyebrows (warm, slight uplift) -->
        <g class="eyebrows">
          <path d="M 19.5 22 Q 23.5 19.5 27.5 21.5" stroke="#3a3024" stroke-width="1.5" fill="none" stroke-linecap="round" />
          <path d="M 32.5 21.5 Q 36.5 19.5 40.5 22" stroke="#3a3024" stroke-width="1.5" fill="none" stroke-linecap="round" />
        </g>

        <!-- Eyes -->
        <g class="pupils" data-prof-feature="pupils" :style="pupilsStyle">
          <ellipse cx="24" cy="27" rx="3.6" ry="3.0" fill="white" />
          <circle cx="24" cy="27" r="2.0" fill="#3b6db0" />
          <circle cx="24" cy="27" r="1.2" fill="#1a2535" />
          <circle cx="25" cy="26" r="0.6" fill="white" opacity="0.9" />

          <ellipse cx="36" cy="27" rx="3.6" ry="3.0" fill="white" />
          <circle cx="36" cy="27" r="2.0" fill="#3b6db0" />
          <circle cx="36" cy="27" r="1.2" fill="#1a2535" />
          <circle cx="37" cy="26" r="0.6" fill="white" opacity="0.9" />
        </g>

        <!-- Glasses with subtle cyan glow -->
        <g class="glasses">
          <rect x="19.2" y="23.6" width="9.6" height="7" rx="2.5" fill="rgba(155,213,255,0.10)" stroke="#2c3e50" stroke-width="0.9" />
          <rect x="31.2" y="23.6" width="9.6" height="7" rx="2.5" fill="rgba(155,213,255,0.10)" stroke="#2c3e50" stroke-width="0.9" />
          <line x1="28.8" y1="27" x2="31.2" y2="27" stroke="#2c3e50" stroke-width="0.9" />
          <line x1="19.2" y1="26.6" x2="15" y2="25" stroke="#2c3e50" stroke-width="0.9" stroke-linecap="round" />
          <line x1="40.8" y1="26.6" x2="45" y2="25" stroke="#2c3e50" stroke-width="0.9" stroke-linecap="round" />
          <!-- Cyan glow inside lenses -->
          <circle cx="24" cy="27" r="1.6" fill="rgba(155,213,255,0.18)" />
          <circle cx="36" cy="27" r="1.6" fill="rgba(155,213,255,0.18)" />
        </g>

        <!-- Warm mouth -->
        <path class="mouth" d="M 23.5 33.5 Q 30 38.5 36.5 33.5" stroke="#6b3a20" stroke-width="1.5" fill="none" stroke-linecap="round" />

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
