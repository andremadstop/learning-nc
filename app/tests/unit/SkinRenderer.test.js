import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { createApp, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import SkinRenderer from '../../src/components/SkinRenderer.vue'
import { useSkinStore } from '../../src/stores/skinStore.js'

// SkinRenderer wraps every skin in <VirtuProfDock> and dispatches the inner
// avatar component (NovaAvatar / ProfLernAvatar / CharacterAvatar) so the
// kicker/title/status shell is identical across all 14 skins. Tests therefore
// check for both the dock wrapper AND the correct nested avatar marker.

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
	it('always wraps the avatar in .virtuprof-dock', () => {
		useSkinStore().setSkin('nova')
		const root = mount()
		expect(root.querySelector('.virtuprof-dock')).not.toBeNull()
	})

	it('PICK-03 renders NovaAvatar inside dock when skinId is "nova"', () => {
		useSkinStore().setSkin('nova')
		const root = mount()
		expect(root.querySelector('.virtuprof-dock .nova-avatar-wrapper')).not.toBeNull()
	})

	it('PICK-03 renders ProfLernAvatar inside dock when skinId is "prof_lern_classic"', () => {
		useSkinStore().setSkin('prof_lern_classic')
		const root = mount()
		expect(root.querySelector('.virtuprof-dock .virtuprof-avatar-wrapper')).not.toBeNull()
	})

	it('PICK-03 renders CharacterAvatar inside dock when skinId is a valid campaign character ("architect")', () => {
		useSkinStore().setSkin('architect')
		const root = mount()
		expect(root.querySelector('.virtuprof-dock .character-avatar')).not.toBeNull()
	})

	it('PICK-05 falls back to NovaAvatar inside dock on invalid skinId', () => {
		useSkinStore().setSkin('__nope__')
		const root = mount()
		expect(root.querySelector('.virtuprof-dock .nova-avatar-wrapper')).not.toBeNull()
		expect(root.querySelector('.character-avatar')).toBeNull()
	})

	it('TEST-01 / PICK-05 falls back to NovaAvatar when skinId is an orphan id (e.g. einstein_v0_dropped)', () => {
		useSkinStore().setSkin('einstein_v0_dropped')
		const root = mount()
		expect(root.querySelector('.virtuprof-dock .nova-avatar-wrapper')).not.toBeNull()
		expect(root.querySelector('.character-avatar')).toBeNull()
	})

	it('PICK-04 :key remount — changing skinId from nova to prof_lern_classic swaps the inner avatar', async () => {
		const store = useSkinStore()
		store.setSkin('nova')
		const root = mount()
		expect(root.querySelector('.virtuprof-dock .nova-avatar-wrapper')).not.toBeNull()
		store.setSkin('prof_lern_classic')
		await new Promise(resolve => setTimeout(resolve, 0))
		expect(root.querySelector('.virtuprof-dock .virtuprof-avatar-wrapper')).not.toBeNull()
		expect(root.querySelector('.nova-avatar-wrapper')).toBeNull()
	})

	it('renders the per-skin dock label kicker (Klassik for prof_lern_classic)', () => {
		useSkinStore().setSkin('prof_lern_classic')
		const root = mount()
		const kicker = root.querySelector('.virtuprof-dock-kicker')
		expect(kicker).not.toBeNull()
		expect(kicker.textContent.trim()).toBe('Klassik')
	})
})
