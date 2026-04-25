import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

import SkinRenderer from '../../src/components/SkinRenderer.vue'
import { useSkinStore } from '../../src/stores/skinStore.js'
import { playCelebrate, playWave } from '../../src/utils/character-animations.js'

const SCHOLAR_IDS = ['theoretiker', 'kosmologe', 'popularisierer']
const SKIN_IDS = ['prof_lern_classic', ...SCHOLAR_IDS]
const STATES = ['idle', 'wave', 'celebrate']

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
	vi.restoreAllMocks()
})

function mountRenderer(skinId, animation = 'idle') {
	const store = useSkinStore()
	store.setSkin(skinId)

	const app = createApp({ render: () => h(SkinRenderer, { animation }) })
	app.use(pinia)
	app.config.globalProperties.t = (appName, str) => str
	app.mount('#app')
	mountedApp = app

	return document.getElementById('app')
}

describe('Phase 152 scholar animation surface', () => {
	it.each(SCHOLAR_IDS)('renders "%s" with stable SVG subgroups', (skinId) => {
		const root = mountRenderer(skinId, 'idle')

		expect(root.querySelector('.character-avatar')).not.toBeNull()
		expect(root.querySelector('g#head')).not.toBeNull()
		expect(root.querySelector('g#body')).not.toBeNull()
		expect(root.querySelector('g#arms')).not.toBeNull()
		expect(root.querySelector('g#powerEffect')).not.toBeNull()
	})

	it.each(SKIN_IDS)('keeps aria-label static across animation states for "%s"', (skinId) => {
		const labels = STATES.map((state) => {
			const root = mountRenderer(skinId, state)
			const label = root.querySelector('svg').getAttribute('aria-label')
			mountedApp.unmount()
			mountedApp = null
			document.body.innerHTML = '<div id="app"></div>'
			return label
		})

		expect(new Set(labels).size).toBe(1)
		expect(labels[0]).not.toMatch(/idle|wave|celebrate|state/i)
	})

	it.each(STATES)('dispatches scholar animation state "%s" through SkinRenderer', (state) => {
		const root = mountRenderer('theoretiker', state)
		const wrapper = root.querySelector('.character-avatar')

		expect(wrapper).not.toBeNull()
		expect(wrapper.classList.contains(`character-state--${state}`)).toBe(true)
	})

	it('keeps wave and celebrate WAAPI helpers available for future scholar hooks', async () => {
		const element = {
			animate: vi.fn().mockReturnValue({ finished: Promise.resolve() }),
		}

		await playWave(element)
		await playCelebrate(element)

		expect(element.animate).toHaveBeenCalledTimes(2)
	})
})
