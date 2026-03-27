<template>
  <svg
    class="nova-core"
    :class="[`nova-core--${state}`]"
    viewBox="0 0 48 48"
    xmlns="http://www.w3.org/2000/svg"
    aria-hidden="true">
    <defs>
      <linearGradient id="nova-core-fill" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="#1a1f2e" />
        <stop offset="100%" stop-color="#0d1117" />
      </linearGradient>
    </defs>
    <!-- Main cube body -->
    <rect x="4" y="4" width="40" height="40" rx="8" fill="url(#nova-core-fill)"
      stroke="currentColor" stroke-width="1.2" stroke-opacity="0.5" />
    <!-- Solder traces -->
    <line x1="8" y1="12" x2="18" y2="12" stroke="currentColor" stroke-width="0.6" opacity="0.4" />
    <line x1="30" y1="12" x2="40" y2="12" stroke="currentColor" stroke-width="0.6" opacity="0.4" />
    <line x1="8" y1="36" x2="18" y2="36" stroke="currentColor" stroke-width="0.6" opacity="0.4" />
    <line x1="30" y1="36" x2="40" y2="36" stroke="currentColor" stroke-width="0.6" opacity="0.4" />
    <line x1="12" y1="8" x2="12" y2="16" stroke="currentColor" stroke-width="0.6" opacity="0.4" />
    <line x1="36" y1="8" x2="36" y2="16" stroke="currentColor" stroke-width="0.6" opacity="0.4" />
  </svg>
</template>

<script>
export default {
	name: 'NovaBaseCore',
	props: {
		color: { type: String, default: 'var(--nova-accent-cyan)' },
		state: { type: String, default: 'idle' },
	},
}
</script>

<style scoped>
.nova-core {
  width: 48px;
  height: 48px;
  color: v-bind(color);
  transition: transform 0.3s ease;
}

.nova-core--thinking {
  animation: nova-pulse var(--nova-thinking-duration, 2s) ease-in-out infinite;
}

.nova-core--glitch {
  animation: nova-glitch-jitter var(--nova-glitch-duration, 0.2s) steps(2) infinite;
}

@keyframes nova-pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.03); }
}

@keyframes nova-glitch-jitter {
  0% { transform: translate(0, 0); }
  50% { transform: translate(2px, -1px); }
  100% { transform: translate(-2px, 1px); }
}

@media (prefers-reduced-motion: reduce) {
  .nova-core--thinking,
  .nova-core--glitch {
    animation: none;
  }
}
</style>
