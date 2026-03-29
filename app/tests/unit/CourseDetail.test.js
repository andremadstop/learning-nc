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
vi.mock('@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcEmptyContent.js', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcModal.js', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcTextField.js', () => ({ default: { name: 'NcTextField', template: '<input />' } }))
vi.mock('@nextcloud/vue/dist/Components/NcLoadingIcon.js', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/dist/Components/NcNoteCard.js', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))

vi.mock('../../src/components/ExamMode.vue', () => stub('ExamMode'))
vi.mock('../../src/components/LeagueTab.vue', () => stub('LeagueTab'))
vi.mock('../../src/components/LeitnerMode.vue', () => stub('LeitnerMode'))
vi.mock('../../src/components/StudentDetail.vue', () => stub('StudentDetail'))
vi.mock('../../src/components/DuelMode.vue', () => stub('DuelMode'))
vi.mock('../../src/components/GameshowMode.vue', () => stub('GameshowMode'))
vi.mock('../../src/components/TrainingMode.vue', () => stub('TrainingMode'))
vi.mock('../../src/components/ArenaSelector.vue', () => stub('ArenaSelector'))
vi.mock('../../src/components/OldschoolSelector.vue', () => stub('OldschoolSelector'))
vi.mock('../../src/components/WissensturmMode.vue', () => stub('WissensturmMode'))
vi.mock('../../src/components/LernwuerfelMode.vue', () => stub('LernwuerfelMode'))
vi.mock('../../src/components/AbenteuerMode.vue', () => stub('AbenteuerMode'))
vi.mock('../../src/components/CourseTabLernraum.vue', () => stub('CourseTabLernraum'))
vi.mock('../../src/components/CourseTabTeilnehmer.vue', () => stub('CourseTabTeilnehmer'))
vi.mock('../../src/components/CourseTabWettbewerb.vue', () => stub('CourseTabWettbewerb'))
vi.mock('../../src/components/CourseTabKommunikation.vue', () => stub('CourseTabKommunikation'))
vi.mock('../../src/components/CourseTabVerwaltung.vue', () => stub('CourseTabVerwaltung'))
vi.mock('../../src/components/CourseSummary.vue', () => stub('CourseSummary'))
vi.mock('../../src/components/StudentKnowledgeContribute.vue', () => stub('StudentKnowledgeContribute'))
vi.mock('../../src/components/CourseFeed.vue', () => stub('CourseFeed'))
vi.mock('../../src/components/BuddyMatching.vue', () => stub('BuddyMatching'))

import CourseDetail from '../../src/components/CourseDetail.vue'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof CourseDetail.data === 'function' ? CourseDetail.data() : {}
	const instance = {
		...data,
		courseId: 11,
		userRole: 'student',
		contentLanguage: 'de',
		presetDuelCode: '',
		course: {
			is_instructor: false,
			material_folder: null,
			mode_config: {
				training: true,
				leitner: true,
				exam: true,
				duel: true,
				gameshow: true,
				league: true,
				oldschool: true,
				abenteuer: false,
				course_summary: false,
			},
		},
		$root: {
			$emit: vi.fn(),
		},
		$emit: vi.fn(),
		$set: vi.fn((obj, key, value) => {
			obj[key] = value
		}),
		$delete: vi.fn((obj, key) => {
			delete obj[key]
		}),
		$nextTick: vi.fn((cb) => {
			if (typeof cb === 'function') cb()
		}),
		...overrides,
	}

	Object.defineProperties(instance, {
		isInstructor: { get: () => CourseDetail.computed.isInstructor.call(instance) },
		isCourseSummaryReleased: { get: () => CourseDetail.computed.isCourseSummaryReleased.call(instance) },
		lernraumLeafTabs: { get: () => CourseDetail.computed.lernraumLeafTabs.call(instance) },
		teilnehmerLeafTabs: { get: () => CourseDetail.computed.teilnehmerLeafTabs.call(instance) },
		wettbewerbLeafTabs: { get: () => CourseDetail.computed.wettbewerbLeafTabs.call(instance) },
		visibleTabs: { get: () => CourseDetail.computed.visibleTabs.call(instance) },
		hasEnabledArenaModes: { get: () => CourseDetail.computed.hasEnabledArenaModes.call(instance) },
	})

	for (const [name, fn] of Object.entries(CourseDetail.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('CourseDetail navigation logic', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('normalizes missing mode config keys with adventure and summary disabled by default', () => {
		const instance = createInstance()

		expect(instance.normalizeModeConfig({ duel: false })).toEqual({
			training: true,
			leitner: true,
			swipe: true,
			exam: true,
			duel: false,
			gameshow: true,
			league: true,
			oldschool: true,
			abenteuer: false,
			course_summary: false,
		})
	})

	it('shows mega-tabs and delegates summary to teilnehmerLeafTabs', () => {
		const hiddenInstance = createInstance()
		expect(hiddenInstance.visibleTabs.map((tab) => tab.id)).toContain('lernraum')
		expect(hiddenInstance.visibleTabs.map((tab) => tab.id)).not.toContain('training')
		// summary is now a leaf inside teilnehmer mega-tab, not in visibleTabs directly
		expect(hiddenInstance.teilnehmerLeafTabs).toEqual(['my-progress'])

		const releasedInstance = createInstance({
			course: {
				is_instructor: false,
				material_folder: null,
				mode_config: {
					training: true,
					leitner: true,
					exam: true,
					duel: true,
					gameshow: true,
					league: true,
					oldschool: true,
					abenteuer: false,
					course_summary: true,
				},
			},
		})

		expect(releasedInstance.teilnehmerLeafTabs).toContain('summary')
		expect(releasedInstance.teilnehmerLeafTabs).toEqual(['my-progress', 'summary'])
	})

	it('keeps the instructor mega-tabs and delegates summary to teilnehmerLeafTabs', () => {
		const instance = createInstance({
			course: {
				is_instructor: true,
				material_folder: null,
				mode_config: {
					training: true,
					leitner: true,
					exam: true,
					duel: true,
					gameshow: true,
					league: true,
					oldschool: true,
					abenteuer: false,
					course_summary: false,
				},
			},
		})

		expect(instance.visibleTabs[0].id).toBe('lernraum')
		expect(instance.visibleTabs.map((tab) => tab.id)).not.toContain('pools')
		// Instructor has teilnehmer mega-tab, summary is a leaf within it
		expect(instance.visibleTabs.map((tab) => tab.id)).toContain('teilnehmer')
		expect(instance.teilnehmerLeafTabs).toContain('summary')
	})

	it('treats Lernraum leaf tabs as active via the collapsed mega-tab', () => {
		const instance = createInstance({
			currentTab: 'exam',
		})

		expect(instance.lernraumLeafTabs).toEqual(['training', 'leitner', 'exam'])
		expect(instance.isLernraumTab('exam')).toBe(true)
		expect(instance.isTabActive('lernraum')).toBe(true)
		expect(instance.isTabActive('leaderboard')).toBe(false)
	})

	it('falls back to the default Lernraum leaf when the mega-tab is selected directly', () => {
		const studentInstance = createInstance({
			currentTab: 'leaderboard',
		})

		studentInstance.selectTab('lernraum')

		expect(studentInstance.currentTab).toBe('training')
		expect(studentInstance.$root.$emit).toHaveBeenCalledWith('course:tab-change', 'training')

		const instructorInstance = createInstance({
			currentTab: 'leaderboard',
			course: {
				is_instructor: true,
				material_folder: null,
				mode_config: {
					training: true,
					leitner: true,
					exam: true,
					duel: true,
					gameshow: true,
					league: true,
					oldschool: true,
					abenteuer: false,
					course_summary: false,
				},
			},
			userRole: 'instructor',
		})

		instructorInstance.selectTab('lernraum')

		expect(instructorInstance.currentTab).toBe('pools')
		expect(instructorInstance.$root.$emit).toHaveBeenCalledWith('course:tab-change', 'pools')
	})
})
