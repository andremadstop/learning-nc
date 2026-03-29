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
vi.mock('@nextcloud/vue/dist/Components/NcLoadingIcon.js', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))

vi.mock('../../src/components/LeagueTab.vue', () => stub('LeagueTab'))
vi.mock('../../src/components/ArenaSelector.vue', () => stub('ArenaSelector'))
vi.mock('../../src/components/DuelMode.vue', () => stub('DuelMode'))
vi.mock('../../src/components/GameshowMode.vue', () => stub('GameshowMode'))
vi.mock('../../src/components/OldschoolSelector.vue', () => stub('OldschoolSelector'))
vi.mock('../../src/components/WissensturmMode.vue', () => stub('WissensturmMode'))
vi.mock('../../src/components/LernwuerfelMode.vue', () => stub('LernwuerfelMode'))
vi.mock('../../src/components/AbenteuerMode.vue', () => stub('AbenteuerMode'))

import CourseTabWettbewerb from '../../src/components/CourseTabWettbewerb.vue'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof CourseTabWettbewerb.data === 'function' ? CourseTabWettbewerb.data() : {}
	const instance = {
		...data,
		courseId: 5,
		course: {
			is_instructor: true,
			mode_config: {
				training: true,
				leitner: true,
				exam: true,
				duel: true,
				gameshow: true,
				league: true,
				oldschool: true,
				abenteuer: false,
			},
		},
		userRole: 'instructor',
		coursePools: [],
		activeTab: 'leaderboard',
		contentLanguage: 'de',
		presetDuelCode: '',
		$emit: vi.fn(),
		...overrides,
	}

	Object.defineProperties(instance, {
		isInstructor: { get: () => CourseTabWettbewerb.computed.isInstructor.call(instance) },
		visibleSubTabs: { get: () => CourseTabWettbewerb.computed.visibleSubTabs.call(instance) },
		hasEnabledArenaModes: { get: () => CourseTabWettbewerb.computed.hasEnabledArenaModes.call(instance) },
	})

	for (const [name, fn] of Object.entries(CourseTabWettbewerb.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('CourseTabWettbewerb', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('builds instructor sub-tabs: leaderboard, league, arena, abenteuer', () => {
		const instance = createInstance()

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['leaderboard', 'league', 'arena', 'abenteuer'])
	})

	it('hides arena pill for students when all arena modes are disabled', () => {
		const instance = createInstance({
			course: {
				is_instructor: false,
				mode_config: {
					duel: false,
					gameshow: false,
					oldschool: false,
					league: true,
					abenteuer: false,
				},
			},
			userRole: 'student',
		})

		const ids = instance.visibleSubTabs.map((tab) => tab.id)
		expect(ids).toContain('leaderboard')
		expect(ids).toContain('league')
		expect(ids).not.toContain('arena')
		expect(ids).not.toContain('abenteuer')
	})

	it('presetDuelCode prop triggers arenaSubMode=duel and currentSubTab=arena', () => {
		const instance = createInstance({
			presetDuelCode: 'ABC123',
		})

		// Simulate the watcher manually
		const handler = CourseTabWettbewerb.watch.presetDuelCode.handler
		handler.call(instance, 'ABC123')

		expect(instance.currentSubTab).toBe('arena')
		expect(instance.arenaSubMode).toBe('duel')
	})

	it('emits tab-change with correct leaf ID when selecting a sub-tab', () => {
		const instance = createInstance()

		instance.selectSubTab('league')

		expect(instance.currentSubTab).toBe('league')
		expect(instance.$emit).toHaveBeenCalledWith('tab-change', 'league')
	})

	it('resets arenaSubMode when switching away from arena', () => {
		const instance = createInstance({
			arenaSubMode: 'duel',
			currentSubTab: 'arena',
		})

		instance.selectSubTab('leaderboard')

		expect(instance.arenaSubMode).toBeNull()
		expect(instance.oldschoolSubMode).toBeNull()
	})
})
