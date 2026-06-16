import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createApp, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

import SkinRenderer from '../../src/components/SkinRenderer.vue'
import { useSkinStore } from '../../src/stores/skinStore.js'

const SCHOLAR_CASES = [
	{ id: 'theoretiker', wrapper: '.theoretiker-avatar-wrapper' },
	{ id: 'kosmologe', wrapper: '.kosmologe-avatar-wrapper' },
	{ id: 'popularisierer', wrapper: '.popularisierer-avatar-wrapper' },
]

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

describe('SkinRenderer scholar dispatch (chibi family)', () => {
	it.each(SCHOLAR_CASES)('renders the dedicated chibi wrapper inside .virtuprof-dock for "$id"', async ({ id, wrapper }) => {
		const store = useSkinStore()
		store.setSkin(id)

		const root = mountRenderer({ animation: 'idle' })
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(root.querySelector('.virtuprof-dock')).not.toBeNull()
		expect(root.querySelector(`.virtuprof-dock ${wrapper}`)).not.toBeNull()
		// All 3 chibi scholars share the family class.
		expect(root.querySelector(`${wrapper}.chibi-avatar-wrapper`)).not.toBeNull()
		// Must NOT fall back to the generic stick-figure CharacterAvatar.
		expect(root.querySelector('.character-avatar')).toBeNull()
		// Must NOT render Nova or ProfLern wrappers.
		expect(root.querySelector('.nova-avatar-wrapper')).toBeNull()
	})

	it('exposes all scholar skins in the available skin list', () => {
		const store = useSkinStore()
		const ids = store.availableSkins.map((character) => character.id)

		for (const { id } of SCHOLAR_CASES) {
			expect(ids).toContain(id)
		}
	})
})
