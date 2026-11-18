/**
 * GitHub #92: every result of a counting exam in a certifying course ends with one obvious next
 * step. Before, the bright button after a pass was "Retake Exam", and nothing led to the
 * certificate, back to the materials or to the instructors.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p).split('%n').join(String(c))
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn() }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub, slotStub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
	slotStub: (name, cls) => ({ default: { name, template: `<div class="${cls}"><slot /></div>` } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: { variant: { type: String, default: 'secondary' }, disabled: Boolean },
		emits: ['click'],
		template: '<button class="nc-button" :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => slotStub('NcNoteCard', 'nc-note'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('../../src/components/Certificate.vue', () => stub('Certificate'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/QuestionScenario.vue', () => stub('QuestionScenario'))

import ExamMode from '../../src/components/ExamMode.vue'

const GUIDE = { materials: true, certificate: true, askInstructor: true }
const policy = (extra = {}) => ({ limit_per_day: 5, cooldown_minutes: 10, scope: 'pool_context', total_limit: null, used_by_pool: {}, next_start_at: null, ...extra })
const validCert = { verification_id: 'vid-1', issued_at: 1735689600, expires_at: null, expired: false, revoked: false }

describe('ExamMode result guidance (GitHub #92)', () => {
	let host, app, emitted

	async function showResult(certification, { guide = GUIDE, correct = 12, practice = false } = {}) {
		host = document.createElement('div')
		document.body.appendChild(host)
		emitted = []
		const exam = ref(null)
		app = createApp({
			render: () => h(ExamMode, {
				ref: exam,
				poolId: 3,
				courseId: 7,
				totalQuestions: 20,
				guide,
				onGuide: (leaf) => emitted.push(['guide', leaf]),
				onBack: () => emitted.push(['back']),
			}),
		})
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = globalThis.n
		app.config.warnHandler = () => {}
		app.mount(host)
		await nextTick()
		exam.value.resultsData = {
			total_questions: 20,
			correct_answers: correct,
			score_percentage: correct * 5,
			certification,
			...(practice ? { exam_kind: 'practice', pass_percent: 70, passed: false } : {}),
		}
		exam.value.detailedResults = []
		exam.value.screen = 'results'
		await nextTick()
		await nextTick()
		return exam.value
	}

	const button = (test) => host.querySelector(`[data-test="${test}"]`)
	// every bright button on the result screen, the certificate note included
	const primaries = () => Array.from(host.querySelectorAll('.nc-button[data-variant="primary"]')).map((b) => b.textContent.trim())

	afterEach(() => {
		app?.unmount()
		host?.remove()
		vi.useRealTimers()
	})

	it('after a pass with a certificate, "Zu meinem Zertifikat" is the one primary button and retaking is quiet', async () => {
		await showResult({ applicable: true, passed: true, threshold: 70, exam_passed: true, requirement: 'any_exam', certificate_issued: true, certificate: validCert, pool_id: 3, attempt_policy: policy() }, { correct: 18 })

		expect(primaries()).toEqual(['Zu meinem Zertifikat'])
		expect(button('guide-retake').getAttribute('data-variant')).toBe('tertiary')
		// the certificate preview in the note is no second bright button
		expect(host.querySelector('.certification-result .nc-button').getAttribute('data-variant')).toBe('secondary')
		button('guide-certificate').click()
		expect(emitted).toEqual([['guide', 'summary']])
	})

	it('a pool passed while other required pools are open leads back to the pool list, not to retaking', async () => {
		const pools = [{ pool_id: 3, title: 'A', passed: true }, { pool_id: 4, title: 'B', passed: false }]
		await showResult({ applicable: true, passed: false, threshold: 70, exam_passed: true, requirement: 'required_pools', pools, counts_for_requirement: true, certificate_issued: false, certificate: null, pool_id: 3, attempt_policy: policy() }, { correct: 18 })

		expect(primaries()).toEqual(['Zur Prüfungsübersicht'])
		button('guide-pools').click()
		expect(emitted).toEqual([['back']])
	})

	it('not passed with attempts left: attempts, countdown, back to the materials; retake waits for the countdown', async () => {
		vi.useFakeTimers()
		const now = Math.floor(Date.now() / 1000)
		const exam = await showResult({ applicable: true, passed: false, threshold: 70, exam_passed: false, requirement: 'any_exam', certificate_issued: false, certificate: null, pool_id: 3, attempt_policy: policy({ total_limit: 3, used_by_pool: { 3: 1 }, next_start_at: now + 90 }) })

		expect(button('result-attempts').textContent.trim()).toBe('Bisher: 1 Versuch nicht bestanden, 2 Versuche übrig')
		expect(button('result-wait').textContent).toContain('Nächster Versuch möglich in 1:3')
		expect(primaries()).toEqual(['Zurück zum Lernmaterial'])
		expect(button('guide-retake').disabled).toBe(true)
		button('guide-materials').click()
		expect(emitted).toEqual([['guide', 'materials']])

		vi.advanceTimersByTime(91_000)
		await nextTick()
		expect(button('result-wait')).toBeNull()
		expect(button('guide-retake').disabled).toBe(false)
		expect(host.querySelector('.result-guidance [role="status"]').textContent).toBe('Du kannst die Prüfung jetzt wiederholen.')
		expect(exam.guideTimer).toBeNull()
	})

	it('the countdown stops when the exam view goes away', async () => {
		vi.useFakeTimers()
		const now = Math.floor(Date.now() / 1000)
		const exam = await showResult({ applicable: true, passed: false, threshold: 70, exam_passed: false, requirement: 'any_exam', certificate_issued: false, certificate: null, pool_id: 3, attempt_policy: policy({ next_start_at: now + 600 }) })
		expect(exam.guideTimer).not.toBeNull()
		const clear = vi.spyOn(globalThis, 'clearInterval')
		app.unmount()
		app = null
		expect(clear).toHaveBeenCalled()
	})

	it('a failed renewal exam leads to the countdown and the materials, not to the old certificate', async () => {
		await showResult({ applicable: true, passed: false, threshold: 70, exam_passed: false, requirement: 'any_exam', certificate_issued: true, certificate: validCert, renewal_open_since: 1735689600, pool_id: 3, attempt_policy: policy({ total_limit: 3, used_by_pool: { 3: 1 } }) })

		expect(primaries()).toEqual(['Zurück zum Lernmaterial'])
		expect(button('guide-certificate')).toBeNull()
		expect(button('result-attempts')).not.toBeNull()
	})

	it('a renewal that issued the new certificate (window closed again) leads to the certificate', async () => {
		await showResult({ applicable: true, passed: true, threshold: 70, exam_passed: true, requirement: 'any_exam', certificate_issued: true, certificate: { ...validCert, verification_id: 'vid-2' }, renewal_open_since: null, pool_id: 3, attempt_policy: policy() }, { correct: 18 })
		expect(primaries()).toEqual(['Zu meinem Zertifikat'])
	})

	it('a renewal pool passed while another required pool is open leads to the overview, not to the old certificate', async () => {
		const pools = [{ pool_id: 3, title: 'A', passed: true }, { pool_id: 4, title: 'B', passed: false }]
		await showResult({ applicable: true, passed: false, threshold: 70, exam_passed: true, requirement: 'required_pools', pools, counts_for_requirement: true, certificate_issued: true, certificate: validCert, renewal_open_since: 1735689600, pool_id: 3, attempt_policy: policy() }, { correct: 18 })

		expect(primaries()).toEqual(['Zur Prüfungsübersicht'])
		expect(button('guide-certificate')).toBeNull()
		// the old certificate stays reachable, but only as the quiet button in the note
		expect(host.querySelector('.certification-result .nc-button').getAttribute('data-variant')).toBe('secondary')
	})

	it('a renewal pass whose new certificate could not be issued asks the instructors, no retake', async () => {
		await showResult({ applicable: true, passed: true, threshold: 70, exam_passed: true, requirement: 'any_exam', certificate_issued: true, certificate: validCert, issuance_error: 'issuance_failed', renewal_open_since: 1735689600, pool_id: 3, attempt_policy: policy() }, { correct: 18 })

		expect(primaries()).toEqual(['Frage an die Kursleitung'])
		expect(button('guide-certificate')).toBeNull()
		expect(button('guide-retake')).toBeNull()
	})

	it('a pass whose certificate cannot be signed asks the instructors instead of offering "Retake Exam"', async () => {
		await showResult({ applicable: true, passed: true, threshold: 70, exam_passed: true, requirement: 'any_exam', certificate_issued: false, certificate: null, issuance_error: 'signing_unavailable', pool_id: 3, attempt_policy: policy() }, { correct: 18 })

		expect(primaries()).toEqual(['Frage an die Kursleitung'])
		expect(button('guide-retake')).toBeNull()
		expect(primaries()).not.toContain('Retake Exam')
	})

	it('a pass on a pool the certificate does not require leads to the overview', async () => {
		const pools = [{ pool_id: 4, title: 'B', passed: false }]
		await showResult({ applicable: true, passed: false, threshold: 70, exam_passed: true, requirement: 'required_pools', pools, counts_for_requirement: false, certificate_issued: false, certificate: null, pool_id: 3, attempt_policy: policy() }, { correct: 18 })

		expect(primaries()).toEqual(['Zur Prüfungsübersicht'])
		expect(button('guide-retake').getAttribute('data-variant')).toBe('tertiary')
	})

	it('a pass whose certificate check failed leads to the completion area, not to retaking', async () => {
		await showResult({ course_id: 7, error: 'evaluation_failed', threshold: 70, exam_score: 90, exam_passed: true }, { correct: 18 })

		expect(primaries()).toEqual(['Zu meinem Zertifikat'])
		expect(primaries()).not.toContain('Retake Exam')
		expect(button('guide-retake').getAttribute('data-variant')).toBe('tertiary')
	})

	it('a failed attempt whose certificate check failed keeps the old screen', async () => {
		await showResult({ course_id: 7, error: 'evaluation_failed', threshold: 70, exam_score: 40, exam_passed: false }, { correct: 8 })
		expect(button('result-guidance')).toBeNull()
		expect(primaries()).toEqual(['Retake Exam'])
	})

	it('no attempt left: a question to the instructors is the way on, and there is no retake', async () => {
		await showResult({ applicable: true, passed: false, threshold: 70, exam_passed: false, requirement: 'any_exam', certificate_issued: false, certificate: null, pool_id: 3, attempt_policy: policy({ total_limit: 3, used_by_pool: { 3: 3 } }) })

		expect(primaries()).toEqual(['Frage an die Kursleitung'])
		expect(button('guide-retake')).toBeNull()
		button('guide-ask').click()
		expect(emitted).toEqual([['guide', 'my-questions']])
	})

	it('without a materials tab the retake stays the primary button after a failed attempt', async () => {
		await showResult({ applicable: true, passed: false, threshold: 70, exam_passed: false, requirement: 'any_exam', certificate_issued: false, certificate: null, pool_id: 3, attempt_policy: policy() }, { guide: { ...GUIDE, materials: false } })

		expect(primaries()).toEqual(['Prüfung wiederholen'])
		expect(button('result-attempts')).toBeNull()
	})

	it('without a guide (pool window) and for a practice run the result screen is unchanged', async () => {
		await showResult({ applicable: true, passed: true, threshold: 70, exam_passed: true, requirement: 'any_exam', certificate_issued: true, certificate: validCert, pool_id: 3, attempt_policy: policy() }, { guide: null, correct: 18 })
		expect(button('result-guidance')).toBeNull()
		expect(primaries()).toContain('Retake Exam')
		app.unmount()
		host.remove()

		await showResult(null, { practice: true })
		expect(button('result-guidance')).toBeNull()
		expect(primaries()).toContain('Retake Exam')
	})

	it('the instructor in the student view gets no certificate button', async () => {
		await showResult({ applicable: true, passed: true, threshold: 70, exam_passed: true, requirement: 'any_exam', certificate_issued: false, certificate: null, issuance_error: 'instructor_of_course', pool_id: 3, attempt_policy: policy() }, { correct: 18 })
		expect(button('guide-certificate')).toBeNull()
	})
})
