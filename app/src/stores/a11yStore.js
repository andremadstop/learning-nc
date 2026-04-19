import { defineStore, getActivePinia } from 'pinia'

export const useA11yStore = defineStore('a11y', {
	state: () => ({
		animationsEnabled: true,
	}),
	actions: {
		setEnabled(enabled) {
			this.animationsEnabled = enabled !== false
		},
		loadFromServerPayload(payload = {}) {
			this.animationsEnabled = (payload.animations_enabled || 'yes') !== 'no'
		},
	},
})

export function useOptionalA11yStore() {
	const pinia = getActivePinia()
	return pinia ? useA11yStore(pinia) : null
}
