<template>
  <button
    type="button"
    class="virtuprof-rail"
    :class="{ 'has-invite': inviteCount > 0 }"
    :aria-expanded="expanded ? 'true' : 'false'"
    @click="$emit('click')">
    <span class="virtuprof-rail-copy">
      <span class="virtuprof-rail-kicker">{{ vt('VirtuProf') }}</span>
      <span class="virtuprof-rail-title">{{ vt('Learning assistant') }}</span>
      <span class="virtuprof-rail-status">{{ statusText }}</span>
    </span>
    <NovaAvatar
      :animation="animation"
      :emotion="emotion"
      :has-message="hasMessage"
      :invite-count="inviteCount" />
  </button>
</template>

<script>
import NovaAvatar from './NovaAvatar.vue'
import { translateVirtuProf } from '../../utils/virtuprof-i18n.js'

export default {
	name: 'NovaDock',
	components: { NovaAvatar },
	emits: ['click'],
	props: {
		animation: { type: String, default: 'idle' },
		emotion: { type: String, default: null },
		hasMessage: { type: Boolean, default: false },
		inviteCount: { type: Number, default: 0 },
		statusText: { type: String, default: '' },
		expanded: { type: Boolean, default: false },
	},
	methods: {
		vt(key, params) {
			return translateVirtuProf(key, params)
		},
	},
}
</script>

<style scoped>
.virtuprof-rail {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 14px;
  border: none;
  border-radius: 16px;
  background: var(--color-background-dark, #1a1a2e);
  color: var(--color-main-text);
  cursor: pointer;
  text-align: left;
  transition: background 0.2s ease, box-shadow 0.2s ease;
}

.virtuprof-rail:hover {
  background: var(--color-background-hover);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.virtuprof-rail.has-invite {
  box-shadow: 0 0 0 2px var(--nova-warning-rose, #f43f5e);
}

.virtuprof-rail-copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
  min-width: 0;
}

.virtuprof-rail-kicker {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--nova-accent-cyan, #06b6d4);
}

.virtuprof-rail-title {
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.virtuprof-rail-status {
  font-size: 11px;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
