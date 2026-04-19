import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'
import { createPinia } from 'pinia'
import CharacterAvatar from '../../src/components/CharacterAvatar.vue'

class FakeIntersectionObserver {
	constructor(callback) {
		this.callback = callback
	}

	observe() {}

	disconnect() {}
}

function mountAvatar(overrides = {}) {
	const target = document.createElement('div')
	document.body.appendChild(target)

	const app = createApp(CharacterAvatar, {
		characterId: 'architect',
		state: 'idle',
		size: 96,
		...overrides,
	})

	app.config.globalProperties.t = (appName, text) => text
	app.use(createPinia())
	app.mount(target)

	return {
		app,
		target,
		unmount() {
			app.unmount()
			target.remove()
		},
	}
}

describe('CharacterAvatar SVG structure', () => {
	beforeEach(() => {
		globalThis.IntersectionObserver = FakeIntersectionObserver
	})

	afterEach(() => {
		document.body.innerHTML = ''
		vi.restoreAllMocks()
	})

	it('renders named head, body, and arms groups with transform-box styles', async () => {
		const view = mountAvatar()
		await nextTick()

		;['head', 'body', 'arms'].forEach((id) => {
			const element = view.target.querySelector(`g#${id}`)
			expect(element).not.toBeNull()
			const style = element?.getAttribute('style') || ''
			expect(style).toMatch(/transform-origin:/)
			expect(style).toMatch(/transform-box:\s*fill-box/)
		})

		view.unmount()
	})

	it('keeps the aria-label static across animation states', async () => {
		const idle = mountAvatar({ state: 'idle' })
		const thinking = mountAvatar({ state: 'thinking' })
		const celebrate = mountAvatar({ state: 'celebrate' })
		await nextTick()

		const idleLabel = idle.target.querySelector('svg')?.getAttribute('aria-label')
		const thinkingLabel = thinking.target.querySelector('svg')?.getAttribute('aria-label')
		const celebrateLabel = celebrate.target.querySelector('svg')?.getAttribute('aria-label')

		expect(idleLabel).toBe(thinkingLabel)
		expect(thinkingLabel).toBe(celebrateLabel)
		expect(idleLabel).not.toMatch(/idle|thinking|celebrate|wave/i)

		idle.unmount()
		thinking.unmount()
		celebrate.unmount()
	})

	it('does not render a title element inside the svg', async () => {
		const view = mountAvatar()
		await nextTick()
		expect(view.target.querySelector('svg title')).toBeNull()
		view.unmount()
	})

	it.each(['nova', 'architect', 'ghostline', 'sysadmin'])(
		'renders silhouette %s without breaking the svg structure',
		async (characterId) => {
			const view = mountAvatar({ characterId })
			await nextTick()
			expect(view.target.querySelector('svg')).not.toBeNull()
			expect(view.target.querySelector('g#body path')).not.toBeNull()
			view.unmount()
		},
	)
})
