<template>
  <button
    type="button"
    class="virtuprof-dock"
    :class="{ 'has-invite': inviteCount > 0, 'has-message': hasMessage }"
    :aria-expanded="expanded ? 'true' : 'false'"
    @click="$emit('click')">
    <span class="virtuprof-dock-copy">
      <span class="virtuprof-dock-kicker">{{ kickerLabel }}</span>
      <span class="virtuprof-dock-title">{{ titleLabel }}</span>
      <span v-if="statusText" class="virtuprof-dock-status">{{ statusText }}</span>
    </span>
    <span class="virtuprof-dock-avatar-slot">
      <slot />
    </span>
  </button>
</template>

<script>
import { useSkinStore } from '../stores/skinStore.js'
import { getCharacter } from '../data/characters.js'

const FALLBACK_LABEL = Object.freeze({ kicker: 'VirtuProf', title: 'Lernassistentin' })

export default {
	name: 'VirtuProfDock',
	emits: ['click'],
	props: {
		hasMessage: { type: Boolean, default: false },
		inviteCount: { type: Number, default: 0 },
		statusText: { type: String, default: '' },
		expanded: { type: Boolean, default: false },
	},
	computed: {
		dockLabel() {
			let id = 'nova'
			try {
				id = useSkinStore().skinId || 'nova'
			} catch (e) {
				id = 'nova'
			}
			const character = getCharacter(id)
			const label = character && character.dockLabel ? character.dockLabel : FALLBACK_LABEL
			return {
				kicker: label.kicker || FALLBACK_LABEL.kicker,
				title: label.title || FALLBACK_LABEL.title,
			}
		},
		kickerLabel() {
			return this.t('learning', this.dockLabel.kicker)
		},
		titleLabel() {
			return this.t('learning', this.dockLabel.title)
		},
	},
	methods: {
		t(app, source) {
			// Use Nextcloud's global gettext when available; otherwise fall back to source.
			if (typeof window !== 'undefined' && typeof window.t === 'function') {
				return window.t(app, source)
			}
			return source
		},
	},
}
</script>

<style scoped>
.virtuprof-dock {
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

.virtuprof-dock:hover {
  background: var(--color-background-hover);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.virtuprof-dock.has-invite {
  box-shadow: 0 0 0 2px var(--nova-warning-rose, #f43f5e);
}

.virtuprof-dock.has-message::after {
  content: '';
  position: absolute;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--nova-accent-cyan, #06b6d4);
  margin-left: auto;
}

.virtuprof-dock-copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
  min-width: 0;
}

.virtuprof-dock-kicker {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--nova-accent-cyan, #06b6d4);
}

.virtuprof-dock-title {
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.virtuprof-dock-status {
  font-size: 11px;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.virtuprof-dock-avatar-slot {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
</style>
