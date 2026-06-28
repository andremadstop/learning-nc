<template>
  <div
    ref="wrapperRef"
    class="nova-avatar-wrapper chibi-avatar-wrapper"
    :class="{ 'is-waving': waving }"
    :style="sizeStyle"
    @click="handleClick">
    <div
      class="nova-avatar chibi-avatar"
      :class="[`animation-${animation}`, { 'has-message': hasMessage, 'is-waving': waving }]"
      :style="avatarSizeStyle">

      <svg
        viewBox="0 0 60 80"
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        aria-label="NOVA">

        <defs>
          <radialGradient id="nova-skin" cx="40%" cy="30%" r="78%">
            <stop offset="0%" stop-color="#ffe0c4" />
            <stop offset="62%" stop-color="#f3c7a6" />
            <stop offset="100%" stop-color="#e0a87f" />
          </radialGradient>
          <linearGradient id="nova-suit" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#2a8aa8" />
            <stop offset="100%" stop-color="#15596e" />
          </linearGradient>
          <linearGradient id="nova-hair" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#234653" />
            <stop offset="100%" stop-color="#102730" />
          </linearGradient>
          <radialGradient id="nova-aura" cx="50%" cy="42%" r="58%">
            <stop offset="0%" stop-color="#45e6ff" stop-opacity="0.30" />
            <stop offset="100%" stop-color="#45e6ff" stop-opacity="0" />
          </radialGradient>
          <radialGradient id="nova-cube-glow" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stop-color="#8af3ff" stop-opacity="0.55" />
            <stop offset="100%" stop-color="#8af3ff" stop-opacity="0" />
          </radialGradient>
        </defs>

        <!-- AR presence aura -->
        <ellipse cx="30" cy="34" rx="29" ry="32" fill="url(#nova-aura)" />

        <!-- Tech hoodie body -->
        <rect x="14" y="40" width="32" height="32" rx="6" fill="url(#nova-suit)" />
        <!-- Cyan shoulder rim light -->
        <path d="M 14 46 Q 14 40 20 40 M 40 40 Q 46 40 46 46" fill="none" stroke="#7df0ff" stroke-width="0.9" stroke-linecap="round" opacity="0.6" />
        <!-- Hood collar V -->
        <path d="M 23 40 L 30 47 L 37 40" fill="#0f4456" stroke="#0a3543" stroke-width="0.5" stroke-linejoin="round" />
        <!-- Glowing core emblem (hexagon) -->
        <path d="M 30 42.6 L 32.4 44 L 32.4 46.8 L 30 48.2 L 27.6 46.8 L 27.6 44 Z"
              fill="rgba(125,240,255,0.18)" stroke="#7df0ff" stroke-width="0.7" stroke-linejoin="round" />
        <circle cx="30" cy="45.4" r="0.9" fill="#bdf7ff" />

        <!-- Holo-projection cube (held between hands) -->
        <g data-prof-feature="book">
          <circle cx="30" cy="58" r="8.5" fill="url(#nova-cube-glow)" />
          <!-- isometric cube wireframe -->
          <path d="M 30 53 L 34.3 55.5 L 34.3 60.5 L 30 63 L 25.7 60.5 L 25.7 55.5 Z"
                fill="rgba(125,240,255,0.12)" stroke="#8af3ff" stroke-width="1" stroke-linejoin="round" />
          <path d="M 30 58 L 34.3 55.5 M 30 58 L 25.7 55.5 M 30 58 L 30 63"
                fill="none" stroke="#8af3ff" stroke-width="0.8" stroke-linecap="round" />
          <!-- sparkles -->
          <circle cx="36" cy="53" r="0.6" fill="#eafdff" />
          <circle cx="24" cy="62" r="0.5" fill="#bdf7ff" />
          <!-- Hands presenting the projection -->
          <ellipse cx="19.5" cy="61" rx="3.2" ry="2.4" fill="url(#nova-skin)" />
          <ellipse cx="40.5" cy="61" rx="3.2" ry="2.4" fill="url(#nova-skin)" />
        </g>

        <!-- Wave-Arm (cyan sleeve) -->
        <g ref="armRef" class="wave-arm">
          <path d="M 42 42 Q 52 34 50 24" stroke="#1f7e9b" stroke-width="4.5" fill="none" stroke-linecap="round" />
          <circle cx="50" cy="22" r="4" fill="url(#nova-skin)" />
        </g>

        <!-- Head -->
        <circle cx="30" cy="28" r="16" fill="url(#nova-skin)" />
        <ellipse cx="14.5" cy="28" rx="2" ry="2.8" fill="#e6ab82" />
        <ellipse cx="45.5" cy="28" rx="2" ry="2.8" fill="#e6ab82" />
        <!-- Cyan tech cheek indicators -->
        <rect x="20.4" y="31.6" width="2.2" height="1.3" rx="0.65" fill="#5fdcf2" opacity="0.85" />
        <rect x="37.4" y="31.6" width="2.2" height="1.3" rx="0.65" fill="#5fdcf2" opacity="0.85" />

        <!-- Swept tech hair -->
        <g class="hair">
          <path d="M 15 25 Q 12 9 30 8 Q 48 9 45 25 Q 43.5 16 38 16 Q 41 12.5 33 13 Q 35 10 28.5 12 Q 24 10.5 22 15 Q 17.5 15.5 15 25 Z"
                fill="url(#nova-hair)" stroke="#0c2028" stroke-width="0.5" stroke-linejoin="round" />
          <!-- cyan highlight streak -->
          <path d="M 22 14 Q 28 10.5 35 12.5" fill="none" stroke="#5fdcf2" stroke-width="1.1" stroke-linecap="round" opacity="0.85" />
        </g>

        <!-- AR headband + earpiece -->
        <g class="headset" aria-hidden="true">
          <path d="M 15 23 Q 30 13 45 23" fill="none" stroke="#3fc4de" stroke-width="1.3" stroke-linecap="round" opacity="0.9" />
          <ellipse cx="14.2" cy="28" rx="2.4" ry="3.2" fill="#11515f" stroke="#3fc4de" stroke-width="0.6" />
          <circle cx="14.2" cy="28" r="1" fill="#7df0ff" />
        </g>

        <!-- Antenna with glowing tip -->
        <g class="antenna" aria-hidden="true">
          <path d="M 31 9 Q 33 4.5 34.5 3.5" fill="none" stroke="#234653" stroke-width="1" stroke-linecap="round" />
          <circle cx="35" cy="3" r="2.4" fill="url(#nova-cube-glow)" />
          <circle cx="35" cy="3" r="1.2" fill="#7df0ff" />
        </g>

        <!-- Eyebrows (emotion-aware) -->
        <g class="eyebrows">
          <path :d="browPaths[0]" stroke="#1c3a44" stroke-width="1.4" fill="none" stroke-linecap="round" />
          <path :d="browPaths[1]" stroke="#1c3a44" stroke-width="1.4" fill="none" stroke-linecap="round" />
        </g>

        <!-- Glowing cyan eyes (emotion-aware) -->
        <g class="pupils" data-prof-feature="pupils" :style="pupilsStyle">
          <!-- sleep: closed dashes -->
          <template v-if="face === 'sleep'">
            <path d="M 20.5 27 L 27.5 27" stroke="#17c2e6" stroke-width="1.7" stroke-linecap="round" />
            <path d="M 32.5 27 L 39.5 27" stroke="#17c2e6" stroke-width="1.7" stroke-linecap="round" />
          </template>
          <!-- happy / celebrate: upward arcs (^ ^) -->
          <template v-else-if="face === 'happy' || face === 'celebrate'">
            <circle cx="24" cy="27" r="3.2" fill="#2bd0ee" opacity="0.22" />
            <circle cx="36" cy="27" r="3.2" fill="#2bd0ee" opacity="0.22" />
            <path d="M 20.5 28.6 Q 24 23.8 27.5 28.6" stroke="#17c2e6" stroke-width="1.9" fill="none" stroke-linecap="round" />
            <path d="M 32.5 28.6 Q 36 23.8 39.5 28.6" stroke="#17c2e6" stroke-width="1.9" fill="none" stroke-linecap="round" />
          </template>
          <!-- round eyes: neutral / surprised / sad -->
          <template v-else>
            <circle cx="24" cy="27" :r="eyeGlowR" fill="#2bd0ee" opacity="0.25" />
            <ellipse cx="24" cy="27" :rx="eyeRx" :ry="eyeRy" fill="white" />
            <circle cx="24" :cy="pupilCy" r="2.2" fill="#17c2e6" />
            <circle cx="24" :cy="pupilCy" r="1.2" fill="#0a3a47" />
            <circle cx="25.1" :cy="pupilCy - 1.1" r="0.75" fill="white" opacity="0.95" />

            <circle cx="36" cy="27" :r="eyeGlowR" fill="#2bd0ee" opacity="0.25" />
            <ellipse cx="36" cy="27" :rx="eyeRx" :ry="eyeRy" fill="white" />
            <circle cx="36" :cy="pupilCy" r="2.2" fill="#17c2e6" />
            <circle cx="36" :cy="pupilCy" r="1.2" fill="#0a3a47" />
            <circle cx="37.1" :cy="pupilCy - 1.1" r="0.75" fill="white" opacity="0.95" />
          </template>
        </g>

        <!-- Mouth (emotion-aware) -->
        <ellipse v-if="face === 'surprised'" class="mouth" cx="30" cy="35.6" rx="2" ry="2.6" fill="#2a4a55" />
        <path v-else class="mouth" :d="mouthPath" stroke="#2a4a55" stroke-width="1.5" fill="none" stroke-linecap="round" />

        <!-- Floating holo-bits (Nova signature) -->
        <g class="energy-glyphs" aria-hidden="true">
          <rect x="6.5" y="13.5" width="2.6" height="2.6" rx="0.5" fill="#5fdcf2" transform="rotate(20 7.8 14.8)" opacity="0.9" />
          <rect x="50.5" y="11" width="2.2" height="2.2" rx="0.5" fill="#7df0ff" transform="rotate(-15 51.6 12.1)" opacity="0.9" />
          <rect x="52" y="32" width="1.8" height="1.8" rx="0.4" fill="#5fdcf2" transform="rotate(25 52.9 32.9)" opacity="0.85" />
        </g>

        <!-- Celebrate particles -->
        <g class="celebrate-particles">
          <circle cx="7"  cy="22" r="2"   fill="#7df0ff" />
          <circle cx="53" cy="18" r="1.6" fill="#fff" />
          <circle cx="30" cy="5"  r="1.8" fill="#17c2e6" />
          <circle cx="5"  cy="42" r="1.5" fill="#5fdcf2" />
          <circle cx="55" cy="40" r="2"   fill="#7df0ff" />
        </g>
      </svg>
    </div>

    <div v-if="inviteCount > 0" class="chibi-invite-badge" :aria-label="`${inviteCount} Duel-Einladung(en)`">
      {{ inviteCount > 9 ? '9+' : inviteCount }}
    </div>
  </div>
</template>

<script>
import { useA11yStore } from '../../stores/a11yStore.js'
import { playWave } from '../../utils/character-animations.js'

export default {
	name: 'NovaAvatar',
	props: {
		animation: { type: String, default: 'idle' },
		hasMessage: { type: Boolean, default: false },
		inviteCount: { type: Number, default: 0 },
		emotion: { type: String, default: null },
		outfit: { type: String, default: 'standard' },
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
		// Emotion drives the face (set by novaReactionEngine via VirtuProf → SkinRenderer).
		// Vocabulary: neutral | happy | sad | surprised | sleep | celebrate.
		face() {
			const valid = ['neutral', 'happy', 'sad', 'surprised', 'sleep', 'celebrate']
			return valid.includes(this.emotion) ? this.emotion : 'neutral'
		},
		eyeRx() {
			return this.face === 'surprised' ? 4.3 : 3.8
		},
		eyeRy() {
			return this.face === 'surprised' ? 3.7 : 3.2
		},
		eyeGlowR() {
			return this.face === 'surprised' ? 3.7 : 3.2
		},
		pupilCy() {
			// Sad looks down a touch.
			return this.face === 'sad' ? 28.2 : 27
		},
		browPaths() {
			switch (this.face) {
			case 'surprised':
				return ['M 19.6 19.4 Q 23.6 17.2 27.6 19.2', 'M 32.4 19.2 Q 36.4 17.2 40.4 19.4']
			case 'sad':
				// Worried: inner ends raised.
				return ['M 19.6 21.4 Q 23.6 21.2 27.6 19.4', 'M 32.4 19.4 Q 36.4 21.2 40.4 21.4']
			case 'happy':
			case 'celebrate':
				return ['M 19.6 20.8 Q 23.6 18.8 27.6 20.6', 'M 32.4 20.6 Q 36.4 18.8 40.4 20.8']
			default:
				return ['M 19.6 21.6 Q 23.6 19.4 27.6 21.4', 'M 32.4 21.4 Q 36.4 19.4 40.4 21.6']
			}
		},
		mouthPath() {
			switch (this.face) {
			case 'happy':
				return 'M 23.5 33.5 Q 30 40 36.5 33.5'
			case 'celebrate':
				return 'M 23 33 Q 30 41 37 33'
			case 'sad':
				return 'M 25 37 Q 30 33.5 35 37'
			case 'sleep':
				return 'M 27 35.5 Q 30 36.6 33 35.5'
			default:
				return 'M 24.5 34 Q 30 38.5 35.5 34'
			}
		},
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
@import '../../styles/chibi-avatar.css';

.nova-avatar-wrapper:hover .chibi-avatar {
	filter: drop-shadow(0 6px 16px rgba(45, 200, 230, 0.5));
}
</style>
