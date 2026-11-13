/**
 * GitHub #35: the student view shows courses the way learners see them.
 *
 * - actsAsCourseInstructor(): the one gate for the instructor tabs of a course. Only the course
 *   membership counts, never the global role; the student view turns it off.
 * - lernraumTabIds(): the Lernraum leaf list shared by CourseDetail and CourseTabLernraum.
 * - CourseList (rendered): in the student view an instructor still finds the own courses (that is
 *   how a course is previewed) but without create, edit, archive or delete.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
})

const { api } = vi.hoisted(() => ({ api: { courses: { own: [], enrolled: [] } } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: api.courses })),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { stub } = vi.hoisted(() => ({ stub: (name, cls) => ({ default: { name, template: `<div class="${cls}"><slot /></div>` } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton', 'nc-button'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent', 'nc-empty'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal', 'nc-modal'))
vi.mock('@nextcloud/vue/components/NcTextField', () => stub('NcTextField', 'nc-text-field'))
vi.mock('@nextcloud/vue/components/NcActions', () => stub('NcActions', 'nc-actions'))
vi.mock('@nextcloud/vue/components/NcActionButton', () => stub('NcActionButton', 'nc-action-button'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon', 'nc-loading'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard', 'nc-note'))

import CourseList from '../../src/components/CourseList.vue'
import { actsAsCourseInstructor, lernraumTabIds } from '../../src/utils/courseView.js'

describe('actsAsCourseInstructor (GitHub #35)', () => {
	it('follows the course membership, not a global role', () => {
		expect(actsAsCourseInstructor({ is_instructor: true }, false)).toBe(true)
		expect(actsAsCourseInstructor({ is_instructor: false }, false)).toBe(false)
	})

	it('the student view turns the instructor tabs off', () => {
		expect(actsAsCourseInstructor({ is_instructor: true }, true)).toBe(false)
	})

	it('a missing course or a non-boolean flag is no instructor', () => {
		expect(actsAsCourseInstructor(null, false)).toBe(false)
		expect(actsAsCourseInstructor({ is_instructor: 1 }, false)).toBe(false)
	})
})

describe('lernraumTabIds (GitHub #35)', () => {
	const course = { mode_config: { training: true, leitner: true, exam: true }, practice_enabled: true, material_folder: '/M' }

	it('instructor list', () => {
		expect(lernraumTabIds(course, { actsAsInstructor: true, hasCourseTools: true })).toEqual(['pools', 'tools', 'curriculum', 'materials', 'knowledge'])
		expect(lernraumTabIds(course, { actsAsInstructor: true })).toEqual(['pools', 'curriculum', 'materials', 'knowledge'])
	})

	it('learner list in display order, required content first', () => {
		expect(lernraumTabIds(course, { hasVideos: true, hasCourseTools: true }))
			.toEqual(['videos', 'training', 'leitner', 'exam', 'practice', 'tools', 'materials'])
	})

	it('a disabled Training is not listed; a missing mode_config means every mode is on', () => {
		expect(lernraumTabIds({ mode_config: { training: false } })).toEqual(['leitner', 'exam'])
		expect(lernraumTabIds({ mode_config: null })).toEqual(['training', 'leitner', 'exam'])
	})
})

describe('CourseList in the student view (GitHub #35)', () => {
	let host, app

	async function mountList(props) {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(CourseList, props) })
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = (_a, s, p, c) => (c === 1 ? s : p)
		app.config.warnHandler = () => {}
		app.mount(host)
		for (let i = 0; i < 4; i++) {
			await new Promise((r) => setTimeout(r, 0))
			await nextTick()
		}
	}

	beforeEach(() => {
		localStorage.clear()
		api.courses = {
			own: [{ id: 1, title: 'Arbeitsschutz 2026', status: 'active', pool_count: 2, member_count: 20 }],
			enrolled: [{ id: 2, title: 'Kollegenkurs', member_role: 'student', pool_count: 1 }],
		}
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('keeps the own courses listed and openable, without create, edit, archive or delete', async () => {
		await mountList({ userRole: 'student', studentViewActive: true })

		const own = host.querySelector('.own-courses')
		expect(own).not.toBeNull()
		expect(own.textContent).toContain('Arbeitsschutz 2026')
		expect(host.querySelectorAll('.nc-actions')).toHaveLength(0)
		expect(host.textContent).not.toContain('+ Create Course')
		expect(host.textContent).toContain('Kollegenkurs')
	})

	it('an instructor outside the student view keeps the management actions', async () => {
		await mountList({ userRole: 'instructor', studentViewActive: false })

		expect(host.querySelector('.own-courses')).not.toBeNull()
		expect(host.querySelectorAll('.nc-actions')).toHaveLength(1)
		expect(host.textContent).toContain('+ Create Course')
	})

	it('a real student sees no own-course section', async () => {
		await mountList({ userRole: 'student', studentViewActive: false })

		expect(host.querySelector('.own-courses')).toBeNull()
	})
})
