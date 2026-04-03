import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

import Klassenbuch from '../../src/components/Klassenbuch.vue'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof Klassenbuch.data === 'function' ? Klassenbuch.data() : {}
	const instance = {
		...data,
		courseId: 5,
		userRole: 'student',
		...overrides,
	}

	for (const [name, fn] of Object.entries(Klassenbuch.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('Klassenbuch', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loads profiles and meta data from the API payload', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				profiles: [{ user_id: 'alice', display_name: 'Alice', level: 4 }],
				current_user_id: 'bob',
				my_visibility: 'course',
				kudos_remaining: 2,
				can_toggle_visibility: true,
			},
		})

		const instance = createInstance()
		await instance.loadClassbook()

		expect(instance.profiles).toHaveLength(1)
		expect(instance.currentUserId).toBe('bob')
		expect(instance.myVisibility).toBe('course')
		expect(instance.kudosRemaining).toBe(2)
		expect(instance.canToggleVisibility).toBe(true)
	})

	it('disables kudos for self and after daily limit is exhausted', () => {
		const instance = createInstance({
			currentUserId: 'alice',
			kudosRemaining: 0,
		})

		expect(instance.canGiveKudos({ user_id: 'alice' })).toBe(false)
		expect(instance.canGiveKudos({ user_id: 'bob' })).toBe(false)
	})

	it('allows kudos for another student while quota remains', () => {
		const instance = createInstance({
			currentUserId: 'alice',
			kudosRemaining: 2,
		})

		expect(instance.canGiveKudos({ user_id: 'bob' })).toBe(true)
	})
})
