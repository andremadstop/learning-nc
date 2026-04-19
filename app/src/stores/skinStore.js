import { defineStore, getActivePinia } from 'pinia'
import { resolveVirtuProfSkinId } from '../data/characters.js'

export const useSkinStore = defineStore('skin', {
	state: () => ({
		selectedSkinId: 'nova',
		version: 0,
	}),
	actions: {
		setSkinId(skinId) {
			const resolvedSkinId = resolveVirtuProfSkinId(skinId)
			if (resolvedSkinId !== this.selectedSkinId) {
				this.selectedSkinId = resolvedSkinId
				this.version += 1
			}
			return this.selectedSkinId
		},
		loadFromServerPayload(payload = {}) {
			return this.setSkinId(payload.skinId || payload.skin_id || payload.skin || 'nova')
		},
	},
})

export function useOptionalSkinStore() {
	const pinia = getActivePinia()
	return pinia ? useSkinStore(pinia) : null
}
