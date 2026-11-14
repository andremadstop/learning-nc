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

	// GitHub #50: a success note only when the server reports a real change
	it('shows no success note when the server reports no visibility', async () => {
		axios.post.mockResolvedValueOnce({ data: {} })
		const instance = createInstance({ myVisibility: 'private', canToggleVisibility: true })
		instance.loadClassbook = vi.fn()

		await instance.toggleVisibility()

		expect(instance.success).toBe('')
		expect(instance.error).not.toBe('')
		expect(instance.loadClassbook).not.toHaveBeenCalled()
	})

	// GitHub #50: the visibility is global; the button asks for the state it names
	it('sends the requested visibility, not a relative toggle', async () => {
		axios.post.mockResolvedValueOnce({ data: { visibility: 'course' } })
		const instance = createInstance({ myVisibility: 'private', canToggleVisibility: true })
		instance.loadClassbook = vi.fn()

		await instance.toggleVisibility()

		expect(axios.post.mock.calls.at(-1)[1]).toEqual({ visibility: 'course' })
	})

	it('reports the server state when another tab had already changed it', async () => {
		// stale client "visible", the learner is already private on the server
		axios.post.mockResolvedValueOnce({ data: { visibility: 'private' } })
		const instance = createInstance({ myVisibility: 'course', canToggleVisibility: true })
		instance.loadClassbook = vi.fn()

		await instance.toggleVisibility()

		expect(instance.error).toBe('')
		expect(instance.myVisibility).toBe('private')
		expect(instance.success).toBe('Dein Klassenbuch-Profil ist wieder privat.')
		expect(instance.loadClassbook).toHaveBeenCalled()
	})

	it('never shows the untranslated server error text', async () => {
		axios.post.mockRejectedValueOnce({ response: { status: 403, data: { error: 'Only learners of this course can change their visibility here' } } })
		const instance = createInstance({ myVisibility: 'private', canToggleVisibility: true })

		await instance.toggleVisibility()

		expect(instance.error).toBe('Sichtbarkeit konnte nicht geändert werden.')
	})

	it('shows no success note when the server refuses the switch', async () => {
		axios.post.mockRejectedValueOnce({ response: { status: 403, data: {} } })
		const instance = createInstance({ myVisibility: 'private', canToggleVisibility: true })

		await instance.toggleVisibility()

		expect(instance.success).toBe('')
		expect(instance.myVisibility).toBe('private')
	})

	it('reports a real change from private to visible', async () => {
		axios.post.mockResolvedValueOnce({ data: { visibility: 'course' } })
		const instance = createInstance({ myVisibility: 'private', canToggleVisibility: true })
		instance.loadClassbook = vi.fn()

		await instance.toggleVisibility()

		expect(instance.myVisibility).toBe('course')
		expect(instance.success).toBe('Dein Klassenbuch-Profil ist jetzt sichtbar.')
		expect(instance.loadClassbook).toHaveBeenCalled()
	})
})
