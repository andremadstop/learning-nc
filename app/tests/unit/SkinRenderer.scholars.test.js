import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createApp, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

import SkinRenderer from '../../src/components/SkinRenderer.vue'
import { useSkinStore } from '../../src/stores/skinStore.js'

const SCHOLAR_IDS = ['theoretiker', 'kosmologe', 'popularisierer']

let mountedApp = null
let pinia = null

beforeEach(() => {
	if (mountedApp) {
		mountedApp.unmount()
		mountedApp = null
	}
	document.body.innerHTML = '<div id="app"></div>'
	pinia = createPinia()
	setActivePinia(pinia)
})

afterEach(() => {
	if (mountedApp) {
		mountedApp.unmount()
		mountedApp = null
	}
})

function mountRenderer(props = {}) {
	const app = createApp({ render: () => h(SkinRenderer, props) })
	app.use(pinia)
	app.config.globalProperties.t = (appName, str) => str
	app.mount('#app')
	mountedApp = app
	return document.getElementById('app')
}

describe('Phase 152 SkinRenderer scholar dispatch', () => {
	it.each(SCHOLAR_IDS)('renders CharacterAvatar for scholar skin "%s"', async (skinId) => {
		const store = useSkinStore()
		store.setSkin(skinId)

		const root = mountRenderer({ animation: 'idle' })
		await new Promise((resolve) => setTimeout(resolve, 0))

		const avatar = root.querySelector('.character-avatar')
		expect(avatar).not.toBeNull()
		expect(root.querySelector('.virtuprof-rail, .nova-dock')).toBeNull()
		expect(root.querySelector('.virtuprof-avatar-wrapper, .prof-lern-avatar')).toBeNull()
	})

	it('exposes all scholar skins in the available skin list', () => {
		const store = useSkinStore()
		const ids = store.availableSkins.map((character) => character.id)

		for (const id of SCHOLAR_IDS) {
			expect(ids).toContain(id)
		}
	})
})
