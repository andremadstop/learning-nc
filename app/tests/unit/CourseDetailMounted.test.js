/**
 * GitHub #35: CourseDetail rendered for real, with the real CourseTabLernraum inside.
 *
 * The other CourseDetail tests call computeds on a plain object and the tab tests set
 * actsAsInstructor themselves, so nothing checked that CourseDetail actually hands the gate to its
 * tabs, or that the Lernraum's "required content exists" report reaches CourseDetail. A dropped
 * binding would cost every instructor the instructor tabs with all gates green.
 *
 * - Teilnehmer, Wettbewerb, Kommunikation and Verwaltung are stubs that only record the
 *   actsAsInstructor prop they receive.
 * - CourseTabLernraum is the real component (its heavy children stubbed), so its emits on mount,
 *   after the required-content fetch and on unmount are the real ones.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { course: null, videos: [] } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/courses/{courseId}') return Promise.resolve({ data: api.course })
			if (url.startsWith('/apps/learning/api/video-progress/course/')) return Promise.resolve({ data: { videos: api.videos } })
			if (url === '/apps/learning/api/settings/tools') return Promise.resolve({ data: { enabled_tools: null } })
			if (url === '/apps/learning/api/leitner/queue/count') return Promise.resolve({ data: { count: 0 } })
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'teacher' })) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { stub, recordingTab } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div><slot /></div>' } }),
	// Records the gate it is handed; declares the prop exactly like the real tab does.
	recordingTab: (name) => ({
		default: {
			name,
			props: { actsAsInstructor: { type: Boolean, default: false } },
			template: `<div class="tab-stub" data-tab="${name}" :data-acts="String(actsAsInstructor)" />`,
		},
	}),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => stub('NcCheckboxRadioSwitch'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))

vi.mock('../../src/components/CourseTabTeilnehmer.vue', () => recordingTab('CourseTabTeilnehmer'))
vi.mock('../../src/components/CourseTabWettbewerb.vue', () => recordingTab('CourseTabWettbewerb'))
vi.mock('../../src/components/CourseTabKommunikation.vue', () => recordingTab('CourseTabKommunikation'))
vi.mock('../../src/components/CourseTabVerwaltung.vue', () => recordingTab('CourseTabVerwaltung'))
vi.mock('../../src/components/ExamReadiness.vue', () => stub('ExamReadiness'))

// Children of the real CourseTabLernraum.
vi.mock('../../src/components/CourseKnowledgeImport.vue', () => stub('CourseKnowledgeImport'))
vi.mock('../../src/components/CourseMaterials.vue', () => stub('CourseMaterials'))
vi.mock('../../src/components/AuthFlowSimulator.vue', () => stub('AuthFlowSimulator'))
vi.mock('../../src/components/DnsResolver.vue', () => stub('DnsResolver'))
vi.mock('../../src/components/ExamMode.vue', () => stub('ExamMode'))
vi.mock('../../src/components/FirewallBuilder.vue', () => stub('FirewallBuilder'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => stub('KnowledgeModeration'))
vi.mock('../../src/components/LeitnerMode.vue', () => stub('LeitnerMode'))
vi.mock('../../src/components/NatTable.vue', () => stub('NatTable'))
vi.mock('../../src/components/PortScanner.vue', () => stub('PortScanner'))
vi.mock('../../src/components/RoutingTable.vue', () => stub('RoutingTable'))
vi.mock('../../src/components/SubnetCalculator.vue', () => stub('SubnetCalculator'))
vi.mock('../../src/components/TrainingMode.vue', () => stub('TrainingMode'))
vi.mock('../../src/components/TrainingPrivacyNotice.vue', () => stub('TrainingPrivacyNotice'))
vi.mock('../../src/components/VideoConsentOverlay.vue', () => stub('VideoConsentOverlay'))
vi.mock('../../src/components/VideoPlayer.vue', () => stub('VideoPlayer'))
vi.mock('../../src/components/WiresharkLite.vue', () => stub('WiresharkLite'))

import CourseDetail from '../../src/components/CourseDetail.vue'

function course(overrides = {}) {
	return {
		id: 5,
		title: 'Arbeitsschutz 2026',
		status: 'active',
		is_instructor: false,
		mode_config: { training: true, leitner: true, exam: true },
		pools: [],
		members: [],
		...overrides,
	}
}

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('CourseDetail rendered (GitHub #35)', () => {
	let host, app, detail

	async function mountDetail(props) {
		host = document.createElement('div')
		document.body.appendChild(host)
		const detailRef = ref(null)
		app = createApp({ render: () => h(CourseDetail, { ref: detailRef, courseId: 5, userRole: 'student', ...props }) })
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = globalThis.n
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()
		detail = detailRef.value
	}

	function megaButtons() {
		return Array.from(host.querySelectorAll('.mega-tab-button'))
	}

	async function clickMega(label) {
		const button = megaButtons().find((b) => b.textContent.trim() === label)
		expect(button, `mega tab ${label}`).toBeTruthy()
		button.click()
		await flush()
	}

	function subTabLabels() {
		return Array.from(host.querySelectorAll('.lernraum-pill')).map((b) => b.textContent.trim())
	}

	function stubGate(name) {
		return host.querySelector(`.tab-stub[data-tab="${name}"]`)?.getAttribute('data-acts')
	}

	beforeEach(() => {
		api.course = course()
		api.videos = []
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
		detail = null
	})

	it('hands the instructor gate to every tab of an own course', async () => {
		api.course = course({ is_instructor: true })
		await mountDetail({ userRole: 'instructor', studentViewActive: false })

		expect(subTabLabels()).toContain('Pools')
		await clickMega('Teilnehmer')
		expect(stubGate('CourseTabTeilnehmer')).toBe('true')
		await clickMega('Wettbewerb')
		expect(stubGate('CourseTabWettbewerb')).toBe('true')
		await clickMega('Kommunikation')
		expect(stubGate('CourseTabKommunikation')).toBe('true')
		await clickMega('Verwaltung')
		expect(stubGate('CourseTabVerwaltung')).toBe('true')
	})

	it('the student view turns the gate off in every tab and hides Verwaltung', async () => {
		api.course = course({ is_instructor: true })
		await mountDetail({ userRole: 'student', studentViewActive: true })

		expect(subTabLabels()).not.toContain('Pools')
		expect(subTabLabels()).toContain('Training')
		expect(megaButtons().map((b) => b.textContent.trim())).not.toContain('Verwaltung')
		await clickMega('Teilnehmer')
		expect(stubGate('CourseTabTeilnehmer')).toBe('false')
		await clickMega('Wettbewerb')
		expect(stubGate('CourseTabWettbewerb')).toBe('false')
		await clickMega('Kommunikation')
		expect(stubGate('CourseTabKommunikation')).toBe('false')
	})

	it('learns from the real Lernraum that required content exists', async () => {
		api.videos = [{ id: 11, title: 'Unterweisung', source_type: 'document', video_ref: '/u.pdf', completed: false }]
		await mountDetail({})

		expect(subTabLabels()[0]).toBe('Pflichtinhalte')
		expect(detail.lernraumLeafTabs[0]).toBe('videos')
		expect(detail.megaTabForLeaf('videos')).toBe('lernraum')
	})

	// Review: hasCourseVideos used to outlive the Lernraum, so after Teilnehmer -> Lernraum
	// CourseDetail named 'videos' while the remounted Lernraum showed Training, and a click on
	// Pflichtinhalte changed nothing.
	it('Teilnehmer -> Lernraum round trip: shown sub-tab and currentTab agree, Pflichtinhalte opens', async () => {
		api.videos = [{ id: 11, title: 'Unterweisung', source_type: 'document', video_ref: '/u.pdf', completed: false }]
		await mountDetail({})

		await clickMega('Teilnehmer')
		await clickMega('Lernraum')

		const active = host.querySelector('.lernraum-pill.active')?.textContent.trim()
		const labelFor = { training: 'Training', videos: 'Pflichtinhalte', leitner: 'Leitner', exam: 'Exam' }
		expect(active).toBe(labelFor[detail.currentTab])

		const pflicht = Array.from(host.querySelectorAll('.lernraum-pill')).find((b) => b.textContent.trim() === 'Pflichtinhalte')
		expect(pflicht).toBeTruthy()
		pflicht.click()
		await flush()
		expect(detail.currentTab).toBe('videos')
		expect(host.querySelector('.lernraum-pill.active')?.textContent.trim()).toBe('Pflichtinhalte')
	})
	// GitHub #35: mode_config.tools switches off the Werkzeuge tab and the tool hints in the
	// learning space, whatever enabled_tools says.
	it('the tools master switch removes the Werkzeuge tab and the tool hints for learners', async () => {
		api.course = course({ enabled_tools: null, mode_config: { training: true, leitner: true, exam: true, tools: true } })
		await mountDetail({ userRole: 'student' })
		expect(subTabLabels()).toContain('Werkzeuge')
		expect(host.querySelector('.course-tools-callout')).toBeTruthy()
		app.unmount()
		host.remove()

		api.course = course({ enabled_tools: null, mode_config: { training: true, leitner: true, exam: true, tools: false } })
		await mountDetail({ userRole: 'student' })
		expect(subTabLabels()).not.toContain('Werkzeuge')
		expect(host.querySelector('.course-tools-callout')).toBeNull()
	})
})
