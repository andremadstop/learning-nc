<template>
  <div
    ref="wrapperRef"
    class="theoretiker-avatar-wrapper chibi-avatar-wrapper"
    :class="{ 'is-waving': waving }"
    :style="sizeStyle"
    @click="handleClick">
    <div
      class="theoretiker-avatar chibi-avatar"
      :class="[`animation-${animation}`, { 'is-waving': waving }]"
      :style="avatarSizeStyle">

      <svg
        viewBox="0 0 60 80"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        aria-label="Der Theoretiker">

        <defs>
          <radialGradient id="th-skin" cx="40%" cy="30%" r="78%">
            <stop offset="0%" stop-color="#ffdcb8" />
            <stop offset="62%" stop-color="#f3c7a6" />
            <stop offset="100%" stop-color="#e0a87f" />
          </radialGradient>
          <linearGradient id="th-cardigan" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#d6a86d" />
            <stop offset="100%" stop-color="#a9783c" />
          </linearGradient>
          <linearGradient id="th-hair" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#dcdcdc" />
            <stop offset="100%" stop-color="#8c8c8c" />
          </linearGradient>
          <radialGradient id="th-energy" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stop-color="#d8f25f" stop-opacity="0.85" />
            <stop offset="100%" stop-color="#d8f25f" stop-opacity="0" />
          </radialGradient>
        </defs>

        <!-- Cardigan body -->
        <rect x="14" y="40" width="32" height="32" rx="6" fill="url(#th-cardigan)" />
        <!-- Cardigan shading -->
        <path d="M 14 58 Q 30 64 46 58 L 46 66 Q 46 72 40 72 L 20 72 Q 14 72 14 66 Z" fill="#000" opacity="0.08" />
        <!-- Glowing hem accent (power-element) -->
        <path d="M 16 70 Q 30 74 44 70" fill="none" stroke="#d8f25f" stroke-width="1.1" stroke-linecap="round" opacity="0.85" />
        <!-- Cardigan collar V -->
        <path d="M 22 40 L 30 50 L 38 40" fill="#8a6233" stroke="#6b4a26" stroke-width="0.6" stroke-linejoin="round" />
        <!-- Glowing trim along the V opening -->
        <path d="M 22.5 40.5 L 30 49.5 L 37.5 40.5" fill="none" stroke="#d8f25f" stroke-width="0.7" stroke-linecap="round" opacity="0.7" />
        <!-- Buttons -->
        <circle cx="30" cy="55" r="0.9" fill="#6b4a26" />
        <circle cx="30" cy="61" r="0.9" fill="#6b4a26" />
        <!-- Shirt collar accent (cream) -->
        <path d="M 25 41 L 30 48 L 35 41" fill="#f7edd1" />

        <!-- Notizblock + Kreide (held in hands) -->
        <g data-prof-feature="book">
          <!-- Block -->
          <rect x="16" y="50" width="28" height="13" rx="2" fill="#f7edd1" stroke="#7a5230" stroke-width="0.9" />
          <!-- Lines -->
          <path
            d="M 19 54 L 27 54 M 33 54 L 41 54 M 19 58 L 27 58 M 33 58 L 41 58"
            stroke="rgba(122,82,48,0.5)" stroke-width="0.7" stroke-linecap="round" />
          <!-- Glyph (decorative π-like, glowing) -->
          <text x="30" y="60" text-anchor="middle" font-size="6.5" font-weight="bold" fill="#7c9a2f" font-family="serif">π</text>
          <!-- Kreide-Stick to the right -->
          <rect x="44.5" y="55" width="6" height="2" rx="0.5" fill="#f7edd1" stroke="#7a5230" stroke-width="0.4" transform="rotate(20 47 56)" />
        </g>

        <!-- Wave-Arm (Kreide-Hand) -->
        <g ref="armRef" class="wave-arm">
          <path d="M 42 42 Q 52 34 50 24" stroke="#f0bf9a" stroke-width="4.5" fill="none" stroke-linecap="round" />
          <circle cx="50" cy="22" r="4" fill="url(#th-skin)" />
          <!-- Kreide tip -->
          <rect x="48" y="18" width="2" height="6" rx="0.4" fill="#fff" stroke="#7a5230" stroke-width="0.4" />
        </g>

        <!-- Side hair masses behind head (drawn first, wild bald-top professor) -->
        <g class="hair">
          <!-- Left fluffy cloud -->
          <path d="M 18 17 Q 8 15 7 23 Q 3 26 6 31 Q 4 36 11 35 Q 8 30 14 30 Q 10 24 17 25 Q 13 19 19 21 Z"
                fill="url(#th-hair)" stroke="#6c6c6c" stroke-width="0.5" stroke-linejoin="round" />
          <!-- Right fluffy cloud -->
          <path d="M 42 17 Q 52 15 53 23 Q 57 26 54 31 Q 56 36 49 35 Q 52 30 46 30 Q 50 24 43 25 Q 47 19 41 21 Z"
                fill="url(#th-hair)" stroke="#6c6c6c" stroke-width="0.5" stroke-linejoin="round" />
        </g>

        <!-- Head -->
        <circle cx="30" cy="28" r="16" fill="url(#th-skin)" />
        <ellipse cx="14.5" cy="28" rx="2" ry="2.8" fill="#e6ab82" />
        <ellipse cx="45.5" cy="28" rx="2" ry="2.8" fill="#e6ab82" />
        <!-- Warm cheeks -->
        <ellipse cx="21" cy="32.5" rx="2.6" ry="1.7" fill="#f0a07a" opacity="0.45" />
        <ellipse cx="39" cy="32.5" rx="2.6" ry="1.7" fill="#f0a07a" opacity="0.45" />

        <!-- Wild top wisps over the bald forehead -->
        <g class="hair">
          <path d="M 20 16 Q 15 7 22 7" fill="none" stroke="url(#th-hair)" stroke-width="2.2" stroke-linecap="round" />
          <path d="M 26 13 Q 25 5 31 8" fill="none" stroke="url(#th-hair)" stroke-width="2.2" stroke-linecap="round" />
          <path d="M 34 13 Q 35 5 40 9" fill="none" stroke="url(#th-hair)" stroke-width="2.2" stroke-linecap="round" />
          <path d="M 40 16 Q 46 8 44 16" fill="none" stroke="url(#th-hair)" stroke-width="2" stroke-linecap="round" />
          <!-- white highlight strands -->
          <path d="M 9 25 Q 7 29 10 32" fill="none" stroke="#f0f0f0" stroke-width="0.7" stroke-linecap="round" opacity="0.8" />
          <path d="M 51 25 Q 53 29 50 32" fill="none" stroke="#f0f0f0" stroke-width="0.7" stroke-linecap="round" opacity="0.8" />
        </g>

        <!-- Bushy eyebrows -->
        <g class="eyebrows">
          <path d="M 18 20.5 Q 23 17.3 28 20.6" stroke="#777" stroke-width="2.4" fill="none" stroke-linecap="round" />
          <path d="M 32 20.6 Q 37 17.3 42 20.5" stroke="#777" stroke-width="2.4" fill="none" stroke-linecap="round" />
        </g>

        <!-- Eyes -->
        <g class="pupils" data-prof-feature="pupils" :style="pupilsStyle">
          <ellipse cx="24" cy="27" rx="3.8" ry="3.2" fill="white" />
          <circle cx="24" cy="27" r="2.1" fill="#6f8a3f" />
          <circle cx="24" cy="27" r="1.25" fill="#1a2535" />
          <circle cx="25.1" cy="25.9" r="0.7" fill="white" opacity="0.95" />

          <ellipse cx="36" cy="27" rx="3.8" ry="3.2" fill="white" />
          <circle cx="36" cy="27" r="2.1" fill="#6f8a3f" />
          <circle cx="36" cy="27" r="1.25" fill="#1a2535" />
          <circle cx="37.1" cy="25.9" r="0.7" fill="white" opacity="0.95" />
        </g>

        <!-- Glasses -->
        <g class="glasses">
          <rect x="19.2" y="23.6" width="9.6" height="7" rx="2.4" fill="rgba(255,255,255,0.06)" stroke="#3a2c1a" stroke-width="0.9" />
          <rect x="31.2" y="23.6" width="9.6" height="7" rx="2.4" fill="rgba(255,255,255,0.06)" stroke="#3a2c1a" stroke-width="0.9" />
          <line x1="28.8" y1="27" x2="31.2" y2="27" stroke="#3a2c1a" stroke-width="0.9" />
          <line x1="19.2" y1="26.4" x2="15" y2="25" stroke="#3a2c1a" stroke-width="0.9" stroke-linecap="round" />
          <line x1="40.8" y1="26.4" x2="45" y2="25" stroke="#3a2c1a" stroke-width="0.9" stroke-linecap="round" />
          <!-- lens shine -->
          <path d="M 21 25 L 24 25" stroke="#fff" stroke-width="0.7" stroke-linecap="round" opacity="0.5" />
          <path d="M 33 25 L 36 25" stroke="#fff" stroke-width="0.7" stroke-linecap="round" opacity="0.5" />
        </g>

        <!-- Bushy mustache (covers mouth) -->
        <g class="mouth">
          <path d="M 20.5 35 Q 26 39.5 30 36.2 Q 34 39.5 39.5 35 Q 36.5 41.5 30 40.3 Q 23.5 41.5 20.5 35 Z"
                fill="url(#th-hair)" stroke="#6c6c6c" stroke-width="0.5" stroke-linejoin="round" />
          <path d="M 24 36.5 Q 27 38.5 30 37" fill="none" stroke="#eee" stroke-width="0.5" stroke-linecap="round" opacity="0.6" />
        </g>

        <!-- Floating formula glyphs (power-element, electric yellow-green) -->
        <g class="energy-glyphs" aria-hidden="true">
          <circle cx="9" cy="14" r="3.4" fill="url(#th-energy)" />
          <text x="9" y="16" text-anchor="middle" font-size="5" font-weight="bold" fill="#aee03a" font-family="serif">∑</text>
          <circle cx="51" cy="12" r="3.2" fill="url(#th-energy)" />
          <text x="51" y="14" text-anchor="middle" font-size="4.6" font-weight="bold" fill="#aee03a" font-family="serif">∞</text>
          <circle cx="53" cy="31" r="2.6" fill="url(#th-energy)" />
          <text x="53" y="32.6" text-anchor="middle" font-size="3.8" font-weight="bold" fill="#aee03a" font-family="serif">π</text>
        </g>

        <!-- Celebrate particles -->
        <g class="celebrate-particles">
          <circle cx="7"  cy="22" r="2"   fill="#f2c230" />
          <circle cx="53" cy="18" r="1.6" fill="#aee03a" />
          <circle cx="30" cy="5"  r="1.8" fill="#dcdcdc" />
          <circle cx="5"  cy="42" r="1.5" fill="#c89760" />
          <circle cx="55" cy="40" r="2"   fill="#aee03a" />
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
	name: 'TheoretikerAvatar',
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
