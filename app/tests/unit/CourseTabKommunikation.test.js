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
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['announcements', 'feed', 'requests'])
	})

	it('builds student sub-tabs: feed, buddies, schwarm', () => {
		const instance = createInstance()

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['feed', 'buddies', 'schwarm'])
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
