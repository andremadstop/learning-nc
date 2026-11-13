import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

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

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<input />' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

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
import { useCourseStore } from '../../src/stores/courseStore.js'

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
		studentViewActive: false,
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
		actsAsInstructor: { get: () => CourseDetail.computed.actsAsInstructor.call(instance) },
		isInstructor: { get: () => CourseDetail.computed.isInstructor.call(instance) },
		isCourseSummaryReleased: { get: () => CourseDetail.computed.isCourseSummaryReleased.call(instance) },
		hasCourseTools: { get: () => CourseDetail.computed.hasCourseTools.call(instance) },
		lernraumLeafTabs: { get: () => CourseDetail.computed.lernraumLeafTabs.call(instance) },
		teilnehmerLeafTabs: { get: () => CourseDetail.computed.teilnehmerLeafTabs.call(instance) },
		wettbewerbLeafTabs: { get: () => CourseDetail.computed.wettbewerbLeafTabs.call(instance) },
		kommunikationLeafTabs: { get: () => CourseDetail.computed.kommunikationLeafTabs.call(instance) },
		verwaltungLeafTabs: { get: () => CourseDetail.computed.verwaltungLeafTabs.call(instance) },
		visibleMegaTabs: { get: () => CourseDetail.computed.visibleMegaTabs.call(instance) },
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
		setActivePinia(createPinia())
	})

	it('normalizes missing mode config keys with adventure and summary disabled by default', () => {
		const instance = createInstance()

		expect(instance.normalizeModeConfig({ duel: false })).toEqual({
			training: true,
			leitner: true,
			exam: true,
			duel: false,
			gameshow: true,
			league: true,
			abenteuer: false,
			course_summary: false,
			tools: true,
		})
	})

	it('shows mega-tabs and delegates summary to teilnehmerLeafTabs', () => {
		const hiddenInstance = createInstance()
		expect(hiddenInstance.visibleMegaTabs.map((tab) => tab.id)).toContain('lernraum')
		expect(hiddenInstance.visibleMegaTabs.map((tab) => tab.id)).not.toContain('training')
		// summary is now a leaf inside teilnehmer mega-tab, not in visibleMegaTabs directly
		expect(hiddenInstance.teilnehmerLeafTabs).toEqual(['classbook', 'my-progress'])

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
						abenteuer: false,
					course_summary: true,
				},
			},
		})

		expect(releasedInstance.teilnehmerLeafTabs).toContain('summary')
		expect(releasedInstance.teilnehmerLeafTabs).toEqual(['classbook', 'my-progress', 'summary'])
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
						abenteuer: false,
					course_summary: false,
				},
			},
		})

		expect(instance.visibleMegaTabs[0].id).toBe('lernraum')
		expect(instance.visibleMegaTabs.map((tab) => tab.id)).not.toContain('pools')
		// Instructor has teilnehmer mega-tab, summary is a leaf within it
		expect(instance.visibleMegaTabs.map((tab) => tab.id)).toContain('teilnehmer')
		expect(instance.teilnehmerLeafTabs).toContain('summary')
	})

	it('instructor sees exactly 5 mega-tabs', () => {
		const instance = createInstance({
			course: {
				is_instructor: true,
				material_folder: null,
				mode_config: {},
			},
		})

		const ids = instance.visibleMegaTabs.map((tab) => tab.id)
		expect(ids).toEqual(['lernraum', 'teilnehmer', 'wettbewerb', 'kommunikation', 'verwaltung'])
		expect(instance.visibleMegaTabs).toHaveLength(5)
	})

	it('student sees 4 mega-tabs (no Verwaltung)', () => {
		const instance = createInstance()

		const ids = instance.visibleMegaTabs.map((tab) => tab.id)
		expect(ids).toEqual(['lernraum', 'teilnehmer', 'wettbewerb', 'kommunikation'])
		expect(instance.visibleMegaTabs).toHaveLength(4)
		expect(ids).not.toContain('verwaltung')
	})

	it('selectMegaTab changes activeMegaTab and resolves to leaf tab', () => {
		const instance = createInstance({
			activeMegaTab: 'lernraum',
			currentTab: 'training',
		})
		const courseStore = useCourseStore()

		instance.selectMegaTab('wettbewerb')

		expect(instance.activeMegaTab).toBe('wettbewerb')
		expect(instance.currentTab).toBe('leaderboard')
		expect(courseStore.currentTab).toBe('leaderboard')
	})

	it('megaTabForLeaf maps leaf IDs to correct mega-tab', () => {
		const instance = createInstance({
			course: {
				is_instructor: true,
				material_folder: null,
				mode_config: {},
			},
		})

		expect(instance.megaTabForLeaf('pools')).toBe('lernraum')
		expect(instance.megaTabForLeaf('curriculum')).toBe('lernraum')
		expect(instance.megaTabForLeaf('members')).toBe('teilnehmer')
		expect(instance.megaTabForLeaf('progress')).toBe('teilnehmer')
		expect(instance.megaTabForLeaf('leaderboard')).toBe('wettbewerb')
		expect(instance.megaTabForLeaf('arena')).toBe('wettbewerb')
		expect(instance.megaTabForLeaf('announcements')).toBe('kommunikation')
		expect(instance.megaTabForLeaf('feed')).toBe('kommunikation')
		expect(instance.megaTabForLeaf('mode-config')).toBe('verwaltung')
		expect(instance.megaTabForLeaf('unknown-tab')).toBe(null)
	})

	it('onLeafTabChange emits course:tab-change with leaf ID', () => {
		const instance = createInstance({
			activeMegaTab: 'lernraum',
			currentTab: 'training',
		})
		const courseStore = useCourseStore()

		instance.onLeafTabChange('arena')

		expect(instance.currentTab).toBe('arena')
		expect(courseStore.currentTab).toBe('arena')
	})

	it('treats Lernraum leaf tabs as active via the collapsed mega-tab', () => {
		const instance = createInstance({
			currentTab: 'exam',
		})

		expect(instance.lernraumLeafTabs).toEqual(['training', 'leitner', 'exam', 'tools'])
		expect(instance.isLernraumTab('exam')).toBe(true)
		expect(instance.isTabActive('lernraum')).toBe(true)
		expect(instance.isTabActive('leaderboard')).toBe(false)
	})

	// Codeberg #7: the tools tab has to follow the effective selection, admin level included.
	it('drops the tools tab when the course or the admin switched every tool off', () => {
		const courseOff = createInstance()
		courseOff.course = { ...courseOff.course, enabled_tools: [] }
		expect(courseOff.lernraumLeafTabs).not.toContain('tools')

		const adminOff = createInstance({ adminEnabledTools: [] })
		adminOff.course = { ...adminOff.course, enabled_tools: null }
		expect(adminOff.lernraumLeafTabs).not.toContain('tools')

		const adminSome = createInstance({ adminEnabledTools: ['dns'] })
		adminSome.course = { ...adminSome.course, enabled_tools: null }
		expect(adminSome.lernraumLeafTabs).toContain('tools')
	})

	// GitHub #35: the master switch beats the tool selection, for routing too.
	it('drops the tools tab while the course master switch is off', () => {
		const instance = createInstance({ adminEnabledTools: null })
		instance.course = { ...instance.course, enabled_tools: null, mode_config: { ...instance.course.mode_config, tools: false } }
		expect(instance.hasCourseTools).toBe(false)
		expect(instance.lernraumLeafTabs).not.toContain('tools')

		instance.course = { ...instance.course, mode_config: { ...instance.course.mode_config, tools: true } }
		expect(instance.lernraumLeafTabs).toContain('tools')
	})

	// Codeberg #9: without 'practice' in this whitelist the navigation drops the tab silently.
	it('routes the practice exam tab into Lernraum when the course offers one', () => {
		const instance = createInstance({ currentTab: 'practice' })
		instance.course = { ...instance.course, practice_enabled: true }

		expect(instance.lernraumLeafTabs).toContain('practice')
		expect(instance.isLernraumTab('practice')).toBe(true)
		expect(instance.megaTabForLeaf('practice')).toBe('lernraum')
	})

	it('falls back to the default Lernraum leaf when the mega-tab is selected directly', () => {
		const studentInstance = createInstance({
			currentTab: 'leaderboard',
		})
		const studentCourseStore = useCourseStore()

		studentInstance.selectTab('lernraum')

		expect(studentInstance.currentTab).toBe('training')
		expect(studentCourseStore.currentTab).toBe('training')

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
						abenteuer: false,
					course_summary: false,
				},
			},
			userRole: 'instructor',
		})
		const instructorCourseStore = useCourseStore()

		instructorInstance.selectTab('lernraum')

		expect(instructorInstance.currentTab).toBe('pools')
		expect(instructorCourseStore.currentTab).toBe('pools')
	})
})

// GitHub #35: CourseDetail keeps its own copy of the learner tab list (teilnehmerLeafTabs); it must
// agree with CourseTabTeilnehmer, otherwise the visibleTabs watcher throws the learner out of the
// completion tab and the certificate notification's deep link lands nowhere.
describe('CourseDetail — completion tab in certifying courses (GitHub #35)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		setActivePinia(createPinia())
	})

	it('lists summary for students when cert_enabled even with course_summary off', () => {
		const instance = createInstance({
			course: { is_instructor: false, cert_enabled: true, mode_config: { course_summary: false } },
		})

		expect(instance.teilnehmerLeafTabs).toEqual(['classbook', 'my-progress', 'summary'])
	})

	it('resolves the /courses/<id>/summary deep link to the summary leaf under Teilnehmer', () => {
		const instance = createInstance({
			course: { is_instructor: false, cert_enabled: true, mode_config: { course_summary: false } },
		})

		instance.syncCurrentTabFromRoute('summary')

		expect(instance.currentTab).toBe('summary')
		expect(instance.activeMegaTab).toBe('teilnehmer')
	})
})

// GitHub #35: the student view must show a course the way a learner sees it. CourseDetail got the
// real global role and read course.is_instructor directly, so an instructor in the student view
// still saw Verwaltung and every instructor sub-tab.
describe('CourseDetail — student view and course-level role (GitHub #35)', () => {
	const ownCourse = (extra = {}) => ({
		is_instructor: true,
		material_folder: null,
		enabled_tools: [],
		mode_config: {
			training: true, leitner: true, exam: true, duel: true, gameshow: true, league: true, abenteuer: false, course_summary: false,
		},
		...extra,
	})

	beforeEach(() => {
		vi.clearAllMocks()
		setActivePinia(createPinia())
	})

	it('the student view hides Verwaltung and the instructor sub-tabs of an own course', () => {
		const instance = createInstance({ course: ownCourse(), userRole: 'student', studentViewActive: true })

		expect(instance.actsAsInstructor).toBe(false)
		expect(instance.visibleMegaTabs.map((tab) => tab.id)).toEqual(['lernraum', 'teilnehmer', 'wettbewerb', 'kommunikation'])
		expect(instance.lernraumLeafTabs).toEqual(['training', 'leitner', 'exam'])
		expect(instance.teilnehmerLeafTabs).toEqual(['classbook', 'my-progress'])
		expect(instance.kommunikationLeafTabs).toEqual(['feed', 'buddies'])
		expect(instance.wettbewerbLeafTabs).toEqual(['leaderboard', 'league', 'arena'])
		instance.adminAiEnabled = true
		expect(instance.kommunikationLeafTabs).toEqual(['feed', 'buddies', 'schwarm'])
	})

	it('a global student enrolled as co-instructor keeps the instructor tabs (the membership decides)', () => {
		const instance = createInstance({ course: ownCourse(), userRole: 'student', studentViewActive: false })

		expect(instance.actsAsInstructor).toBe(true)
		expect(instance.visibleMegaTabs.map((tab) => tab.id)).toContain('verwaltung')
		expect(instance.lernraumLeafTabs).toEqual(['pools', 'curriculum', 'materials'])
		instance.adminAiEnabled = true
		expect(instance.lernraumLeafTabs).toEqual(['pools', 'curriculum', 'materials', 'knowledge'])
	})

	it('loading an own course in the student view opens the learner default tab and the learner progress', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockImplementation((url) => {
			if (url === '/apps/learning/api/courses/{courseId}') {
				return Promise.resolve({ data: { id: 11, ...ownCourse(), pools: [], members: [] } })
			}
			return Promise.resolve({ data: { pools: [] } })
		})
		const instance = createInstance({ course: null, userRole: 'student', studentViewActive: true, currentTab: 'pools' })

		await instance.fetchCourseDetail()

		expect(instance.currentTab).toBe('training')
		expect(instance.activeMegaTab).toBe('lernraum')
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/{courseId}/my-progress')
	})

	// GitHub #35: the knowledge tab follows the admin's AI switch from GET /api/settings/tools;
	// a failed read hides it rather than offering it on a server where AI may be off.
	it('reads the AI switch with the tool settings; a failed read counts as off', async () => {
		const axios = (await import('@nextcloud/axios')).default
		const instance = createInstance({ course: ownCourse(), userRole: 'instructor' })

		axios.get.mockImplementation(() => Promise.resolve({ data: { enabled_tools: null, ai_enabled: true } }))
		await instance.fetchAdminEnabledTools()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/settings/tools')
		expect(instance.adminAiEnabled).toBe(true)
		expect(instance.lernraumLeafTabs).toContain('knowledge')

		axios.get.mockImplementation(() => Promise.reject(new Error('500')))
		await instance.fetchAdminEnabledTools()
		expect(instance.adminAiEnabled).toBe(false)
		expect(instance.lernraumLeafTabs).not.toContain('knowledge')

		axios.get.mockImplementation(() => Promise.resolve({ data: { enabled_tools: null } }))
		await instance.fetchAdminEnabledTools()
		expect(instance.adminAiEnabled).toBe(false)
	})

	it('a course with Training switched off does not route learners to Training', () => {
		const instance = createInstance({
			course: ownCourse({ is_instructor: false, mode_config: { training: false, leitner: true, exam: true } }),
		})

		expect(instance.lernraumLeafTabs).not.toContain('training')
		expect(instance.defaultLernraumTab()).toBe('leitner')
	})

	it('a reload forgets the required-content flag until the remounted learning space reports it again', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockImplementation((url) => {
			if (url === '/apps/learning/api/courses/{courseId}') {
				return Promise.resolve({ data: { id: 11, ...ownCourse({ is_instructor: false }), pools: [], members: [] } })
			}
			return Promise.resolve({ data: { pools: [] } })
		})
		const instance = createInstance({ hasCourseVideos: true, currentTab: 'videos' })

		await instance.fetchCourseDetail()

		expect(instance.hasCourseVideos).toBe(false)
		expect(instance.currentTab).toBe('training')
	})

	it('routes the required-content leaf (videos) into Lernraum once the learning space reports it', () => {
		const instance = createInstance({ hasCourseVideos: true })

		expect(instance.lernraumLeafTabs[0]).toBe('videos')
		expect(instance.megaTabForLeaf('videos')).toBe('lernraum')
	})
})
