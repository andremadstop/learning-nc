/**
 * GitHub #60: several instructor groups and individual people, picked from Nextcloud.
 *
 * The admin page used to send one typed group name as instructor_group with the main settings.
 * Now the list loads from and saves to /api/settings/admin/instructors as {groupIds, userIds};
 * the main settings no longer carry the old field (the server would otherwise overwrite the
 * groups' 5.11 key on every save), and an instructor list that never loaded is never saved
 * (an empty list would put the default group in place of the configured ones).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, str) => str }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel'],
		emits: ['search', 'update:modelValue'],
		template: '<div class="select-stub" :data-label="inputLabel"><span v-for="o in modelValue" :key="o.id" class="chip">{{ o.label }}</span></div>',
	},
}))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'
import AdminSettings from '../../src/components/AdminSettings.vue'
import InstructorPicker from '../../src/components/InstructorPicker.vue'
import { toInstructorPayload, toOptions } from '../../src/utils/member-picker.js'

const INSTRUCTORS = {
	groups: [{ id: 'training', label: 'Training Department' }, { id: 'hr', label: 'HR' }],
	users: [{ id: 'carla', label: 'Carla Klein' }],
}

function createInstance() {
	const instance = { ...AdminSettings.data(), $emit: vi.fn() }
	for (const [name, fn] of Object.entries(AdminSettings.methods)) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

function mockGets({ instructorsFail = false } = {}) {
	axios.get.mockImplementation((url) => {
		if (url.includes('/settings/tools')) {
			return Promise.resolve({ data: { enabled_tools: [] } })
		}
		if (url.endsWith('/settings/admin/instructors')) {
			return instructorsFail ? Promise.reject(new Error('500')) : Promise.resolve({ data: INSTRUCTORS })
		}
		return Promise.resolve({ data: { instructor_group: 'training' } })
	})
}

function putBody(url) {
	const call = axios.put.mock.calls.find(([u]) => u === url)
	return call ? call[1] : null
}

beforeEach(() => {
	vi.clearAllMocks()
	axios.put.mockImplementation((url, body) => Promise.resolve({
		data: url.endsWith('/instructors') ? { groups: body.groupIds.map((id) => ({ id, label: id })), users: body.userIds.map((id) => ({ id, label: id })) } : {},
	}))
})

describe('#60 admin page: instructor groups and people', () => {
	it('loads the configured groups and people into the picker', async () => {
		mockGets()
		const instance = createInstance()
		await instance.load()
		expect(instance.instructorsLoaded).toBe(true)
		expect(instance.form.instructors.map((o) => o.id)).toEqual(['user:carla', 'group:training', 'group:hr'])
		expect(instance.form.instructors.find((o) => o.id === 'group:training').label).toBe('Training Department')
	})

	it('saves groups and people to the instructor endpoint and no longer sends the single group', async () => {
		mockGets()
		const instance = createInstance()
		await instance.load()
		instance.form.instructors = [
			...instance.form.instructors,
			{ id: 'user:dora', type: 'user', value: 'dora', label: 'Dora' },
		]
		await instance.save()
		expect(putBody('/apps/learning/api/settings/admin/instructors')).toEqual({
			groupIds: ['training', 'hr'],
			userIds: ['carla', 'dora'],
		})
		expect(putBody('/apps/learning/api/settings/admin')).not.toHaveProperty('instructor_group')
		expect(instance.saved).toBe(true)
	})

	it('never saves an instructor list that did not load', async () => {
		const instance = createInstance()
		await instance.save()
		expect(putBody('/apps/learning/api/settings/admin/instructors')).toBeNull()

		mockGets({ instructorsFail: true })
		const failed = createInstance()
		await failed.load()
		expect(failed.instructorsLoaded).toBe(false)
		await failed.save()
		expect(putBody('/apps/learning/api/settings/admin/instructors')).toBeNull()
	})

	it('a failing instructor list costs the picker, not the rest of the admin page', async () => {
		mockGets({ instructorsFail: true })
		axios.get.mockImplementation((url) => {
			if (url.includes('/settings/tools')) {
				return Promise.resolve({ data: { enabled_tools: [] } })
			}
			if (url.endsWith('/settings/admin/instructors')) {
				return Promise.reject(new Error('500'))
			}
			return Promise.resolve({ data: { exam_attempt_cooldown_minutes: 0, exam_attempt_limit_per_day: 7 } })
		})
		const instance = createInstance()
		await instance.load()
		expect(instance.error).toBe('')
		expect(instance.form.examAttemptLimitPerDay).toBe(7)
		expect(instance.form.examAttemptCooldownMinutes).toBe(0)
		expect(instance.instructorsLoaded).toBe(false)
		expect(instance.instructorsError).toBe('Die Kursleitenden konnten nicht geladen werden.')
		expect(instance.loading).toBe(false)
	})

	it('toInstructorPayload splits the picker options by type', () => {
		const options = toOptions({ users: [{ id: 'g1', label: 'A user called g1' }], groups: [{ id: 'g1', label: 'Group g1' }] })
		expect(toInstructorPayload(options)).toEqual({ groupIds: ['g1'], userIds: ['g1'] })
		expect(toInstructorPayload(undefined)).toEqual({ groupIds: [], userIds: [] })
	})
})

describe('#60 InstructorPicker', () => {
	let host, app

	afterEach(() => {
		app?.unmount()
		host?.remove()
		vi.useRealTimers()
	})

	it('searches the admin instructor endpoint (not the course member search) and offers groups and people', async () => {
		vi.useFakeTimers()
		axios.get.mockResolvedValue({ data: { users: [{ id: 'travis', label: 'Travis Lee' }], groups: [{ id: 'training', label: 'Training Department' }] } })
		host = document.createElement('div')
		document.body.appendChild(host)
		let picker
		app = createApp({
			components: { InstructorPicker },
			data: () => ({ value: [] }),
			template: '<InstructorPicker ref="p" v-model="value" />',
			mounted() { picker = this.$refs.p },
		})
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		expect(host.querySelector('.select-stub').dataset.label).toBe('Kursleitende (Gruppen und Personen)')

		picker.onSearch('  tra ')
		await vi.advanceTimersByTimeAsync(300)
		await nextTick()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/settings/admin/instructor-search', { params: { search: 'tra' } })
		expect(picker.options.map((o) => o.id)).toEqual(['user:travis', 'group:training'])
		expect(picker.optionHint(picker.options[1])).toBe('Gruppe')
		expect(picker.optionHint(picker.options[0])).toBe('travis')
	})
})
