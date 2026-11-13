import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import axios from '@nextcloud/axios'

// GitHub #35: the members tab enrols people and groups through a search picker. These tests mount
// the real component (only the Nextcloud widgets are stubbed) and check what it actually sends:
// GET /member-search?search=… and POST /members/bulk with exactly {userIds, groupIds, role}.

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => url.replace(/\{(\w+)\}/g, (_, key) => String(params[key]))),
}))

const { selectStub } = vi.hoisted(() => ({ selectStub: { instance: null } }))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'loading', 'multiple', 'filterable', 'disabled', 'inputLabel', 'placeholder', 'label', 'closeOnSelect'],
		emits: ['update:modelValue', 'search'],
		created() {
			selectStub.instance = this
		},
		template: '<div class="nc-select-stub" />',
	},
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', props: ['disabled', 'variant', 'type'], template: '<button :disabled="disabled"><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}))

import CourseMemberPicker from '../../src/components/CourseMemberPicker.vue'
import { buildBulkPayload, toOptions } from '../../src/utils/member-picker.js'

globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [key, value]) => acc.split(`{${key}}`).join(String(value)), text)

const SEARCH_RESPONSE = {
	users: [
		{ id: 'alice', label: 'Alice Doe', is_member: false },
		{ id: 'staff', label: 'Staff Account', is_member: true },
	],
	groups: [{ id: 'staff', label: 'All staff' }],
}

describe('CourseMemberPicker (GitHub #35)', () => {
	let app
	let host
	let membersChanged

	function mountPicker() {
		membersChanged = vi.fn()
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(CourseMemberPicker, { courseId: 5, onMembersChanged: membersChanged }) })
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
	}

	async function flush() {
		for (let i = 0; i < 5; i++) {
			await Promise.resolve()
			await nextTick()
		}
	}

	async function searchFor(term) {
		selectStub.instance.$emit('search', term)
		vi.advanceTimersByTime(300)
		await flush()
	}

	function addButton() {
		return [...host.querySelectorAll('button')].find((b) => b.textContent.includes('Hinzufügen'))
	}

	beforeEach(() => {
		vi.useFakeTimers()
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: SEARCH_RESPONSE })
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
		vi.useRealTimers()
	})

	it('searches the course member-search endpoint with the typed term', async () => {
		mountPicker()

		await searchFor('al')

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/5/member-search', { params: { search: 'al' } })
		expect(selectStub.instance.options.map((o) => o.id)).toEqual(['user:alice', 'user:staff', 'group:staff'])
	})

	it('does not search for an empty term', async () => {
		mountPicker()

		await searchFor('   ')

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('posts the selected users and groups with the chosen role as {userIds, groupIds, role}', async () => {
		axios.post.mockResolvedValue({
			data: {
				added: [{ user_id: 'alice', display_name: 'Alice Doe' }, { user_id: 'carol', display_name: 'Carol' }],
				already: [{ user_id: 'bob', display_name: 'Bob' }],
				notFound: [],
				groupsUnavailable: [],
				failed: [],
			},
		})
		mountPicker()
		await searchFor('al')

		const [alice, , staffGroup] = selectStub.instance.options
		selectStub.instance.$emit('update:modelValue', [alice, staffGroup])
		const role = host.querySelector('select')
		role.value = 'instructor'
		role.dispatchEvent(new Event('change'))
		await flush()
		addButton().click()
		await flush()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/5/members/bulk', {
			userIds: ['alice'],
			groupIds: ['staff'],
			role: 'instructor',
		})
		expect(host.textContent).toContain('Hinzugefügt (2): Alice Doe, Carol')
		expect(host.textContent).toContain('Schon im Kurs (1): Bob')
		expect(membersChanged).toHaveBeenCalledTimes(1)
		expect(selectStub.instance.modelValue).toEqual([])
	})

	it('a user and a group with the same id stay distinct', async () => {
		mountPicker()
		await searchFor('staff')
		const [, staffUser, staffGroup] = selectStub.instance.options

		expect(buildBulkPayload([staffUser, staffGroup], 'student')).toEqual({ userIds: ['staff'], groupIds: ['staff'], role: 'student' })
	})

	it('shows a partial enrolment with the failed accounts and keeps the selection', async () => {
		axios.post.mockRejectedValue({
			response: {
				status: 500,
				data: {
					added: [{ user_id: 'alice', display_name: 'Alice Doe' }],
					already: [],
					notFound: ['ghost'],
					groupsUnavailable: ['board'],
					failed: [{ user_id: 'bob', display_name: 'Bob' }],
				},
			},
		})
		mountPicker()
		await searchFor('al')
		selectStub.instance.$emit('update:modelValue', [selectStub.instance.options[0]])
		await flush()

		addButton().click()
		await flush()

		expect(host.textContent).toContain('Konnte nicht hinzugefügt werden: Bob')
		expect(host.textContent).toContain('Nicht gefunden: ghost')
		expect(host.textContent).toContain('Gruppe nicht verfügbar: board')
		expect(host.querySelector('.note[data-type="error"]')).not.toBeNull()
		expect(membersChanged).toHaveBeenCalledTimes(1) // alice was added
		expect(selectStub.instance.modelValue).toHaveLength(1)
	})

	it('an existing member picked with another role is a warning, not a success', async () => {
		// "Kursleitung" chosen, an existing student picked: the server keeps the role and says so
		axios.post.mockResolvedValue({
			data: {
				added: [],
				already: [],
				roleUnchanged: [{ user_id: 'bob', display_name: 'Bob', role: 'student' }],
				notFound: [],
				groupsUnavailable: [],
				failed: [],
			},
		})
		mountPicker()
		await searchFor('al')
		selectStub.instance.$emit('update:modelValue', [selectStub.instance.options[0]])
		await flush()

		addButton().click()
		await flush()

		expect(host.textContent).toContain('Rolle nicht geändert (1): Bob (Lernende:r)')
		expect(host.textContent).not.toContain('Niemand wurde hinzugefügt')
		expect(host.querySelector('.note[data-type="warning"]')).not.toBeNull()
		expect(host.querySelector('.note[data-type="success"]')).toBeNull()
		expect(membersChanged).not.toHaveBeenCalled()
	})

	it('shows the server error when nothing could be enrolled (e.g. 403)', async () => {
		axios.post.mockRejectedValue({ response: { status: 403, data: { error: 'Nur Kursleitungen', code: 'forbidden' } } })
		mountPicker()
		await searchFor('al')
		selectStub.instance.$emit('update:modelValue', [selectStub.instance.options[0]])
		await flush()

		addButton().click()
		await flush()

		expect(host.textContent).toContain('Nur Kursleitungen')
		expect(membersChanged).not.toHaveBeenCalled()
	})

	it('keeps the Add button disabled until something is selected', async () => {
		mountPicker()
		await flush()

		expect(addButton().disabled).toBe(true)
	})
})

describe('member-picker helpers', () => {
	it('maps the search response to keyed options', () => {
		expect(toOptions({ users: [{ id: 'a', label: '', is_member: true }], groups: [{ id: 'g' }] })).toEqual([
			{ id: 'user:a', type: 'user', value: 'a', label: 'a', isMember: true },
			{ id: 'group:g', type: 'group', value: 'g', label: 'g', isMember: false },
		])
		expect(toOptions(null)).toEqual([])
	})
})
