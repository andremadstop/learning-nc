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

        <!-- Cardigan body -->
        <rect x="14" y="40" width="32" height="32" rx="6" fill="#c89760" />
        <!-- Cardigan collar V -->
        <path d="M 22 40 L 30 50 L 38 40" fill="#a07239" stroke="#7a5230" stroke-width="0.6" stroke-linejoin="round" />
        <!-- Buttons -->
        <circle cx="30" cy="54" r="0.9" fill="#7a5230" />
        <circle cx="30" cy="60" r="0.9" fill="#7a5230" />
        <circle cx="30" cy="66" r="0.9" fill="#7a5230" />
        <!-- Shirt collar accent (cream) -->
        <path d="M 25 41 L 30 48 L 35 41" fill="#f5e9c5" />

        <!-- Notizblock + Kreide (held in hands) -->
        <g data-prof-feature="book">
          <!-- Block -->
          <rect x="16" y="50" width="28" height="13" rx="2" fill="#f5e9c5" stroke="#7a5230" stroke-width="0.9" />
          <!-- Lines -->
          <path
            d="M 19 54 L 27 54 M 33 54 L 41 54 M 19 58 L 27 58 M 33 58 L 41 58"
            stroke="rgba(122,82,48,0.55)" stroke-width="0.7" stroke-linecap="round" />
          <!-- Glyph (decorative π-like) -->
          <text x="30" y="60" text-anchor="middle" font-size="6" font-weight="bold" fill="#7a5230" font-family="serif">π</text>
          <!-- Kreide-Stick to the right -->
          <rect x="44.5" y="55" width="6" height="2" rx="0.5" fill="#f5e9c5" stroke="#7a5230" stroke-width="0.4" transform="rotate(20 47 56)" />
        </g>

        <!-- Wave-Arm (Kreide-Hand) -->
        <g ref="armRef" class="wave-arm">
          <path d="M 42 42 Q 52 34 50 24" stroke="#f3c7a6" stroke-width="4.5" fill="none" stroke-linecap="round" />
          <circle cx="50" cy="22" r="4" fill="#f3c7a6" />
          <!-- Kreide tip -->
          <rect x="48" y="18" width="2" height="6" rx="0.4" fill="#fff" stroke="#7a5230" stroke-width="0.4" />
        </g>

        <!-- Head -->
        <circle cx="30" cy="28" r="16" fill="#f3c7a6" />
        <ellipse cx="14.5" cy="28" rx="2" ry="2.8" fill="#ecb48f" />
        <ellipse cx="45.5" cy="28" rx="2" ry="2.8" fill="#ecb48f" />

        <!-- Wild grey hair tufts -->
        <g class="hair">
          <path d="M 16 16 Q 12 10 19 6 Q 22 2 26 8" fill="#9a9a9a" stroke="#6c6c6c" stroke-width="0.6" stroke-linejoin="round" />
          <path d="M 24 6 Q 30 0 36 7 Q 38 4 40 9" fill="#9a9a9a" stroke="#6c6c6c" stroke-width="0.6" stroke-linejoin="round" />
          <path d="M 38 8 Q 46 6 47 18 Q 50 14 46 24" fill="#9a9a9a" stroke="#6c6c6c" stroke-width="0.6" stroke-linejoin="round" />
          <!-- Sideburns hint -->
          <path d="M 14 22 Q 12 28 17 30" stroke="#9a9a9a" stroke-width="2.5" fill="none" stroke-linecap="round" />
          <path d="M 46 22 Q 48 28 43 30" stroke="#9a9a9a" stroke-width="2.5" fill="none" stroke-linecap="round" />
        </g>

        <!-- Bushy eyebrows -->
        <g class="eyebrows">
          <path d="M 18.5 21 Q 23 18 27.5 21" stroke="#5a5a5a" stroke-width="2.2" fill="none" stroke-linecap="round" />
          <path d="M 32.5 21 Q 37 18 41.5 21" stroke="#5a5a5a" stroke-width="2.2" fill="none" stroke-linecap="round" />
        </g>

        <!-- Eyes -->
        <g class="pupils" data-prof-feature="pupils" :style="pupilsStyle">
          <ellipse cx="24" cy="27" rx="3.4" ry="2.9" fill="white" />
          <circle cx="24" cy="27" r="1.9" fill="#6f8a3f" />
          <circle cx="24" cy="27" r="1.2" fill="#1a2535" />
          <circle cx="25" cy="26" r="0.55" fill="white" opacity="0.9" />

          <ellipse cx="36" cy="27" rx="3.4" ry="2.9" fill="white" />
          <circle cx="36" cy="27" r="1.9" fill="#6f8a3f" />
          <circle cx="36" cy="27" r="1.2" fill="#1a2535" />
          <circle cx="37" cy="26" r="0.55" fill="white" opacity="0.9" />
        </g>

        <!-- Glasses -->
        <g class="glasses">
          <rect x="19.4" y="23.8" width="9.2" height="6.8" rx="2.2" fill="rgba(255,255,255,0.05)" stroke="#3a2c1a" stroke-width="0.9" />
          <rect x="31.4" y="23.8" width="9.2" height="6.8" rx="2.2" fill="rgba(255,255,255,0.05)" stroke="#3a2c1a" stroke-width="0.9" />
          <line x1="28.6" y1="27" x2="31.4" y2="27" stroke="#3a2c1a" stroke-width="0.9" />
          <line x1="19.4" y1="26.6" x2="15" y2="25" stroke="#3a2c1a" stroke-width="0.9" stroke-linecap="round" />
          <line x1="40.6" y1="26.6" x2="45" y2="25" stroke="#3a2c1a" stroke-width="0.9" stroke-linecap="round" />
        </g>

        <!-- Bushy mustache (covers mouth) -->
        <g class="mouth">
          <path d="M 21 35 Q 26 39 30 36 Q 34 39 39 35 Q 36 41 30 40 Q 24 41 21 35 Z"
                fill="#9a9a9a" stroke="#6c6c6c" stroke-width="0.6" stroke-linejoin="round" />
        </g>

        <!-- Celebrate particles -->
        <g class="celebrate-particles">
          <circle cx="7"  cy="22" r="2"   fill="#f2c230" />
          <circle cx="53" cy="18" r="1.6" fill="#7c9a3f" />
          <circle cx="30" cy="5"  r="1.8" fill="#9a9a9a" />
          <circle cx="5"  cy="42" r="1.5" fill="#c89760" />
          <circle cx="55" cy="40" r="2"   fill="#6f8a3f" />
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
