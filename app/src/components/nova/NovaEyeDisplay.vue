<template>
  <svg
    class="nova-eye"
    :class="[`nova-eye--${emotion}`]"
    viewBox="0 0 32 32"
    xmlns="http://www.w3.org/2000/svg"
    aria-hidden="true">
    <!-- Monitor frame -->
    <circle cx="16" cy="16" r="14" fill="none"
      stroke="currentColor" stroke-width="1" opacity="0.3" />

    <!-- Emotion symbols -->
    <g v-if="emotion === 'neutral'" class="nova-eye-symbol">
      <line x1="9" y1="16" x2="14" y2="16" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
      <line x1="18" y1="16" x2="23" y2="16" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
    </g>

    <g v-else-if="emotion === 'happy'" class="nova-eye-symbol">
      <path d="M9 15 L11.5 12 L14 15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
      <path d="M18 15 L20.5 12 L23 15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
    </g>

    <g v-else-if="emotion === 'sad'" class="nova-eye-symbol">
      <line x1="9" y1="16" x2="14" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
      <line x1="18" y1="16" x2="23" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
    </g>

    <g v-else-if="emotion === 'surprised'" class="nova-eye-symbol">
      <circle cx="11.5" cy="15" r="3" fill="none" stroke="currentColor" stroke-width="1.5" />
      <circle cx="20.5" cy="15" r="3" fill="none" stroke="currentColor" stroke-width="1.5" />
      <line x1="16" y1="21" x2="16" y2="24" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
    </g>

    <g v-else-if="emotion === 'sleep'" class="nova-eye-symbol nova-eye-sleep">
      <line x1="10" y1="16" x2="22" y2="16" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
    </g>

    <g v-else-if="emotion === 'celebrate'" class="nova-eye-symbol">
      <polygon points="16,10 17.5,14 22,14 18.5,17 19.8,21 16,18.5 12.2,21 13.5,17 10,14 14.5,14"
        fill="currentColor" opacity="0.9" />
    </g>

    <!-- Glow overlay -->
    <circle v-if="emotionColor" cx="16" cy="16" r="12"
      :fill="emotionColor" opacity="0.08" />
  </svg>
</template>

<script>
import { EMOTIONS } from './nova-states.js'

export default {
	name: 'NovaEyeDisplay',
	props: {
		emotion: { type: String, default: 'neutral' },
		color: { type: String, default: 'var(--nova-accent-cyan)' },
	},
	computed: {
		emotionColor() {
			const e = EMOTIONS[this.emotion]
			return e ? e.color : null
		},
	},
}
</script>

<style scoped>
.nova-eye {
  width: 32px;
  height: 32px;
  color: v-bind(color);
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}

.nova-eye--neutral .nova-eye-symbol,
.nova-eye--happy .nova-eye-symbol {
  animation: nova-blink var(--nova-blink-interval, 4s) ease-in-out infinite;
}

.nova-eye-sleep {
  animation: nova-sleep-pulse 2s ease-in-out infinite;
}

@keyframes nova-blink {
  0%, 90%, 100% { transform: scaleY(1); }
  95% { transform: scaleY(0.1); }
}

@keyframes nova-sleep-pulse {
  0%, 100% { opacity: 0.3; }
  50% { opacity: 0.7; }
}

@media (prefers-reduced-motion: reduce) {
  .nova-eye--neutral .nova-eye-symbol,
  .nova-eye--happy .nova-eye-symbol,
  .nova-eye-sleep {
    animation: none;
  }
}
</style>
