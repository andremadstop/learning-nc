<template>
  <div
    class="virtuprof-panel"
    tabindex="-1"
    @touchstart.passive="$emit('touchstart', $event)"
    @touchend.passive="$emit('touchend', $event)">
    <div class="virtuprof-panel-header">
      <div class="virtuprof-panel-copy">
        <span class="virtuprof-panel-kicker">{{ vt('VirtuProf') }}</span>
        <strong class="virtuprof-panel-title">{{ title }}</strong>
        <span class="virtuprof-panel-status">{{ metaText }}</span>
      </div>
      <div class="virtuprof-panel-actions">
        <button
          type="button"
          class="virtuprof-panel-action"
          :aria-label="vt('Minimize panel')"
          :title="vt('Minimize panel')"
          @click="$emit('minimize')">
          <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
            <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.8"
              fill="none" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
        <button
          type="button"
          class="virtuprof-panel-action"
          :aria-label="vt('Close panel')"
          :title="vt('Close panel')"
          @click="$emit('close')">
          <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
            <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.8"
              fill="none" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
      </div>
    </div>
    <slot />
  </div>
</template>

<script>
import { translateVirtuProf } from '../../utils/virtuprof-i18n.js'

export default {
	name: 'NovaPanel',
	props: {
		title: { type: String, default: '' },
		metaText: { type: String, default: '' },
	},
	methods: {
		vt(key, params) {
			return translateVirtuProf(key, params)
		},
	},
}
</script>

<style scoped>
.virtuprof-panel {
  display: flex;
  flex-direction: column;
  background: var(--color-main-background);
  border-radius: 16px;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.12);
  overflow: hidden;
  max-height: 80vh;
}

.virtuprof-panel-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 14px;
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;
}

.virtuprof-panel-copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
  min-width: 0;
}

.virtuprof-panel-kicker {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--nova-accent-cyan, #06b6d4);
}

.virtuprof-panel-title {
  font-size: 14px;
  font-weight: 600;
}

.virtuprof-panel-status {
  font-size: 11px;
  color: var(--color-text-maxcontrast);
}

.virtuprof-panel-actions {
  display: flex;
  align-items: center;
  gap: 4px;
}

.virtuprof-panel-action {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--color-text-maxcontrast);
  cursor: pointer;
  flex-shrink: 0;
}

.virtuprof-panel-action:hover {
  background: var(--color-background-hover);
  color: var(--color-main-text);
}
</style>
