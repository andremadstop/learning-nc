import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'

// GitHub #35: the course form's group field. On create it is sent when filled, on edit it is
// ALWAYS sent ('' removes the group) — before, the edit form dropped an emptied field and the
// server never read the field at all. The server's enrolment summary is shown, and a group the
// server rejects keeps the dialog open with a message instead of a generic failure.

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

describe('CourseList group field (GitHub #35)', () => {
	beforeEach(() => vi.clearAllMocks())

	it('create sends the trimmed group and reports how many were enrolled', async () => {
		axios.post.mockResolvedValue({ data: { id: 9, enrollment: { group_id: 'team', added: 3, already: 1, failed: 0, error: null } } })
		const vm = instance({ formData: { title: 'Kurs', description: '', ncGroupId: ' team ' } })

		await vm.saveCourse()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses', { title: 'Kurs', ncGroupId: 'team' })
		expect(showSuccess).toHaveBeenCalledWith('Gruppe „team“ eingeschrieben – neu: 3, schon im Kurs: 1.')
	})

	it('create without a group does not send the field', async () => {
		axios.post.mockResolvedValue({ data: { id: 9 } })
		const vm = instance({ formData: { title: 'Kurs', description: '', ncGroupId: '' } })

		await vm.saveCourse()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses', { title: 'Kurs' })
	})

	it('edit always sends the group, an empty one to remove it', async () => {
		axios.put.mockResolvedValue({ data: { id: 4 } })
		const vm = instance({ editingCourse: { id: 4 }, formData: { title: 'Kurs', description: '', ncGroupId: '' } })

		await vm.saveCourse()

		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/courses/4', { title: 'Kurs', description: '', ncGroupId: '' })
	})

	// Audit #94: an emptied description was left out of the PUT, and the server keeps the old
	// text for an omitted field.
	it('edit sends an emptied description so the server clears it', async () => {
		axios.put.mockResolvedValue({ data: { id: 4 } })
		const vm = instance({ editingCourse: { id: 4 }, formData: { title: 'A', description: '   ', ncGroupId: '' } })

		await vm.saveCourse()

		expect(axios.put.mock.calls[0][1]).toHaveProperty('description', '')
	})

	it('a failed group enrolment is shown as an error, not as success', async () => {
		axios.put.mockResolvedValue({ data: { id: 4, enrollment: { group_id: 'team', added: 0, already: 0, failed: 2, error: 'failed' } } })
		const vm = instance({ editingCourse: { id: 4 }, formData: { title: 'Kurs', description: '', ncGroupId: 'team' } })

		await vm.saveCourse()

		expect(showError).toHaveBeenCalledTimes(1)
		expect(showError.mock.calls[0][0]).toContain('„team“')
	})

	it('a group the server rejects keeps the dialog open with an explanation', async () => {
		axios.post.mockRejectedValue({ response: { status: 400, data: { error: 'Group not available', code: 'group_unavailable' } } })
		const vm = instance({ formData: { title: 'Kurs', description: '', ncGroupId: 'board' } })

		await vm.saveCourse()

		expect(vm.closeModal).not.toHaveBeenCalled()
		expect(vm.modalError).toContain('„board“')
		expect(vm.modalError).toContain('nicht gespeichert')
	})

	it('a group too large to enrol keeps the dialog open and names the limit', async () => {
		axios.post.mockRejectedValue({ response: { status: 400, data: { error: 'Group too large', code: 'too_many', max: 1000 } } })
		const vm = instance({ formData: { title: 'Kurs', description: '', ncGroupId: 'everyone' } })

		await vm.saveCourse()

		expect(vm.closeModal).not.toHaveBeenCalled()
		expect(vm.modalError).toContain('„everyone“')
		expect(vm.modalError).toContain('1000')
		expect(vm.modalError).toContain('nicht gespeichert')
	})

	it('editing a course whose group is already set says that saving enrols nobody', () => {
		const groupAlreadySet = (vm) => CourseList.computed.groupAlreadySet.call(vm)
		const editing = { id: 4, nc_group_id: 'team' }

		expect(groupAlreadySet({ editingCourse: editing, formData: { ncGroupId: 'team' } })).toBe(true)
		// a changed or removed group will enrol / remove, so the hint goes away
		expect(groupAlreadySet({ editingCourse: editing, formData: { ncGroupId: 'other' } })).toBe(false)
		expect(groupAlreadySet({ editingCourse: editing, formData: { ncGroupId: '' } })).toBe(false)
		expect(groupAlreadySet({ editingCourse: { id: 4, nc_group_id: null }, formData: { ncGroupId: '' } })).toBe(false)
		expect(groupAlreadySet({ editingCourse: null, formData: { ncGroupId: 'team' } })).toBe(false)
	})
})
