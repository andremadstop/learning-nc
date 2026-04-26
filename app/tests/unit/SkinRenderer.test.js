import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { createApp, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import SkinRenderer from '../../src/components/SkinRenderer.vue'
import { useSkinStore } from '../../src/stores/skinStore.js'

// TEST-03 BASELINE NOTE (Phase 153):
// The 4-avatar × 3-state matrix for Prof. Lern Classic + Theoretiker + Kosmologe + Popularisierer
// is covered by app/tests/unit/scholarAnimations.test.js (Phase 152, 23 GREEN cases).
// Phase 153 TEST-03 is satisfied by that file's structural matrix — see
// .planning/phases/152-three-archetype-presets/152-VERIFICATION.md "Quality Gate Status".

let mountedApp = null
let pinia = null

beforeEach(() => {
	if (mountedApp) { mountedApp.unmount(); mountedApp = null }
	document.body.innerHTML = '<div id="app"></div>'
	pinia = createPinia()
	setActivePinia(pinia)
})

afterEach(() => {
	if (mountedApp) { mountedApp.unmount(); mountedApp = null }
})

function mount(props = {}) {
	const app = createApp({ render: () => h(SkinRenderer, props) })
	app.use(pinia)
	app.config.globalProperties.t = (appName, str) => str
	app.mount('#app')
	mountedApp = app
	return document.getElementById('app')
}

describe('SkinRenderer dispatch', () => {
	it('PICK-03 renders NovaDock when skinId is "nova"', () => {
		useSkinStore().setSkin('nova')
		const root = mount()
		expect(root.querySelector('.virtuprof-rail, .nova-dock')).not.toBeNull()
	})

	it('PICK-03 renders ProfLernAvatar when skinId is "prof_lern_classic"', () => {
		useSkinStore().setSkin('prof_lern_classic')
		const root = mount()
		expect(root.querySelector('.virtuprof-avatar-wrapper, .prof-lern-avatar')).not.toBeNull()
	})

	it('PICK-03 renders CharacterAvatar when skinId is a valid campaign character ("architect")', () => {
		useSkinStore().setSkin('architect')
		const root = mount()
		expect(root.querySelector('.character-avatar')).not.toBeNull()
	})

	it('PICK-05 falls back to NovaDock on invalid skinId (skinStore coerces to nova; renderer follows)', () => {
		useSkinStore().setSkin('__nope__')
		const root = mount()
		expect(root.querySelector('.virtuprof-rail, .nova-dock')).not.toBeNull()
	})

	it('TEST-01 / PICK-05 falls back to NovaDock when skinId is an invalid skin id (e.g. orphan einstein_v0_dropped)', () => {
		// Simulate an orphan/dropped skin id reaching the store (e.g. via stale
		// server payload after a Phase removal or a malformed user_config row).
		// Both store entry points coerce invalid ids back to 'nova':
		//   - setSkin('einstein_v0_dropped') → CHARACTERS check fails → DEFAULT_SKIN='nova'
		//   - loadFromServerPayload({skin:'einstein_v0_dropped'}) → CHARACTERS check fails → state unchanged (stays 'nova')
		// Either way, the renderer must show NovaDock — graceful PICK-05 degradation.
		useSkinStore().setSkin('einstein_v0_dropped')
		const root = mount()
		// Must render NovaDock fallback (same as the controller's normalizeSkin enforces server-side)
		expect(root.querySelector('.virtuprof-rail, .nova-dock')).not.toBeNull()
		// Negative: NOT routed to .character-avatar (would mean the dispatcher
		// misclassified an unknown id as a generic scholar)
		expect(root.querySelector('.character-avatar')).toBeNull()
	})

	it('PICK-04 :key remount — changing skinId from nova to prof_lern_classic swaps the rendered child', async () => {
		const store = useSkinStore()
		store.setSkin('nova')
		const root = mount()
		expect(root.querySelector('.virtuprof-rail, .nova-dock')).not.toBeNull()
		store.setSkin('prof_lern_classic')
		await new Promise(resolve => setTimeout(resolve, 0))
		expect(root.querySelector('.virtuprof-avatar-wrapper, .prof-lern-avatar')).not.toBeNull()
		expect(root.querySelector('.virtuprof-rail, .nova-dock')).toBeNull()
	})
})
