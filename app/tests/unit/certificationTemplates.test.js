/**
 * GitHub #35 (review): the certification changes are partly template-only — a v-if wrapper, a
 * note bound to a flag, a modal button. The computed/method tests cannot see a v-if in the wrong
 * place, so these tests mount the real components (Nc* stubbed) and look at the rendered DOM.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'teacher' })) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn() }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { slotStub, emptyStub } = vi.hoisted(() => ({
	slotStub: (name, tag = 'div') => ({ default: { name, inheritAttrs: true, template: `<${tag}><slot /></${tag}>` } }),
	emptyStub: (name) => ({ default: { name, template: '<div />' } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => slotStub('NcButton', 'button'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => slotStub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcModal', () => slotStub('NcModal'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => emptyStub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => emptyStub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => slotStub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcTextField', () => emptyStub('NcTextField'))
vi.mock('../../src/components/Certificate.vue', () => emptyStub('Certificate'))
vi.mock('../../src/components/AiConsentHint.vue', () => emptyStub('AiConsentHint'))
vi.mock('../../src/components/BadgeUnlock.vue', () => emptyStub('BadgeUnlock'))
vi.mock('../../src/components/PbqRenderer.vue', () => emptyStub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => emptyStub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/QuestionScenario.vue', () => emptyStub('QuestionScenario'))
vi.mock('../../src/components/StudentDetail.vue', () => emptyStub('StudentDetail'))
vi.mock('../../src/components/BuddyMatching.vue', () => emptyStub('BuddyMatching'))
vi.mock('../../src/components/Klassenbuch.vue', () => emptyStub('Klassenbuch'))

import axios from '@nextcloud/axios'
import CourseSummary from '../../src/components/CourseSummary.vue'
import CourseTabTeilnehmer from '../../src/components/CourseTabTeilnehmer.vue'
import ExamMode from '../../src/components/ExamMode.vue'

let app = null
let host = null

async function flush() {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

function mount(component, props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	let vm = null
	app = createApp({ render: () => h(component, { ...props, ref: (r) => { vm = r } }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	return () => vm
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})
afterEach(() => {
	app?.unmount()
	app = null
	host?.remove()
	host = null
	vi.restoreAllMocks()
})

const certCourse = { cert_enabled: true, mode_config: { course_summary: false }, cert_validity_months: 12 }

describe('CourseSummary template — certifying course with unreleased statistics', () => {
	it('renders the certification card and no statistics or summary actions', async () => {
		axios.get.mockResolvedValue({ data: {
			applicable: true, passed: true, score: 90, threshold: 80, passedAt: 1700000000,
			requirement: 'any_exam', pools: [], certificate_issued: true, issuance_error: null,
			certificate: { verification_id: 'vid-1', issued_at: 1700000000, expires_at: null, expired: false, revoked: false },
		} })

		mount(CourseSummary, { courseId: 7, courseName: 'Arbeitsschutz', course: certCourse })
		await flush()

		expect(host.querySelector('.zeugnisstatus-card')).not.toBeNull()
		expect(host.querySelector('.zeugnis-cert-action')).not.toBeNull()
		expect(host.querySelector('.stats-grid')).toBeNull()
		expect(host.querySelector('.summary-actions')).toBeNull()
		// Only the pass status is read — no summary, AI reflection or calendar token.
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toContain('/pass-status')
	})

	it('a failing pass-status read shows an error with a retry, not a blank tab', async () => {
		axios.get.mockRejectedValueOnce(new Error('500'))

		mount(CourseSummary, { courseId: 7, courseName: 'Arbeitsschutz', course: certCourse })
		await flush()

		expect(host.querySelector('.zeugnisstatus-card')).toBeNull()
		const error = host.querySelector('.pass-status-error')
		expect(error).not.toBeNull()
		expect(error.textContent).toContain('Der Stand deines Zertifikats konnte nicht geladen werden.')

		axios.get.mockResolvedValueOnce({ data: { applicable: true, passed: false, score: null, threshold: 80, requirement: 'any_exam', pools: [] } })
		error.querySelector('button').click()
		await flush()

		expect(host.querySelector('.pass-status-error')).toBeNull()
		expect(host.querySelector('.zeugnisstatus-card')).not.toBeNull()
	})
})

describe('CourseTabTeilnehmer template — instructor completion view', () => {
	it('renders the signing warning and the passed-without-certificate list', async () => {
		axios.get.mockResolvedValue({ data: {
			rows: [],
			signing_available: false,
			passed_without_certificate: [{ display_name: 'Jane Doe' }, { display_name: 'Max Muster' }],
		} })

		mount(CourseTabTeilnehmer, {
			courseId: 7,
			course: { ...certCourse, is_instructor: true },
			userRole: 'instructor',
			activeTab: 'summary',
		})
		await flush()

		expect(host.querySelector('.cert-signing-warning')).not.toBeNull()
		const list = host.querySelector('.cert-passed-without-certificate')
		expect(list).not.toBeNull()
		expect([...list.querySelectorAll('li')].map((li) => li.textContent.trim())).toEqual(['Jane Doe', 'Max Muster'])
	})

	it('renders neither note when signing works and nobody is missing a certificate', async () => {
		axios.get.mockResolvedValue({ data: { rows: [], signing_available: true, passed_without_certificate: [] } })

		mount(CourseTabTeilnehmer, {
			courseId: 7,
			course: { ...certCourse, is_instructor: true },
			userRole: 'instructor',
			activeTab: 'summary',
		})
		await flush()

		expect(host.querySelector('.cert-signing-warning')).toBeNull()
		expect(host.querySelector('.cert-passed-without-certificate')).toBeNull()
	})
})

describe('ExamMode template — result screen of a counting exam', () => {
	it('shows the certification note with an "Open certificate" button', async () => {
		const getVm = mount(ExamMode, { poolId: 3, courseId: 7, totalQuestions: 20 })
		await flush()
		const vm = getVm()
		vm.resultsData = {
			total_questions: 20, correct_answers: 15, score_percentage: 75,
			certification: { applicable: true, passed: true, threshold: 70, exam_score: 75, exam_passed: true, certificate_issued: true, certificate: { verification_id: 'vid-1', revoked: false, expired: false } },
		}
		vm.screen = 'results'
		await flush()

		const note = host.querySelector('.certification-result')
		expect(note).not.toBeNull()
		expect(note.textContent).toContain('Certificate issued')
		expect(note.querySelector('.certification-result__action').textContent).toContain('Open certificate')
		expect(host.querySelector('.result-banner__threshold').textContent).toContain('Pass mark: 70%')
	})
})
