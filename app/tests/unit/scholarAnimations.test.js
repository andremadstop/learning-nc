import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

import SkinRenderer from '../../src/components/SkinRenderer.vue'
import { useSkinStore } from '../../src/stores/skinStore.js'
import { playCelebrate, playWave } from '../../src/utils/character-animations.js'

// As of the chibi-scholar redesign, the 3 scholars render their own chibi
// components (TheoretikerAvatar / KosmologeAvatar / PopularisiererAvatar)
// instead of the generic CharacterAvatar. This test now verifies the chibi
// contract: scoped wrapper class, static aria-label, animation class on inner
// node. The Phase 150 named-groups contract continues to apply only to skins
// that still ship through CharacterAvatar (campaign characters), not the
// scholars.

const SCHOLAR_CASES = [
	{ id: 'theoretiker', wrapper: '.theoretiker-avatar-wrapper', avatar: '.theoretiker-avatar', ariaLabel: 'Der Theoretiker' },
	{ id: 'kosmologe', wrapper: '.kosmologe-avatar-wrapper', avatar: '.kosmologe-avatar', ariaLabel: 'Der Kosmologe' },
	{ id: 'popularisierer', wrapper: '.popularisierer-avatar-wrapper', avatar: '.popularisierer-avatar', ariaLabel: 'Der Popularisierer' },
]
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

describe('Scholar animation surface (chibi family)', () => {
	it.each(SCHOLAR_CASES)('renders "$id" with chibi wrapper inside .virtuprof-dock', ({ id, wrapper, avatar }) => {
		const root = mountRenderer(id, 'idle')

		expect(root.querySelector('.virtuprof-dock')).not.toBeNull()
		expect(root.querySelector(`.virtuprof-dock ${wrapper}`)).not.toBeNull()
		expect(root.querySelector(`${avatar}.chibi-avatar`)).not.toBeNull()
		// SVG must have static aria-label and role=img
		const svg = root.querySelector(`${wrapper} svg`)
		expect(svg.getAttribute('role')).toBe('img')
	})

	it.each(SCHOLAR_CASES)('keeps aria-label static across animation states for "$id"', ({ id, ariaLabel }) => {
		const labels = STATES.map((state) => {
			const root = mountRenderer(id, state)
			const label = root.querySelector('svg').getAttribute('aria-label')
			mountedApp.unmount()
			mountedApp = null
			document.body.innerHTML = '<div id="app"></div>'
			return label
		})

		expect(new Set(labels).size).toBe(1)
		expect(labels[0]).toBe(ariaLabel)
		expect(labels[0]).not.toMatch(/idle|wave|celebrate|state/i)
	})

	it.each(STATES)('dispatches scholar animation state "%s" through SkinRenderer', (state) => {
		const root = mountRenderer('theoretiker', state)
		const avatar = root.querySelector('.theoretiker-avatar')

		expect(avatar).not.toBeNull()
		expect(avatar.classList.contains(`animation-${state}`)).toBe(true)
	})

	it('keeps wave and celebrate WAAPI helpers available', async () => {
		const element = {
			animate: vi.fn().mockReturnValue({ finished: Promise.resolve() }),
		}

		await playWave(element)
		await playCelebrate(element)

		expect(element.animate).toHaveBeenCalledTimes(2)
	})
})

// ── per-skin × per-state matrix for the chibi family ──
// Verifies for each (skin, state) pairing that:
//   - the chibi wrapper mounts
//   - animation class is applied on the inner avatar
//   - aria-label remains static (A11Y-03)
describe.each(SCHOLAR_CASES)('scholar skin "$id" — per-state structural matrix', ({ id, wrapper, avatar, ariaLabel }) => {
	it.each(STATES)('renders chibi wrapper + static aria-label in state "%s"', async (state) => {
		const root = mountRenderer(id, state)
		await new Promise((resolve) => setTimeout(resolve, 0))

		const wrapperEl = root.querySelector(wrapper)
		expect(wrapperEl, `${wrapper} should exist for ${id}/${state}`).not.toBeNull()

		const avatarEl = root.querySelector(avatar)
		expect(avatarEl, `${avatar} should exist for ${id}/${state}`).not.toBeNull()
		expect(avatarEl.classList.contains(`animation-${state}`)).toBe(true)

		const svg = root.querySelector(`${wrapper} svg`)
		expect(svg.getAttribute('aria-label')).toBe(ariaLabel)
	})
})

// Prof. Lern Classic — same contract, different wrapper class.
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
