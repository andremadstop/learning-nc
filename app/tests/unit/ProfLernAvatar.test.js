import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createApp, nextTick } from 'vue'
import ProfLernAvatar from '../../src/components/ProfLernAvatar.vue'

function mountAvatar(props = {}) {
	const target = document.createElement('div')
	document.body.appendChild(target)

	const app = createApp(ProfLernAvatar, {
		animation: 'idle',
		hasMessage: false,
		inviteCount: 0,
		...props,
	})

	app.mount(target)

	return {
		target,
		unmount() {
			app.unmount()
			target.remove()
		},
	}
}

describe('ProfLernAvatar snapshots', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it.each(['idle', 'wave', 'celebrate'])('renders %s state', async (animation) => {
		const view = mountAvatar({ animation, inviteCount: 2 })
		await nextTick()
		expect(view.target.innerHTML).toMatchSnapshot()
		view.unmount()
	})
})
