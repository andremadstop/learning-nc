/**
 * GitHub #35: the pool exam in a course gets the course's certificate rule and practice rules.
 *
 * ExamMode's computed tests cannot see a prop that the parent never passes — the card would
 * silently fall back to the 720/900 scale and hide the practice run. So this mounts the real
 * CourseTabLernraum with an ExamMode stub that declares the props and renders what it received.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: [] })), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const { slotStub, emptyStub } = vi.hoisted(() => ({
	slotStub: (name, tag = 'div') => ({ default: { name, template: `<${tag}><slot /></${tag}>` } }),
	emptyStub: (name) => ({ default: { name, template: '<div />' } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => slotStub('NcButton', 'button'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => slotStub('NcCheckboxRadioSwitch'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => slotStub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => emptyStub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcModal', () => slotStub('NcModal'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => slotStub('NcNoteCard'))
vi.mock('../../src/components/CourseKnowledgeImport.vue', () => emptyStub('CourseKnowledgeImport'))
vi.mock('../../src/components/CourseMaterials.vue', () => emptyStub('CourseMaterials'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => emptyStub('KnowledgeModeration'))
vi.mock('../../src/components/LeitnerMode.vue', () => emptyStub('LeitnerMode'))
vi.mock('../../src/components/TrainingMode.vue', () => emptyStub('TrainingMode'))
vi.mock('../../src/components/TrainingPrivacyNotice.vue', () => emptyStub('TrainingPrivacyNotice'))
vi.mock('../../src/components/ExamMode.vue', () => ({
	default: {
		name: 'ExamMode',
		props: ['poolId', 'courseId', 'practice', 'practiceConfig', 'totalQuestions', 'contentLanguage', 'courseCertification', 'poolPractice'],
		template: '<pre class="exam-mode-props">{{ JSON.stringify({ poolId, courseId, totalQuestions, courseCertification, poolPractice }) }}</pre>',
	},
}))

import CourseTabLernraum from '../../src/components/CourseTabLernraum.vue'

let app = null
let host = null

async function flush() {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

async function mountExam(course, coursePools, poolId) {
	host = document.createElement('div')
	document.body.appendChild(host)
	let vm = null
	app = createApp({
		render: () => h(CourseTabLernraum, {
			courseId: 7, course, coursePools, allPools: [], activeTab: 'exam', userRole: 'student', contentLanguage: 'de',
			ref: (r) => { vm = r },
		}),
	})
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
	vm.selectedLearningPool = coursePools.find((p) => p.pool_id === poolId)
	await flush()
	const pre = host.querySelector('.exam-mode-props')
	return pre ? JSON.parse(pre.textContent) : null
}

afterEach(() => {
	app?.unmount()
	app = null
	host?.remove()
	host = null
})

const pools = [
	{ pool_id: 3, pool_name: 'Brandschutz', question_count: 40, sort_order: 1 },
	{ pool_id: 4, pool_name: 'Erste Hilfe', question_count: 25, sort_order: 2 },
]
const baseCourse = { is_instructor: false, mode_config: { training: true, leitner: true, exam: true } }

describe('CourseTabLernraum → ExamMode props (GitHub #35)', () => {
	it('passes the certificate rule and the practice rules of the course', async () => {
		const props = await mountExam({
			...baseCourse, cert_enabled: true, cert_pass_percent: 85, cert_required_pool_ids: [3],
			practice_enabled: true, practice_questions: 15, practice_minutes: 20, practice_pass_percent: 60,
		}, pools, 3)

		expect(props).toEqual({
			poolId: 3, courseId: 7, totalQuestions: 40,
			courseCertification: { passPercent: 85, countsForCertificate: true },
			poolPractice: { questions: 15, minutes: 20, passPercent: 60 },
		})
	})

	it('a pool the certificate does not require does not count', async () => {
		const props = await mountExam({ ...baseCourse, cert_enabled: true, cert_pass_percent: 80, cert_required_pool_ids: [3] }, pools, 4)

		expect(props.courseCertification).toEqual({ passPercent: 80, countsForCertificate: false })
	})

	// Same rule as the server: a required pool no longer linked to the course is ignored, and
	// with none left every counting exam counts.
	it('required pools no longer linked to the course are ignored', async () => {
		const props = await mountExam({ ...baseCourse, cert_enabled: true, cert_pass_percent: 80, cert_required_pool_ids: [99] }, pools, 4)

		expect(props.courseCertification).toEqual({ passPercent: 80, countsForCertificate: true })
	})

	it('no certificate and no practice exams: neither rule is passed', async () => {
		const props = await mountExam({ ...baseCourse, cert_enabled: false, practice_enabled: false }, pools, 3)

		expect(props.courseCertification).toBeNull()
		expect(props.poolPractice).toBeNull()
	})
})
