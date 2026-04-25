import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

import SkinRenderer from '../../src/components/SkinRenderer.vue'
import { useSkinStore } from '../../src/stores/skinStore.js'
import { playCelebrate, playWave } from '../../src/utils/character-animations.js'

const SCHOLAR_IDS = ['theoretiker', 'kosmologe', 'popularisierer']
const SCHOLAR_SKINS = SCHOLAR_IDS
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

// ── ANIM-05: per-skin × per-state structural matrix (4 skins × 3 states) ──
// Verifies for each (skin, state) pairing:
//   - .character-avatar exists in DOM
//   - g#head, g#body, g#arms, g#powerEffect groups exist (Phase 150 named-groups contract)
//   - aria-label is static (does not encode the state — A11Y-03 contract)
// Pitfall 5: structural querySelector assertions, NOT toMatchSnapshot.
describe.each(SCHOLAR_SKINS)('scholar skin "%s" — per-state structural matrix', (skinId) => {
	it.each(STATES)('renders structural elements + static aria-label in state "%s"', async (state) => {
		const root = mountRenderer(skinId, state)
		await new Promise((resolve) => setTimeout(resolve, 0))

		const avatar = root.querySelector('.character-avatar')
		expect(avatar, `.character-avatar should exist for ${skinId}/${state}`).not.toBeNull()

		// Phase 150 named groups
		expect(root.querySelector('g#head'), `g#head for ${skinId}/${state}`).not.toBeNull()
		expect(root.querySelector('g#body'), `g#body for ${skinId}/${state}`).not.toBeNull()
		expect(root.querySelector('g#arms'), `g#arms for ${skinId}/${state}`).not.toBeNull()
		expect(root.querySelector('g#powerEffect'), `g#powerEffect for ${skinId}/${state}`).not.toBeNull()

		// A11Y-03: aria-label is static (always the character name, never the state)
		const svg = root.querySelector('svg')
		const ariaLabel = svg.getAttribute('aria-label')
		expect(ariaLabel).toBeTruthy()
		expect(ariaLabel.toLowerCase()).not.toContain('idle')
		expect(ariaLabel.toLowerCase()).not.toContain('wave')
		expect(ariaLabel.toLowerCase()).not.toContain('celebrate')
	})
})

// Prof. Lern Classic uses ProfLernAvatar (different component); verify SkinRenderer dispatches
// to it in all 3 states with a static aria-label.
describe('classic skin "prof_lern_classic" — per-state dispatch', () => {
	it.each(STATES)('renders ProfLernAvatar with static aria-label in state "%s"', async (state) => {
		const root = mountRenderer('prof_lern_classic', state)
		await new Promise((resolve) => setTimeout(resolve, 0))

		const avatar = root.querySelector('.virtuprof-avatar-wrapper, .prof-lern-avatar, [data-prof-feature]')
		expect(avatar, `ProfLernAvatar should mount for state ${state}`).not.toBeNull()

		const svg = root.querySelector('svg')
		if (svg) {
			expect(svg.getAttribute('role')).toBe('img')
			const ariaLabel = svg.getAttribute('aria-label')
			if (ariaLabel) {
				expect(ariaLabel.toLowerCase()).not.toContain('idle')
				expect(ariaLabel.toLowerCase()).not.toContain('wave')
				expect(ariaLabel.toLowerCase()).not.toContain('celebrate')
			}
		}
	})
})
