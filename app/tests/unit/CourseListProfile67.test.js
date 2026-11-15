import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'

// GitHub #67: the course profile in the create dialog. "Pflichtschulung mit Zertifikat" is sent as
// profile=mandatory (the server applies the switches); the IT profile is the server default and
// keeps the request as before. Editing a course never sends a profile.

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url, p = {}) => url.replace(/\{(\w+)\}/g, (_, k) => String(p[k]))) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div><slot /></div>' } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('@nextcloud/vue/components/NcTextField', () => stub('NcTextField'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))

import CourseList from '../../src/components/CourseList.vue'

globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), text)

function instance(overrides = {}) {
	const data = CourseList.data.call({})
	const vm = {
		...data,
		userRole: 'instructor',
		closeModal: vi.fn(),
		fetchCourses: vi.fn(),
		$emit: vi.fn(),
		...overrides,
	}
	for (const [name, fn] of Object.entries(CourseList.methods)) {
		if (!vi.isMockFunction(vm[name])) {
			vm[name] = fn.bind(vm)
		}
	}
	return vm
}

describe('CourseList course profile (GitHub #67)', () => {
	beforeEach(() => vi.clearAllMocks())

	it('offers the two profiles, IT preselected', () => {
		const vm = instance()
		expect(vm.formData.profile).toBe('it')
		const options = CourseList.computed.profileOptions.call(vm)
		expect(options.map((o) => o.value)).toEqual(['it', 'mandatory'])
		expect(options[1].label).toBe('Pflichtschulung mit Zertifikat')
		expect(options[1].description).toContain('Leitner')
		expect(options[1].description).toContain('LinkedIn')
	})

	it('create with the mandatory profile sends it', async () => {
		axios.post.mockResolvedValue({ data: { id: 9 } })
		const vm = instance({ formData: { title: 'Brandschutz', description: '', ncGroupId: '', profile: 'mandatory' } })

		await vm.saveCourse()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses', { title: 'Brandschutz', profile: 'mandatory' })
	})

	it('create with the IT profile keeps the request as before', async () => {
		axios.post.mockResolvedValue({ data: { id: 9 } })
		const vm = instance({ formData: { title: 'Netzwerk', description: '', ncGroupId: '', profile: 'it' } })

		await vm.saveCourse()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses', { title: 'Netzwerk' })
	})

	it('editing a course never sends a profile', async () => {
		axios.put.mockResolvedValue({ data: { id: 9 } })
		const vm = instance({
			editingCourse: { id: 9, nc_group_id: null },
			formData: { title: 'Brandschutz', description: '', ncGroupId: '', profile: 'mandatory' },
		})

		await vm.saveCourse()

		expect(axios.put.mock.calls[0][1]).not.toHaveProperty('profile')
	})

	it('opening the create dialog again starts with the IT profile', () => {
		const vm = instance({ formData: { title: 'x', description: '', ncGroupId: '', profile: 'mandatory' } })
		vm.openCreateModal()
		expect(vm.formData.profile).toBe('it')
	})
})
