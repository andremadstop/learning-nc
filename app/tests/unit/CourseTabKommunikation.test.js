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

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))

vi.mock('../../src/components/CourseFeed.vue', () => stub('CourseFeed'))
vi.mock('../../src/components/BuddyMatching.vue', () => stub('BuddyMatching'))
vi.mock('../../src/components/StudentKnowledgeContribute.vue', () => stub('StudentKnowledgeContribute'))

import CourseTabKommunikation from '../../src/components/CourseTabKommunikation.vue'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof CourseTabKommunikation.data === 'function' ? CourseTabKommunikation.data() : {}
	const instance = {
		...data,
		courseId: 5,
		course: {
			is_instructor: false,
		},
		userRole: 'student',
		activeTab: 'feed',
		$emit: vi.fn(),
		$delete: vi.fn(),
		// The prop CourseDetail hands down. Set per test; the harness does not re-derive it from
		// the course (that copy of CourseDetail's rule would test itself, not the component).
		actsAsInstructor: false,
		...overrides,
	}

	Object.defineProperties(instance, {
		isInstructor: { get: () => CourseTabKommunikation.computed.isInstructor.call(instance) },
		visibleSubTabs: { get: () => CourseTabKommunikation.computed.visibleSubTabs.call(instance) },
	})

	for (const [name, fn] of Object.entries(CourseTabKommunikation.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('CourseTabKommunikation', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('builds instructor sub-tabs: announcements, feed, requests', () => {
		const instance = createInstance({
			course: { is_instructor: true },
			userRole: 'instructor',
			actsAsInstructor: true,
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['announcements', 'feed', 'requests'])
	})

	// GitHub #35: the student view must win over the course membership in every mega-tab.
	it('the student view (actsAsInstructor false) shows an own course with the learner sub-tabs', () => {
		const instance = createInstance({ course: { is_instructor: true }, userRole: 'student', actsAsInstructor: false, aiEnabled: true })

		expect(instance.isInstructor).toBe(false)
		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['feed', 'buddies', 'schwarm'])
	})

	it('builds student sub-tabs: feed, buddies, schwarm', () => {
		const instance = createInstance({ aiEnabled: true })

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['feed', 'buddies', 'schwarm'])
	})

	// GitHub #35: with AI off the knowledge moderation is gone, so nobody could ever read a swarm
	// contribution; the swarm is left out instead of collecting into a queue nobody opens.
	it('leaves out the swarm while the admin has AI switched off', () => {
		const instance = createInstance({ aiEnabled: false })

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['feed', 'buddies'])
	})

	it('emits tab-change with correct leaf ID when selecting a sub-tab', () => {
		const instance = createInstance({
			currentSubTab: 'feed',
		})

		instance.selectSubTab('buddies')

		expect(instance.$emit).toHaveBeenCalledWith('tab-change', 'buddies')
		expect(instance.currentSubTab).toBe('buddies')
	})

	it('syncs from activeTab and falls back to default when tab is invalid', () => {
		const instance = createInstance({
			currentSubTab: 'feed',
		})

		instance.syncFromActiveTab('unknown-tab')

		expect(instance.currentSubTab).toBe('feed')
	})

	it('component has correct name', () => {
		expect(CourseTabKommunikation.name).toBe('CourseTabKommunikation')
	})
})
