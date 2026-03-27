<template>
  <div class="nova-bits" :class="[`nova-bits--${state}`]">
    <svg class="nova-bit nova-bit--alpha" viewBox="0 0 8 8" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect width="8" height="8" rx="2" fill="currentColor" opacity="0.8" />
    </svg>
    <svg class="nova-bit nova-bit--beta" viewBox="0 0 8 8" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect width="8" height="8" rx="2" fill="currentColor" opacity="0.8" />
    </svg>
    <!-- Celebrate confetti -->
    <template v-if="state === 'celebrate'">
      <span v-for="i in 5" :key="i" class="nova-confetti" :style="confettiStyle(i)" />
    </template>
  </div>
</template>

<script>
export default {
	name: 'NovaBitOrbiters',
	props: {
		state: { type: String, default: 'idle' },
		color: { type: String, default: 'var(--nova-accent-cyan)' },
	},
	methods: {
		confettiStyle(i) {
			const angle = (i * 72) + 'deg'
			const delay = (i * 0.08) + 's'
			return { '--confetti-angle': angle, '--confetti-delay': delay }
		},
	},
}
</script>

<style scoped>
.nova-bits {
  position: absolute;
  inset: 0;
  color: v-bind(color);
}

.nova-bit {
  width: 8px;
  height: 8px;
  position: absolute;
}

.nova-bit--alpha {
  top: 4px;
  right: -2px;
  animation: nova-orbit-alpha var(--nova-orbit-duration, 10s) linear infinite;
}

.nova-bit--beta {
  bottom: 4px;
  left: -2px;
  animation: nova-orbit-beta var(--nova-orbit-duration, 10s) linear infinite reverse;
}

/* State modifiers */
.nova-bits--thinking .nova-bit--alpha {
  animation-duration: 3s;
  transform-origin: center;
}
.nova-bits--thinking .nova-bit--beta {
  animation-duration: 3s;
}

.nova-bits--talk .nova-bit {
  animation-name: nova-bit-wobble;
  animation-duration: 1.5s;
}

.nova-bits--celebrate .nova-bit--alpha {
  animation-duration: 2s;
}
.nova-bits--celebrate .nova-bit--beta {
  animation-duration: 2s;
}

.nova-bits--sleep .nova-bit {
  animation-duration: 20s;
  opacity: 0.4;
  transform: translateY(8px);
}

.nova-bits--glitch .nova-bit {
  animation: nova-bit-glitch 0.15s steps(3) infinite;
}

/* Confetti particles */
.nova-confetti {
  position: absolute;
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: currentColor;
  top: 50%;
  left: 50%;
  animation: nova-confetti-burst 0.8s ease-out var(--confetti-delay, 0s) forwards;
  opacity: 0;
}

@keyframes nova-orbit-alpha {
  from { transform: rotate(0deg) translateX(28px) rotate(0deg); }
  to { transform: rotate(360deg) translateX(28px) rotate(-360deg); }
}

@keyframes nova-orbit-beta {
  from { transform: rotate(0deg) translateX(26px) rotate(0deg); }
  to { transform: rotate(360deg) translateX(26px) rotate(-360deg); }
}

@keyframes nova-bit-wobble {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}

@keyframes nova-bit-glitch {
  0% { transform: translate(0, 0); }
  50% { transform: translate(3px, -2px); }
  100% { transform: translate(-3px, 1px); }
}

@keyframes nova-confetti-burst {
  0% { transform: rotate(var(--confetti-angle, 0deg)) translateX(0); opacity: 1; }
  100% { transform: rotate(var(--confetti-angle, 0deg)) translateX(32px); opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
  .nova-bit,
  .nova-confetti {
    animation: none !important;
  }
  .nova-bit--alpha {
    top: 4px;
    right: -2px;
  }
  .nova-bit--beta {
    bottom: 4px;
    left: -2px;
  }
}
</style>
