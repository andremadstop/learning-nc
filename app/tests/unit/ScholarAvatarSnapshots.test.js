import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createApp, nextTick } from 'vue'
import { createPinia } from 'pinia'
import CharacterAvatar from '../../src/components/CharacterAvatar.vue'

class FakeIntersectionObserver {
	observe() {}

	disconnect() {}
}

function mountAvatar(props = {}) {
	const target = document.createElement('div')
	document.body.appendChild(target)

	const app = createApp(CharacterAvatar, {
		characterId: 'theoretiker',
		state: 'idle',
		size: 96,
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

describe('Scholar avatar snapshots', () => {
	beforeEach(() => {
		globalThis.IntersectionObserver = FakeIntersectionObserver
		document.body.innerHTML = ''
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it.each([
		['theoretiker', 'idle'],
		['theoretiker', 'wave'],
		['theoretiker', 'celebrate'],
		['kosmologe', 'idle'],
		['kosmologe', 'wave'],
		['kosmologe', 'celebrate'],
		['popularisierer', 'idle'],
		['popularisierer', 'wave'],
		['popularisierer', 'celebrate'],
	])('renders %s in %s state', async (characterId, state) => {
		const view = mountAvatar({ characterId, state })
		await nextTick()
		expect(view.target.querySelector('svg')?.outerHTML).toMatchSnapshot()
		view.unmount()
	})
})
