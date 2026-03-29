import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => ({ uid: 'alice' })),
}))

vi.mock('@nextcloud/vue/dist/Components/NcButton.js', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcEmptyContent.js', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcModal.js', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcTextField.js', () => ({ default: { name: 'NcTextField', template: '<input />' } }))
vi.mock('@nextcloud/vue/dist/Components/NcLoadingIcon.js', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/dist/Components/NcNoteCard.js', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))

vi.mock('../../src/components/StudentDetail.vue', () => stub('StudentDetail'))
vi.mock('../../src/components/CourseSummary.vue', () => stub('CourseSummary'))
vi.mock('../../src/components/BuddyMatching.vue', () => stub('BuddyMatching'))

import CourseTabTeilnehmer from '../../src/components/CourseTabTeilnehmer.vue'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof CourseTabTeilnehmer.data === 'function' ? CourseTabTeilnehmer.data() : {}
	const instance = {
		...data,
		courseId: 5,
		course: {
			is_instructor: true,
			mode_config: {
				course_summary: false,
			},
		},
		userRole: 'instructor',
		courseMembers: [],
		coursePools: [],
		activeTab: 'members',
		$emit: vi.fn(),
		$delete: vi.fn(),
		...overrides,
	}

	Object.defineProperties(instance, {
		isInstructor: { get: () => CourseTabTeilnehmer.computed.isInstructor.call(instance) },
		isCourseSummaryReleased: { get: () => CourseTabTeilnehmer.computed.isCourseSummaryReleased.call(instance) },
		visibleSubTabs: { get: () => CourseTabTeilnehmer.computed.visibleSubTabs.call(instance) },
	})

	for (const [name, fn] of Object.entries(CourseTabTeilnehmer.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('CourseTabTeilnehmer', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('builds instructor sub-tabs: members, progress, heatmap, weak-questions, class-profile, summary', () => {
		const instance = createInstance()

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual([
			'members', 'progress', 'heatmap', 'weak-questions', 'class-profile', 'summary',
		])
	})

	it('builds student sub-tabs with my-progress only (no summary when disabled)', () => {
		const instance = createInstance({
			course: {
				is_instructor: false,
				mode_config: { course_summary: false },
			},
			userRole: 'student',
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['my-progress'])
	})

	it('shows summary pill for students when course_summary is enabled', () => {
		const instance = createInstance({
			course: {
				is_instructor: false,
				mode_config: { course_summary: true },
			},
			userRole: 'student',
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['my-progress', 'summary'])
	})

	it('emits tab-change with correct leaf ID when selecting a sub-tab', () => {
		const instance = createInstance()

		instance.selectSubTab('progress')

		expect(instance.currentSubTab).toBe('progress')
		expect(instance.$emit).toHaveBeenCalledWith('tab-change', 'progress')
	})

	it('computes pool mastery correctly from row data', () => {
		const instance = createInstance()

		const row = {
			pools: [
				{ pool_id: 10, mastered: 8, total_questions: 10 },
				{ pool_id: 20, mastered: 0, total_questions: 5 },
			],
		}

		expect(instance.getPoolMastery(row, 10)).toBe(80)
		expect(instance.getPoolMastery(row, 20)).toBe(0)
		expect(instance.getPoolMastery(row, 99)).toBeNull()
	})
})
