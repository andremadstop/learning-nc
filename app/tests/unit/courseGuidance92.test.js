import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

// The real translate() of @nextcloud/l10n, not a mock: it escapes placeholder values by default,
// and a mock that never escapes cannot see "Abschluss &amp; Zertifikat" (GitHub #87 / #92 review).
await vi.hoisted(async () => {
	const l10n = await import('@nextcloud/l10n')
	globalThis.t = l10n.translate
	globalThis.n = l10n.translatePlural
})

const { api } = vi.hoisted(() => ({ api: { course: null, videos: [], passStatus: {} } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/courses/{courseId}') return Promise.resolve({ data: api.course })
			if (url.startsWith('/apps/learning/api/video-progress/course/')) return Promise.resolve({ data: { videos: api.videos } })
			if (url === '/apps/learning/api/settings/tools') return Promise.resolve({ data: { enabled_tools: null } })
			if (url === '/apps/learning/api/leitner/queue/count') return Promise.resolve({ data: { count: 0 } })
			if (url.endsWith('/pass-status')) return Promise.resolve({ data: api.passStatus })
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
// A Kommunikation sub-navigation with the selected leaf, like the real one
vi.mock('../../src/components/CourseTabKommunikation.vue', () => ({
	default: {
		name: 'CourseTabKommunikation',
		props: { activeTab: { type: String, default: '' } },
		template: '<div role="tablist"><button type="button" role="tab" :aria-selected="activeTab === \'my-questions\' ? \'true\' : \'false\'">Frage stellen</button></div>',
	},
}))
vi.mock('../../src/components/CourseSummary.vue', () => stub('CourseSummary'))
vi.mock('../../src/components/CourseRecentSessions.vue', () => stub('CourseRecentSessions'))
vi.mock('../../src/components/CourseTabVerwaltung.vue', () => recordingTab('CourseTabVerwaltung'))
vi.mock('../../src/components/ExamReadiness.vue', () => stub('ExamReadiness'))

// Children of the real CourseTabLernraum.
vi.mock('../../src/components/CourseKnowledgeImport.vue', () => stub('CourseKnowledgeImport'))
vi.mock('../../src/components/CourseMaterials.vue', () => stub('CourseMaterials'))
vi.mock('../../src/components/AuthFlowSimulator.vue', () => stub('AuthFlowSimulator'))
vi.mock('../../src/components/DnsResolver.vue', () => stub('DnsResolver'))
// The exam result's buttons, reduced to the event they send
vi.mock('../../src/components/ExamMode.vue', () => ({
	default: {
		name: 'ExamMode',
		props: { guide: { type: Object, default: null } },
		emits: ['guide', 'back'],
		template: '<div class="exam-stub" :data-guide="JSON.stringify(guide)"><button type="button" class="exam-ask" @click="$emit(\'guide\', \'my-questions\')">ask</button><button type="button" class="exam-cert" @click="$emit(\'guide\', \'summary\')">cert</button></div>',
	},
}))
vi.mock('../../src/components/FirewallBuilder.vue', () => stub('FirewallBuilder'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => stub('KnowledgeModeration'))
vi.mock('../../src/components/LeitnerMode.vue', () => stub('LeitnerMode'))
vi.mock('../../src/components/NatTable.vue', () => stub('NatTable'))
vi.mock('../../src/components/PortScanner.vue', () => stub('PortScanner'))
vi.mock('../../src/components/RoutingTable.vue', () => stub('RoutingTable'))
vi.mock('../../src/components/SubnetCalculator.vue', () => stub('SubnetCalculator'))
vi.mock('../../src/components/TrainingMode.vue', () => stub('TrainingMode'))
vi.mock('../../src/components/TrainingPrivacyNotice.vue', () => stub('TrainingPrivacyNotice'))

/**
 * GitHub #92: the "next step" buttons, through the real CourseDetail and the real Lernraum: the
 * button under the learning materials, the exam result (ExamMode reduced to its event) and the
 * exam tab. Each opens its tab, moves the focus to it and names it in a status line.
 */
import CourseDetail from '../../src/components/CourseDetail.vue'
import { materialsNextStep } from '../../src/utils/courseView.js'

function course(overrides = {}) {
	return {
		id: 5,
		title: 'Arbeitsschutz 2026',
		status: 'active',
		is_instructor: false,
		cert_enabled: true,
		// the materials tab is there from the start, so the course opens on it
		has_material_folder: true,
		mode_config: { training: false, leitner: false, exam: true },
		pools: [{ pool_id: 3, pool_name: 'Brandschutz', question_count: 10 }],
		members: [],
		...overrides,
	}
}

const DONE_DOC = { id: 11, title: 'Unterweisung', source_type: 'document', video_ref: '/u.pdf', document_id: 31, completed: true }

async function flush() {
	for (let i = 0; i < 8; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('materialsNextStep (GitHub #92)', () => {
	it('the exam in a certifying course or one whose exam waits for the required content', () => {
		expect(materialsNextStep({ cert_enabled: true }, { lernraumTabs: ['materials', 'training', 'exam'] })).toBe('exam')
		expect(materialsNextStep({ video_gate_enabled: true }, { lernraumTabs: ['materials', 'training', 'leitner', 'exam'] })).toBe('exam')
	})

	it('otherwise the next tab the course offers, Leitner on or off', () => {
		expect(materialsNextStep({}, { lernraumTabs: ['materials', 'training', 'leitner', 'exam'] })).toBe('training')
		expect(materialsNextStep({}, { lernraumTabs: ['materials', 'leitner', 'exam'] })).toBe('leitner')
	})

	it('the completion area when nothing follows in the Lernraum, else nothing', () => {
		expect(materialsNextStep({ cert_enabled: true }, { lernraumTabs: ['materials'], abschlussTabs: ['summary'] })).toBe('summary')
		expect(materialsNextStep({}, { lernraumTabs: ['materials'], abschlussTabs: [] })).toBeNull()
	})
})

describe('CourseDetail guides the learner on (GitHub #92)', () => {
	let host, app, detail

	async function mountDetail(props = {}) {
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

	const announcement = () => host.querySelector('[data-test="guide-announcement"]').textContent.trim()
	const nextStep = () => host.querySelector('[data-test="materials-next-step-button"]')

	beforeEach(() => {
		api.course = course()
		api.videos = [DONE_DOC]
		api.passStatus = {}
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
		detail = null
	})

	it('all required content done: "Zur Prüfung" opens the exam tab, focuses it and says so', async () => {
		await mountDetail()
		expect(detail.currentTab).toBe('materials')
		expect(nextStep().textContent.trim()).toBe('Zur Prüfung')

		nextStep().click()
		await flush()

		expect(detail.currentTab).toBe('exam')
		expect(document.activeElement.textContent.trim()).toBe('Exam')
		expect(document.activeElement.getAttribute('aria-selected')).toBe('true')
		expect(announcement()).toBe('Geöffnet: Exam')
	})

	it('required content still open: no next-step button', async () => {
		api.videos = [{ ...DONE_DOC, completed: false }]
		await mountDetail()
		expect(nextStep()).toBeNull()
	})

	it('an IT course without certificate leads to the next tab it offers', async () => {
		api.course = course({ cert_enabled: false, mode_config: { training: true, leitner: true, exam: true } })
		await mountDetail()
		expect(nextStep().textContent.trim()).toBe('Weiter: Training')
	})

	it('the exam result hands the course tabs to ExamMode and its buttons open the communication and completion areas', async () => {
		api.course = course({ mode_config: { training: false, leitner: false, exam: true }, pools: [{ pool_id: 3, pool_name: 'Brandschutz', question_count: 10 }] })
		await mountDetail()
		nextStep().click()
		await flush()
		host.querySelector('.pool-item').click()
		await flush()
		const exam = host.querySelector('.exam-stub')
		expect(JSON.parse(exam.dataset.guide)).toEqual({ materials: true, certificate: true, askInstructor: true })

		host.querySelector('.exam-ask').click()
		await flush()
		expect(detail.activeMegaTab).toBe('kommunikation')
		expect(document.activeElement.textContent.trim()).toBe('Frage stellen')
		expect(announcement()).toBe('Geöffnet: Frage stellen')

		await detail.onGuide('summary')
		await flush()
		expect(detail.activeMegaTab).toBe('abschluss')
		expect(document.activeElement.textContent.trim()).toBe('Abschluss & Zertifikat')
		expect(announcement()).toBe('Geöffnet: Abschluss & Zertifikat')
	})

	it('the exam tab names a pool without attempts and opens a question to the instructors', async () => {
		api.passStatus = { applicable: true, passed: false, threshold: 80, requirement: 'any_exam', pools: [], certificate_issued: false, certificate: null, attempt_policy: { limit_per_day: 5, cooldown_minutes: 10, total_limit: 3, used_by_pool: { 3: 3 } } }
		await mountDetail()
		nextStep().click()
		await flush()

		const note = host.querySelector('[data-test="exam-tab-exhausted"]')
		expect(note.textContent).toContain('Keine Prüfungsversuche mehr für: Brandschutz.')
		host.querySelector('[data-test="exam-tab-ask"]').click()
		await flush()
		expect(detail.currentTab).toBe('my-questions')
	})

	it('the exam tab names a pool as written, "&" and apostrophe included', async () => {
		api.course = course({ pools: [{ pool_id: 3, pool_name: "Netzwerk & O'Reilly", question_count: 10 }] })
		api.passStatus = { applicable: true, passed: false, threshold: 80, requirement: 'any_exam', pools: [], certificate_issued: false, certificate: null, attempt_policy: { limit_per_day: 5, cooldown_minutes: 10, total_limit: 3, used_by_pool: { 3: 3 } } }
		await mountDetail()
		nextStep().click()
		await flush()

		const note = host.querySelector('[data-test="exam-tab-exhausted"]')
		expect(note.textContent).toContain("Keine Prüfungsversuche mehr für: Netzwerk & O'Reilly.")
		expect(note.textContent).not.toContain('&amp;')
	})

	it('the exam tab leads to the certificate once there is one', async () => {
		api.passStatus = { applicable: true, passed: true, threshold: 80, requirement: 'any_exam', pools: [], certificate_issued: true, certificate: { verification_id: 'v', expired: false, revoked: false }, attempt_policy: { limit_per_day: 5, cooldown_minutes: 10, total_limit: null, used_by_pool: {} } }
		await mountDetail()
		nextStep().click()
		await flush()

		host.querySelector('[data-test="exam-tab-certificate-button"]').click()
		await flush()
		expect(detail.activeMegaTab).toBe('abschluss')
	})

	it('in the renewal window the exam tab offers no certificate button: the renewal exam is next', async () => {
		api.passStatus = { applicable: true, passed: false, threshold: 80, requirement: 'any_exam', pools: [], certificate_issued: true, renewal_open_since: 1735689600, certificate: { verification_id: 'v', expired: false, revoked: false }, attempt_policy: { limit_per_day: 5, cooldown_minutes: 10, total_limit: null, used_by_pool: {} } }
		await mountDetail()
		nextStep().click()
		await flush()

		expect(host.querySelector('[data-test="exam-tab-certificate"]')).toBeNull()
	})

	it('instructors get no next-step buttons', async () => {
		api.course = course({ is_instructor: true })
		await mountDetail({ userRole: 'instructor' })
		expect(nextStep()).toBeNull()
	})
})
