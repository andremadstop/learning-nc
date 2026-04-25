import { defineStore } from 'pinia'

import { CHARACTERS } from '../data/characters.js'

const DEFAULT_SKIN = 'nova'

/**
 * Active VirtuProf skin (Phase 151).
 * - Default 'nova' (Zero-Change-Default for existing users; CLASSIC-04 read-path)
 * - setSkin coerces unknown ids to DEFAULT_SKIN (PICK-05 allowlist)
 * - loadFromServerPayload hydrates from /api/virtuprof/state.skin (Pattern A)
 * - availableSkins getter filters CHARACTERS by user_selectable (META-03)
 *
 * Wired to SkinRenderer.vue (PICK-03) and PersonalSettings.vue picker (PICK-01).
 */
export const useSkinStore = defineStore('skin', {
	state: () => ({
		skinId: DEFAULT_SKIN,
	}),
	getters: {
		availableSkins: () => Object.values(CHARACTERS).filter(c => c.user_selectable),
	},
	actions: {
		setSkin(id) {
			if (typeof id !== 'string' || !CHARACTERS[id]) {
				this.skinId = DEFAULT_SKIN
				return
			}
			this.skinId = id
		},
		loadFromServerPayload(payload) {
			if (!payload || typeof payload !== 'object') return
			if (typeof payload.skin === 'string' && CHARACTERS[payload.skin]) {
				this.skinId = payload.skin
			}
		},
	},
})
