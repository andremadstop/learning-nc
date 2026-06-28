<template>
  <div
    ref="wrapperRef"
    class="popularisierer-avatar-wrapper chibi-avatar-wrapper"
    :class="{ 'is-waving': waving }"
    :style="sizeStyle"
    @click="handleClick">
    <div
      class="popularisierer-avatar chibi-avatar"
      :class="[`animation-${animation}`, { 'is-waving': waving }]"
      :style="avatarSizeStyle">

      <svg
        viewBox="0 0 60 80"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        aria-label="Der Popularisierer">

        <defs>
          <radialGradient id="pop-galaxy" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stop-color="#ffc2ec" stop-opacity="0.98" />
            <stop offset="45%" stop-color="#d44fb0" stop-opacity="0.8" />
            <stop offset="100%" stop-color="#1a2535" stop-opacity="0" />
          </radialGradient>
          <radialGradient id="pop-skin" cx="40%" cy="30%" r="78%">
            <stop offset="0%" stop-color="#ffdcb8" />
            <stop offset="62%" stop-color="#f3c7a6" />
            <stop offset="100%" stop-color="#e0a87f" />
          </radialGradient>
          <linearGradient id="pop-vest" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#bd4a9d" />
            <stop offset="100%" stop-color="#8c2c74" />
          </linearGradient>
          <radialGradient id="pop-aura" cx="50%" cy="42%" r="58%">
            <stop offset="0%" stop-color="#ff7ad6" stop-opacity="0.28" />
            <stop offset="100%" stop-color="#ff7ad6" stop-opacity="0" />
          </radialGradient>
        </defs>

        <!-- Star-glow aura (power-element) -->
        <ellipse cx="30" cy="34" rx="29" ry="32" fill="url(#pop-aura)" />

        <!-- Magenta vest body -->
        <rect x="14" y="40" width="32" height="32" rx="6" fill="url(#pop-vest)" />
        <!-- Vest collar V (deeper magenta) -->
        <path d="M 22 40 L 30 50 L 38 40" fill="#6f1f5e" stroke="#4a1240" stroke-width="0.5" stroke-linejoin="round" />
        <!-- Golden rim light on shoulders -->
        <path d="M 14 46 Q 14 40 20 40 M 40 40 Q 46 40 46 46" fill="none" stroke="#ffd96b" stroke-width="0.9" stroke-linecap="round" opacity="0.5" />
        <!-- Star pattern on vest -->
        <g class="vest-stars">
          <circle cx="20" cy="56" r="1" fill="#ffd96b" />
          <circle cx="40" cy="56" r="1" fill="#ffd96b" />
          <circle cx="22" cy="64" r="0.8" fill="#ffd96b" />
          <circle cx="38" cy="64" r="0.8" fill="#ffd96b" />
          <circle cx="30" cy="68" r="0.9" fill="#ffd96b" />
        </g>

        <!-- Mini-Galaxie statt Buch (brighter Kosmos projection) -->
        <g data-prof-feature="book">
          <circle cx="30" cy="56" r="10" fill="url(#pop-galaxy)" />
          <!-- Orbital ring -->
          <ellipse cx="30" cy="56" rx="11" ry="3.6" fill="none" stroke="#ffd0f0" stroke-width="0.8" opacity="0.75" transform="rotate(16 30 56)" />
          <circle cx="26" cy="53" r="0.8" fill="#fff" />
          <circle cx="33" cy="55" r="0.6" fill="#ffd96b" />
          <circle cx="29" cy="59" r="0.5" fill="#fff" />
          <circle cx="35" cy="51" r="0.7" fill="#ffd0f0" />
          <circle cx="24" cy="58" r="0.5" fill="#ffd96b" />
          <circle cx="31" cy="52" r="0.5" fill="#fff" />
          <!-- Hands holding the galaxy -->
          <ellipse cx="19.5" cy="60" rx="3.2" ry="2.4" fill="url(#pop-skin)" />
          <ellipse cx="40.5" cy="60" rx="3.2" ry="2.4" fill="url(#pop-skin)" />
        </g>

        <!-- Wave-Arm -->
        <g ref="armRef" class="wave-arm">
          <path d="M 42 42 Q 52 34 50 24" stroke="#f0bf9a" stroke-width="4.5" fill="none" stroke-linecap="round" />
          <circle cx="50" cy="22" r="4" fill="url(#pop-skin)" />
        </g>

        <!-- Head -->
        <circle cx="30" cy="28" r="16" fill="url(#pop-skin)" />
        <ellipse cx="14.5" cy="28" rx="2" ry="2.8" fill="#e6ab82" />
        <ellipse cx="45.5" cy="28" rx="2" ry="2.8" fill="#e6ab82" />
        <!-- Warm cheeks -->
        <ellipse cx="21" cy="32.5" rx="2.6" ry="1.7" fill="#f0a07a" opacity="0.45" />
        <ellipse cx="39" cy="32.5" rx="2.6" ry="1.7" fill="#f0a07a" opacity="0.45" />

        <!-- Hair (short, dark with magenta highlight) -->
        <g class="hair">
          <path d="M 16 18 Q 18 8 26 9 Q 30 5 34 9 Q 42 8 44 18 Q 44 22 42 22 L 18 22 Q 16 22 16 18 Z"
                fill="#2a2326" stroke="#16110f" stroke-width="0.6" stroke-linejoin="round" />
          <!-- Magenta streak -->
          <path d="M 30 9 Q 33 6 37 12" stroke="#d44fb0" stroke-width="1.7" fill="none" stroke-linecap="round" />
          <!-- highlight sheen -->
          <path d="M 20 13 Q 26 9 32 12" fill="none" stroke="#4a4045" stroke-width="1" stroke-linecap="round" opacity="0.7" />
          <!-- Sideburn hint -->
          <path d="M 16 22 Q 14 28 17 30" stroke="#2a2326" stroke-width="2" fill="none" stroke-linecap="round" />
          <path d="M 44 22 Q 46 28 43 30" stroke="#2a2326" stroke-width="2" fill="none" stroke-linecap="round" />
        </g>

        <!-- Eyebrows (warm, slightly raised) -->
        <g class="eyebrows">
          <path d="M 19.3 21.3 Q 23.5 18.3 27.7 20.8" stroke="#2a2326" stroke-width="1.7" fill="none" stroke-linecap="round" />
          <path d="M 32.3 20.8 Q 36.5 18.3 40.7 21.3" stroke="#2a2326" stroke-width="1.7" fill="none" stroke-linecap="round" />
        </g>

        <!-- Eyes (forward, engaged) -->
        <g class="pupils" data-prof-feature="pupils" :style="pupilsStyle">
          <ellipse cx="24" cy="27" rx="3.8" ry="3.2" fill="white" />
          <circle cx="24" cy="27" r="2.1" fill="#7c4a2a" />
          <circle cx="24" cy="27" r="1.25" fill="#1a2535" />
          <circle cx="25.1" cy="25.9" r="0.7" fill="white" opacity="0.95" />

          <ellipse cx="36" cy="27" rx="3.8" ry="3.2" fill="white" />
          <circle cx="36" cy="27" r="2.1" fill="#7c4a2a" />
          <circle cx="36" cy="27" r="1.25" fill="#1a2535" />
          <circle cx="37.1" cy="25.9" r="0.7" fill="white" opacity="0.95" />
        </g>

        <!-- Smile (warm-charismatic) -->
        <path class="mouth" d="M 23 33.5 Q 30 39 37 33.5" stroke="#6b3a20" stroke-width="1.6" fill="none" stroke-linecap="round" />
        <!-- Mouth highlight tooth -->
        <path d="M 28 35.5 L 32 35.5" stroke="rgba(255,255,255,0.6)" stroke-width="0.8" stroke-linecap="round" />

        <!-- Goatee / Kinnbart (small triangle below mouth) -->
        <g class="goatee">
          <path d="M 27 38 Q 30 44 33 38 Q 31 41 30 41 Q 29 41 27 38 Z" fill="#2a2326" />
        </g>

        <!-- Floating star sparks (power-element) -->
        <g class="energy-glyphs" aria-hidden="true">
          <path d="M 8 15 L 8 12 M 6.5 13.5 L 9.5 13.5" stroke="#ffd96b" stroke-width="0.7" stroke-linecap="round" />
          <circle cx="52" cy="13" r="1.3" fill="#ffd0f0" />
          <circle cx="52" cy="13" r="0.5" fill="#fff" />
          <path d="M 53 33 L 53 31 M 52 32 L 54 32" stroke="#ffd96b" stroke-width="0.6" stroke-linecap="round" />
        </g>

        <!-- Celebrate particles -->
        <g class="celebrate-particles">
          <circle cx="7"  cy="22" r="2"   fill="#ff7ad6" />
          <circle cx="53" cy="18" r="1.6" fill="#ffd96b" />
          <circle cx="30" cy="5"  r="1.8" fill="#a83a8a" />
          <circle cx="5"  cy="42" r="1.5" fill="#ffd96b" />
          <circle cx="55" cy="40" r="2"   fill="#ff7ad6" />
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
	name: 'PopularisiererAvatar',
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
