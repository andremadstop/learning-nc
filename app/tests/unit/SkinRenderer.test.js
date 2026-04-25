import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { createApp, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import SkinRenderer from '../../src/components/SkinRenderer.vue'
import { useSkinStore } from '../../src/stores/skinStore.js'

let mountedApp = null

beforeEach(() => {
	if (mountedApp) { mountedApp.unmount(); mountedApp = null }
	document.body.innerHTML = '<div id="app"></div>'
	setActivePinia(createPinia())
})

afterEach(() => {
	if (mountedApp) { mountedApp.unmount(); mountedApp = null }
})

function mount(props = {}) {
	const app = createApp({ render: () => h(SkinRenderer, props) })
	app.use(createPinia())
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
