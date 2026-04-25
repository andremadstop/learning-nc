/**
 * CharacterAvatar.vue structural tests (Phase 150 Plan 05).
 *
 * Project pattern: no @vue/test-utils, mount manually with createApp + happy-dom.
 * Asserts: named <g id="head|body|arms"> sub-groups with transform-box: fill-box,
 * static aria-label (no state leak), no <title> element, role="img".
 */
import { describe, it, expect, beforeEach } from 'vitest'
import { createApp, h } from 'vue'

import CharacterAvatar from '../../src/components/CharacterAvatar.vue'

let mountedApp = null

beforeEach(() => {
	if (mountedApp) {
		mountedApp.unmount()
		mountedApp = null
	}
	document.body.innerHTML = '<div id="app"></div>'
})

function mountAvatar(props = {}) {
	const app = createApp({
		render: () => h(CharacterAvatar, {
			characterId: 'architect',
			state: 'idle',
			size: 96,
			...props,
		}),
	})
	app.config.globalProperties.t = (appName, str) => str
	app.mount('#app')
	mountedApp = app
	return document.getElementById('app')
}

describe('CharacterAvatar SVG structure (Phase 150 Plan 05)', () => {
	it('renders named <g id="head"> with transform-origin + transform-box: fill-box', () => {
		const root = mountAvatar()
		const head = root.querySelector('g#head')
		expect(head).not.toBeNull()
		const style = head.getAttribute('style') || ''
		expect(style).toMatch(/transform-origin:/)
		expect(style).toMatch(/transform-box:\s*fill-box/)
	})

	it('renders named <g id="body"> with transform-box: fill-box', () => {
		const root = mountAvatar()
		const body = root.querySelector('g#body')
		expect(body).not.toBeNull()
		const style = body.getAttribute('style') || ''
		expect(style).toMatch(/transform-box:\s*fill-box/)
	})

	it('renders named <g id="arms"> slot with transform-box: fill-box', () => {
		const root = mountAvatar()
		const arms = root.querySelector('g#arms')
		expect(arms).not.toBeNull()
		const style = arms.getAttribute('style') || ''
		expect(style).toMatch(/transform-box:\s*fill-box/)
	})

	it('root <svg> has role="img" and aria-label set to character.name only', () => {
		const root = mountAvatar({ characterId: 'architect' })
		const svg = root.querySelector('svg')
		expect(svg).not.toBeNull()
		expect(svg.getAttribute('role')).toBe('img')
		const label = svg.getAttribute('aria-label') || ''
		expect(label.length).toBeGreaterThan(0)
		expect(label).not.toMatch(/idle|thinking|celebrate|state/i)
	})

	it('aria-label is identical across different animation states (no state leak)', () => {
		const idle = mountAvatar({ state: 'idle' }).querySelector('svg').getAttribute('aria-label')
		mountedApp.unmount()
		document.body.innerHTML = '<div id="app"></div>'
		const thinking = mountAvatar({ state: 'thinking' }).querySelector('svg').getAttribute('aria-label')
		mountedApp.unmount()
		document.body.innerHTML = '<div id="app"></div>'
		const celebrate = mountAvatar({ state: 'celebrate' }).querySelector('svg').getAttribute('aria-label')
		expect(idle).toBe(thinking)
		expect(thinking).toBe(celebrate)
	})

	it('contains no <title> element (screen-reader spam — Pitfall #9)', () => {
		const root = mountAvatar()
		expect(root.querySelector('svg title')).toBeNull()
	})

	it.each(['nova', 'architect', 'ghostline', 'sysadmin'])(
		'renders silhouette "%s" without error',
		(characterId) => {
			const root = mountAvatar({ characterId })
			expect(root.querySelector('svg')).not.toBeNull()
			expect(root.querySelector('g#body path')).not.toBeNull()
		},
	)
})
