import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createApp, nextTick } from 'vue'
import { createPinia } from 'pinia'
import SkinRenderer from '../../src/components/SkinRenderer.vue'

class FakeIntersectionObserver {
	observe() {}

	disconnect() {}
}

function mountRenderer(props = {}) {
	const target = document.createElement('div')
	document.body.appendChild(target)

	const app = createApp(SkinRenderer, {
		skinId: 'nova',
		animation: 'idle',
		statusText: 'Ready',
		language: 'de',
		...props,
	})

	app.use(createPinia())
	app.mount(target)

	return {
		target,
		unmount() {
			app.unmount()
			target.remove()
		},
	}
}

describe('SkinRenderer', () => {
	beforeEach(() => {
		globalThis.IntersectionObserver = FakeIntersectionObserver
		document.body.innerHTML = ''
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('falls back to nova for unknown skin ids', async () => {
		const view = mountRenderer({ skinId: 'does-not-exist' })
		await nextTick()
		expect(view.target.querySelector('.nova-avatar-wrapper')).not.toBeNull()
		view.unmount()
	})

	it('renders Prof. Lern Classic when selected', async () => {
		const view = mountRenderer({ skinId: 'prof_lern_classic' })
		await nextTick()
		expect(view.target.querySelector('.proflern-avatar')).not.toBeNull()
		view.unmount()
	})

	it('renders CharacterAvatar-backed skins for archetypes', async () => {
		const view = mountRenderer({ skinId: 'theoretiker', animation: 'wave' })
		await nextTick()
		expect(view.target.querySelector('.character-avatar')).not.toBeNull()
		view.unmount()
	})
})
