/**
 * ExamMode result screen after a counting exam in a certifying course (GitHub #35).
 *
 * The completion response carries a `certification` block (CertificationTrigger on the server).
 * The result screen judges the attempt against the course's pass mark from that block — not
 * against the 720/900 preset scale — and says what it means for the certificate.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn() }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div />' } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('../../src/components/Certificate.vue', () => stub('Certificate'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))

globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

import ExamMode from '../../src/components/ExamMode.vue'

function createInstance(resultsData) {
	const instance = { poolId: 3, courseId: 7, practice: false, practiceConfig: null, totalQuestions: 0, contentLanguage: '', $emit: vi.fn(), $refs: {}, $nextTick: (fn) => fn && fn() }
	Object.assign(instance, ExamMode.data.call(instance))
	for (const [name, def] of Object.entries(ExamMode.computed)) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(instance, name, { get: () => getter.call(instance), configurable: true })
	}
	for (const [name, fn] of Object.entries(ExamMode.methods)) {
		instance[name] = fn.bind(instance)
	}
	instance.resultsData = resultsData
	return instance
}

const policy = { limit_per_day: 5, cooldown_minutes: 10, scope: 'pool' }
function result(certification, extra = {}) {
	return { total_questions: 20, correct_answers: 15, score_percentage: 75, ...extra, certification }
}

describe('ExamMode — certification result (GitHub #35)', () => {
	it('certificate issued: says so and offers to open it', () => {
		const vm = createInstance(result({ applicable: true, passed: true, threshold: 70, exam_passed: true, certificate_issued: true, certificate: { verification_id: 'vid-1', revoked: false, expired: false } }))

		expect(vm.certificationMessage).toEqual({ type: 'success', text: 'Certificate issued', verificationId: 'vid-1' })
	})

	it('judges the attempt against the course pass mark, not 720/900', () => {
		// 75 % → 675/900 would read "Not passed" on the preset scale; the course pass mark is 70 %.
		const vm = createInstance(result({ applicable: true, passed: true, threshold: 70, exam_passed: true, certificate_issued: true, certificate: { verification_id: 'v' } }))

		expect(vm.passedExam).toBe(true)
		expect(vm.usesPercentScore).toBe(true)
	})

	it('pool passed but other required pools missing: names them', () => {
		const vm = createInstance(result({
			applicable: true, passed: false, threshold: 70, exam_passed: true, requirement: 'required_pools', attempt_policy: policy,
			pools: [
				{ pool_id: 3, title: 'Brandschutz', best_score: 75, passed: true },
				{ pool_id: 4, title: 'Erste Hilfe', best_score: null, passed: false },
				{ pool_id: 5, title: null, best_score: 40, passed: false },
			],
		}))

		expect(vm.certificationMessage.text).toBe('Pool passed — still required: Erste Hilfe, Pool #5')
		expect(vm.certificationMessage.type).toBe('info')
	})

	it('failed attempt: states the pass mark and the real retake rules', () => {
		const vm = createInstance(result({ applicable: true, passed: false, threshold: 80, exam_passed: false, requirement: 'any_exam', attempt_policy: policy }, { score_percentage: 60 }))

		expect(vm.passedExam).toBe(false)
		expect(vm.certificationMessage.text).toBe('Not passed yet. The pass mark is 80%.')
		expect(vm.certificationMessage.retake).toBe('You can retake the exam. Attempts per pool in 24 hours: at most 5. Minimum gap between two attempts (minutes): 10.')
	})

	it('passed but issuance failed: asks to contact the administrator', () => {
		const vm = createInstance(result({ applicable: true, passed: true, threshold: 70, exam_passed: true, certificate_issued: false, certificate: null, issuance_error: 'signing_unavailable' }))

		expect(vm.certificationMessage.type).toBe('warning')
		expect(vm.certificationMessage.text).toBe('You passed, but the certificate could not be issued yet — please contact your administrator.')
		expect(vm.certificationMessage.verificationId).toBeUndefined()
	})

	// GitHub #35: the instructor's exam in the student view is a preview; the server issues nothing.
	it('passed as an instructor of the course: says no certificate is issued, not that issuance failed', () => {
		const vm = createInstance(result({ applicable: true, passed: true, threshold: 70, exam_passed: true, certificate_issued: false, certificate: null, issuance_error: 'instructor_of_course' }))

		expect(vm.certificationMessage).toEqual({ type: 'info', text: 'Passed. You lead this course, so no certificate is issued to you here.' })
	})

	it('evaluation failed on the server: result kept, certificate status deferred to the completion tab', () => {
		const vm = createInstance(result({ course_id: 7, error: 'evaluation_failed' }))

		expect(vm.certificationMessage.type).toBe('info')
		expect(vm.certificationApplies).toBe(false)
		expect(vm.usesPercentScore).toBe(false)
	})

	// Review: a known course pass mark still decides the verdict when the certificate check failed.
	it('evaluation failed but the course pass mark is known: verdict uses it, not 720/900', () => {
		// 75 % against a 70 % course mark; on the preset scale this would read 675/900 = not passed.
		const vm = createInstance(result({ course_id: 7, error: 'evaluation_failed', threshold: 70, exam_score: 75, exam_passed: true }))

		expect(vm.passedExam).toBe(true)
		expect(vm.usesPercentScore).toBe(true)
		expect(vm.coursePassMarkApplies).toBe(true)
		expect(vm.certificationMessage.type).toBe('info')
	})

	// Review: an exam on a course pool the certificate does not require is no progress.
	it('passing exam on a pool that is not required: says so instead of "pool passed"', () => {
		const vm = createInstance(result({
			applicable: true, passed: false, threshold: 70, exam_passed: true, requirement: 'required_pools',
			counts_for_requirement: false, attempt_policy: policy,
			pools: [
				{ pool_id: 1, title: 'Brandschutz', best_score: null, passed: false },
				{ pool_id: 2, title: 'Erste Hilfe', best_score: 90, passed: true },
			],
		}))

		expect(vm.certificationMessage.text).toBe('This pool is not required for the certificate. Still required: Brandschutz')
		expect(vm.certificationMessage.text).not.toContain('Pool passed')
	})

	it('no certification block (training course, exam outside a course): legacy preset verdict, no message', () => {
		const vm = createInstance({ total_questions: 20, correct_answers: 16, score_percentage: 80 })

		expect(vm.certificationMessage).toBeNull()
		expect(vm.passedExam).toBe(true) // 16/20 → 720/900
		expect(vm.usesPercentScore).toBe(false)
	})
})
