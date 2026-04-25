import { defineStore } from 'pinia'

/**
 * Accessibility preferences store.
 * Holds the manual "Ruhige Darstellung" (animations-disabled) toggle,
 * wired to WAAPI helpers via setAnimationsEnabledGetter() in main.js so every
 * playWave / playCelebrate / playShrug call respects this flag without
 * per-call plumbing. Never overrides the OS-level prefers-reduced-motion
 * preference — the gate is OR (either matches -> skip animation).
 */
export const useA11yStore = defineStore('a11y', {
	state: () => ({
		animationsEnabled: true,
	}),
	actions: {
		setEnabled(value) {
			this.animationsEnabled = !!value
		},
		/**
		 * Hydrate from the /api/settings/personal payload. Treats missing key
		 * as default-true (backwards compat with pre-v4.4.0 users).
		 * @param {{ animations_enabled?: string } | null | undefined} payload
		 */
		loadFromServerPayload(payload) {
			if (!payload || typeof payload !== 'object') return
			if (typeof payload.animations_enabled === 'string') {
				this.animationsEnabled = payload.animations_enabled !== 'no'
			}
		},
	},
})
