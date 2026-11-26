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
		await showResult({ applicable: true, passed: true, threshold: 70, exam_passed: true, requirement: 'any_exam', certificate_issued: true, certificate_issued_now: true, certificate: validCert, pool_id: 3, attempt_policy: policy() }, { correct: 18 })

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

	// GitHub #92 (review): the screen-reader line used the browser locale ("02:30 PM Uhr") and gave
	// no day, though the 24-hour limit moves the next start to tomorrow.
	it('the screen-reader line names the next start in the Nextcloud locale, with the day when it is not today', async () => {
		const { setLocale } = await import('@nextcloud/l10n')
		const before = globalThis._nc_l10n_locale
		setLocale('de_DE')
		try {
			vi.useFakeTimers()
			vi.setSystemTime(new Date(2026, 10, 18, 10, 0, 0))
			const now = Math.floor(Date.now() / 1000)
			const failed = (nextStartAt) => ({ applicable: true, passed: false, threshold: 70, exam_passed: false, requirement: 'any_exam', certificate_issued: false, certificate: null, pool_id: 3, attempt_policy: policy({ total_limit: 3, used_by_pool: { 3: 1 }, next_start_at: nextStartAt }) })

			await showResult(failed(now + 90 * 60))
			expect(button('result-next-start').textContent.trim()).toBe('Nächster Versuch möglich ab 11:30 Uhr')
			app.unmount()
			host.remove()

			await showResult(failed(Math.floor(new Date(2026, 10, 19, 9, 15, 0).getTime() / 1000)))
			expect(button('result-next-start').textContent.trim()).toBe('Nächster Versuch möglich am Donnerstag, 19. November ab 09:15 Uhr')
		} finally {
			setLocale(before ?? 'en')
		}
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

/**
 * GitHub #92 (gap from the 5.16.0 comment): a learner who comes back to the exam during the wait
 * opened the setup screen with an active "Start Exam" and only learned of the wait from the
 * refused start. The setup screen now counts down the wait the Exam tab knows from the pass
 * status and enables the start when it is over, without asking the server again.
 */
describe('ExamMode setup screen during the wait (GitHub #92)', () => {
	let host, app

	async function showSetup(props = {}) {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(ExamMode, { poolId: 3, courseId: 7, totalQuestions: 20, guide: GUIDE, ...props }) })
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = globalThis.n
		app.config.warnHandler = () => {}
		app.mount(host)
		await nextTick()
		await nextTick()
	}

	const startButton = () => Array.from(host.querySelectorAll('.preset-card .nc-button')).find((b) => b.textContent.trim() === 'Start Exam')
	const practiceButton = () => Array.from(host.querySelectorAll('.preset-card .nc-button')).find((b) => b.textContent.trim() === 'Start practice run')
	const wait = () => host.querySelector('[data-test="setup-wait"]')

	afterEach(() => {
		app?.unmount()
		host?.remove()
		vi.useRealTimers()
	})

	it('counts down in the exam card, keeps the start disabled, and enables it when the wait is over', async () => {
		vi.useFakeTimers()
		const now = Math.floor(Date.now() / 1000)
		await showSetup({ nextStartAt: now + 90, serverClockOffset: 0 })

		expect(startButton().disabled).toBe(true)
		expect(wait().querySelector('[aria-hidden="true"]').textContent).toBe('Nächster Versuch möglich in 1:30')
		expect(wait().querySelector('.hidden-visually').textContent).toMatch(/^Nächster Versuch möglich ab \S+ Uhr$/)
		expect(wait().closest('.preset-card').querySelector('.preset-title').textContent).toBe('Exam')

		vi.advanceTimersByTime(91_000)
		await nextTick()
		expect(startButton().disabled).toBe(false)
		expect(wait()).toBeNull()
	})

	it('uses the server clock: a browser an hour behind still waits 90 seconds', async () => {
		vi.useFakeTimers()
		const serverNow = Math.floor(Date.now() / 1000) + 3600
		await showSetup({ nextStartAt: serverNow + 90, serverClockOffset: 3600 })

		expect(wait().textContent).toContain('Nächster Versuch möglich in 1:30')
	})

	it('the practice run is not held back: it has its own limits', async () => {
		const now = Math.floor(Date.now() / 1000)
		await showSetup({ nextStartAt: now + 90, poolPractice: { questions: 10, minutes: 0, passPercent: 75 } })

		expect(startButton().disabled).toBe(true)
		expect(practiceButton().disabled).toBe(false)
	})

	it('without a known wait (pool window, older server, no pass status) the setup screen is as before', async () => {
		await showSetup()

		expect(startButton().disabled).toBe(false)
		expect(wait()).toBeNull()
	})
})

// Review of the #92 gap fix: "Retake" on the result page opens the setup screen before the Exam tab
// has read the pass status again; the wait the result named must hold there too, also after a pass.
describe('ExamMode retake keeps the wait the result named (GitHub #92)', () => {
	let host, app

	afterEach(() => {
		app?.unmount()
		host?.remove()
		vi.useRealTimers()
	})

	it('after a pass, the quiet retake leads to a setup screen that waits', async () => {
		vi.useFakeTimers()
		host = document.createElement('div')
		document.body.appendChild(host)
		const exam = ref(null)
		app = createApp({ render: () => h(ExamMode, { ref: exam, poolId: 3, courseId: 7, totalQuestions: 20, guide: GUIDE }) })
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = globalThis.n
		app.config.warnHandler = () => {}
		app.mount(host)
		await nextTick()
		const now = Math.floor(Date.now() / 1000)
		exam.value.resultsData = {
			total_questions: 20,
			correct_answers: 18,
			score_percentage: 90,
			certification: { applicable: true, passed: true, threshold: 70, exam_passed: true, requirement: 'any_exam', certificate_issued: true, certificate_issued_now: true, certificate: validCert, pool_id: 3, attempt_policy: policy({ next_start_at: now + 300 }) },
		}
		exam.value.detailedResults = []
		exam.value.screen = 'results'
		await nextTick()

		exam.value.retakeExam()
		await nextTick()
		await nextTick()

		const start = Array.from(host.querySelectorAll('.preset-card .nc-button')).find((b) => b.textContent.trim() === 'Start Exam')
		expect(start.disabled).toBe(true)
		expect(host.querySelector('[data-test="setup-wait"]').textContent).toContain('Nächster Versuch möglich in 5:00')
	})
})
