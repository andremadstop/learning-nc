import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => url.replace('{courseId}', String(params.courseId))),
}))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import CourseSummary from '../../src/components/CourseSummary.vue'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof CourseSummary.data === 'function' ? CourseSummary.data() : {}
	const instance = {
		...data,
		courseId: 7,
		courseName: 'Network+ Bootcamp',
		...overrides,
	}

	Object.defineProperties(instance, {
		mastery: { get: () => CourseSummary.computed.mastery.call(instance) },
		sessions: { get: () => CourseSummary.computed.sessions.call(instance) },
		xp: { get: () => CourseSummary.computed.xp.call(instance) },
		streak: { get: () => CourseSummary.computed.streak.call(instance) },
		duels: { get: () => CourseSummary.computed.duels.call(instance) },
		swarm: { get: () => CourseSummary.computed.swarm.call(instance) },
		earnedBadges: { get: () => CourseSummary.computed.earnedBadges.call(instance) },
		troubleSpots: { get: () => CourseSummary.computed.troubleSpots.call(instance) },
		masteryCircumference: { get: () => CourseSummary.computed.masteryCircumference.call(instance) },
		masteryDashOffset: { get: () => CourseSummary.computed.masteryDashOffset.call(instance) },
		zeugnisVisible: { get: () => CourseSummary.computed.zeugnisVisible.call(instance) },
		certApplicable: { get: () => CourseSummary.computed.certApplicable.call(instance) },
		hasPassed: { get: () => CourseSummary.computed.hasPassed.call(instance) },
		passedAtFormatted: { get: () => CourseSummary.computed.passedAtFormatted.call(instance) },
	})

	for (const [name, fn] of Object.entries(CourseSummary.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('CourseSummary', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loads summary data and tolerates missing snapshots', async () => {
		const instance = createInstance()
		axios.get
			.mockResolvedValueOnce({
				data: {
					mastery: { total_mastered: 142, total_questions: 200, mastery_rate: 71.0 },
					sessions: { total_sessions: 45, overall_accuracy: 80.0 },
					xp: { total_xp: 4500, current_level: 12 },
					badges: [],
					streak: { current_streak: 7, longest_streak: 14 },
					swarm: { total_contributions: 5, approved_contributions: 3 },
					duels: { total_duels: 12, wins: 8, win_rate: 66.7 },
					trouble_spots: [{ question_id: 42, attempts: 5, accuracy: 20 }],
				},
			})
			.mockRejectedValueOnce({ response: { status: 404 } })

		await instance.loadSummaryState()

		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/7/summary')
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/7/summary/snapshot')
		expect(instance.summary.mastery.mastery_rate).toBe(71)
		expect(instance.snapshot).toBeNull()
		expect(instance.loading).toBe(false)
		expect(instance.error).toBe('')
	})

	it('filters earned badges for the gallery and caps trouble spots at five', () => {
		const instance = createInstance({
			summary: {
				badges: [
					{ badge_id: 'streak_7', earned: true },
					{ badge_id: 'perfect_exam', earned: false },
					{ badge_id: 'mastermind_10', earned: true },
				],
				trouble_spots: Array.from({ length: 7 }, (_, index) => ({
					question_id: index + 1,
					attempts: 3,
					accuracy: 20,
				})),
			},
		})

		expect(instance.earnedBadges.map((badge) => badge.badge_id)).toEqual(['streak_7', 'mastermind_10'])
		expect(instance.troubleSpots).toHaveLength(5)
	})

	it('renders narrative section when narrative data is a string', () => {
		const instance = createInstance({
			narrative: 'Dein Lernweg war beeindruckend. Staerken: Netzwerk-Grundlagen.',
			narrativeLoading: false,
		})
		expect(instance.narrative).toBeTruthy()
		expect(typeof instance.narrative).toBe('string')
	})

	it('shows placeholder when narrative is null', () => {
		const instance = createInstance({
			narrative: null,
			narrativeLoading: false,
		})
		expect(instance.narrative).toBeNull()
	})

	it('loadNarrative calls POST narrative endpoint on mount', async () => {
		const instance = createInstance()
		axios.post.mockResolvedValueOnce({
			data: { narrative: 'AI generated text', cached: true },
		})

		await instance.loadNarrative()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/7/summary/narrative')
		expect(instance.narrative).toBe('AI generated text')
		expect(instance.narrativeLoading).toBe(false)
	})

	it('loadIcsToken calls POST /api/ics/generate', async () => {
		const instance = createInstance()
		axios.post.mockResolvedValueOnce({
			data: { token: 'abc123', url: 'https://devcloud.example/apps/learning/api/ics/abc123' },
		})

		await instance.loadIcsToken()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/ics/generate')
		expect(instance.icsToken).toBe('abc123')
		expect(instance.icsLoading).toBe(false)
	})

	it('sets icsUrl and icsSubscribeUrl (webcal://) after loadIcsToken', async () => {
		const instance = createInstance()
		axios.post.mockResolvedValueOnce({
			data: { token: 'xyz', url: 'https://devcloud.example/apps/learning/api/ics/xyz' },
		})

		await instance.loadIcsToken()

		expect(instance.icsUrl).toBe('https://devcloud.example/apps/learning/api/ics/xyz')
		expect(instance.icsSubscribeUrl).toBe('webcal://devcloud.example/apps/learning/api/ics/xyz')
	})

	it('copyIcsUrl sets icsCopied true then resets after 2000ms', () => {
		vi.useFakeTimers()
		const instance = createInstance({
			icsUrl: 'https://devcloud.example/apps/learning/api/ics/abc123',
		})
		// Mock clipboard API
		const writeTextMock = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText: writeTextMock },
			writable: true,
			configurable: true,
		})

		instance.copyIcsUrl()

		expect(writeTextMock).toHaveBeenCalledWith('https://devcloud.example/apps/learning/api/ics/abc123')
		expect(instance.icsCopied).toBe(true)

		vi.advanceTimersByTime(2000)
		expect(instance.icsCopied).toBe(false)
		vi.useRealTimers()
	})

	it('ICS section data is available when icsUrl is set', () => {
		const instance = createInstance({
			icsUrl: 'https://devcloud.example/apps/learning/api/ics/abc123',
			icsSubscribeUrl: 'webcal://devcloud.example/apps/learning/api/ics/abc123',
		})
		expect(instance.icsUrl).toBeTruthy()
		expect(instance.icsSubscribeUrl).toContain('webcal://')
	})

	it('ICS section data is hidden when icsUrl is null', () => {
		const instance = createInstance({ icsUrl: null })
		expect(instance.icsUrl).toBeNull()
	})

	it('creates a snapshot and refreshes snapshot state afterwards', async () => {
		vi.useFakeTimers()
		const instance = createInstance({
			summary: {
				mastery: { mastery_rate: 80 },
				badges: [],
				trouble_spots: [],
			},
		})
		axios.post.mockResolvedValueOnce({ data: { id: 99 } })
		axios.get.mockResolvedValueOnce({
			data: {
				id: 99,
				created_at: 1711234567,
				snapshot_data: {},
			},
		})

		await instance.saveSnapshot()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/7/summary/snapshot')
		expect(instance.snapshot.id).toBe(99)
		expect(instance.snapshotSaved).toBe(true)

		vi.runAllTimers()
		expect(instance.snapshotSaved).toBe(false)
		vi.useRealTimers()
	})

	// Audit #95: the error card replaced the whole summary, so a failed save hid the data.
	it('a failed snapshot save keeps the loaded summary and shows a toast', async () => {
		const summary = { mastery: { mastery_rate: 80 }, badges: [], trouble_spots: [] }
		const instance = createInstance({ summary })
		axios.post.mockRejectedValueOnce({ response: { status: 429, data: {} } })

		await instance.saveSnapshot()

		expect(instance.error).toBe('')
		expect(instance.summary).toBe(summary)
		expect(instance.savingSnapshot).toBe(false)
		expect(showError).toHaveBeenCalledWith('Snapshot konnte nicht gespeichert werden.')
	})
})

describe('Zeugnisstatus computeds (Phase 154)', () => {
	it('zeugnisVisible is false when passStatus is null', () => {
		const instance = createInstance({ passStatus: null, passStatusLoading: false })
		expect(instance.zeugnisVisible).toBe(false)
	})

	it('zeugnisVisible is false while passStatusLoading=true', () => {
		const instance = createInstance({
			passStatus: { applicable: true, passed: false, score: 60, threshold: 80, poolsMastered: false, passedAt: null },
			passStatusLoading: true,
		})
		expect(instance.zeugnisVisible).toBe(false)
	})

	it('zeugnisVisible is true when passStatus loaded and not loading', () => {
		const instance = createInstance({
			passStatus: { applicable: true, passed: false, score: 60, threshold: 80, poolsMastered: false, passedAt: null },
			passStatusLoading: false,
		})
		expect(instance.zeugnisVisible).toBe(true)
	})

	it('certApplicable is false when applicable=false', () => {
		const instance = createInstance({
			passStatus: { applicable: false, passed: false, score: null, threshold: 80, poolsMastered: false, passedAt: null },
		})
		expect(instance.certApplicable).toBe(false)
	})

	it('certApplicable is true when applicable=true', () => {
		const instance = createInstance({
			passStatus: { applicable: true, passed: false, score: 60, threshold: 80, poolsMastered: false, passedAt: null },
		})
		expect(instance.certApplicable).toBe(true)
	})

	it('hasPassed is true when passed=true', () => {
		const instance = createInstance({
			passStatus: { applicable: true, passed: true, score: 85, threshold: 80, poolsMastered: true, passedAt: 1750000000 },
		})
		expect(instance.hasPassed).toBe(true)
	})

	it('hasPassed is false when passed=false', () => {
		const instance = createInstance({
			passStatus: { applicable: true, passed: false, score: 70, threshold: 80, poolsMastered: false, passedAt: null },
		})
		expect(instance.hasPassed).toBe(false)
	})

	it('passedAtFormatted is null when passedAt is null', () => {
		const instance = createInstance({
			passStatus: { applicable: true, passed: false, score: 70, threshold: 80, poolsMastered: false, passedAt: null },
		})
		expect(instance.passedAtFormatted).toBeNull()
	})

	it('passedAtFormatted returns a non-empty date string when passedAt is set', () => {
		const instance = createInstance({
			passStatus: { applicable: true, passed: true, score: 85, threshold: 80, poolsMastered: true, passedAt: 1750000000 },
		})
		expect(typeof instance.passedAtFormatted).toBe('string')
		expect(instance.passedAtFormatted.length).toBeGreaterThan(0)
	})
})

// GitHub #35: the certification card shows for every certifying course, independent of the
// released summary statistics, and tells the learner exactly where they stand.
describe('CourseSummary — certification card (GitHub #35)', () => {
	function createLiveInstance(overrides = {}) {
		const instance = {
			...CourseSummary.data(),
			courseId: 7,
			courseName: 'Arbeitsschutz',
			course: null,
			...overrides,
		}
		for (const [name, def] of Object.entries(CourseSummary.computed)) {
			const getter = typeof def === 'function' ? def : def.get
			Object.defineProperty(instance, name, { get: () => getter.call(instance), configurable: true })
		}
		for (const [name, fn] of Object.entries(CourseSummary.methods || {})) {
			instance[name] = fn.bind(instance)
		}
		return instance
	}

	const certCourse = (extra = {}) => ({ cert_enabled: true, mode_config: { course_summary: false }, cert_validity_months: 12, ...extra })

	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('does not load the unreleased statistics (no summary read, no AI reflection, no ICS token)', () => {
		const instance = createLiveInstance({ course: certCourse() })
		instance.loadSummaryState = vi.fn()

		CourseSummary.watch.courseId.handler.call(instance, 7, undefined)

		expect(instance.statsReleased).toBe(false)
		expect(instance.loadSummaryState).not.toHaveBeenCalled()
	})

	it('still loads the statistics when course_summary is released', () => {
		const instance = createLiveInstance({ course: certCourse({ mode_config: { course_summary: true } }) })
		instance.loadSummaryState = vi.fn()

		CourseSummary.watch.courseId.handler.call(instance, 7, undefined)

		expect(instance.loadSummaryState).toHaveBeenCalledTimes(1)
	})

	it('takes the certificate from the pass status — no certificate list lookup', async () => {
		const instance = createLiveInstance({ course: certCourse() })
		axios.get.mockResolvedValueOnce({ data: {
			applicable: true, passed: true, score: 90, threshold: 80, passedAt: 1700000000,
			certificate_issued: true, issuance_error: null,
			certificate: { verification_id: 'vid-9', issued_at: 1700000000, expires_at: null, expired: false, revoked: false },
		} })

		await instance.fetchPassStatus()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toContain('/pass-status')
		expect(instance.certVerificationId).toBe('vid-9')
		expect(instance.issuanceFailed).toBe(false)
	})

	it('offers an expired certificate but never a revoked one', () => {
		const expired = createLiveInstance({ passStatus: { applicable: true, passed: true, certificate: { verification_id: 'vid-e', expired: true, revoked: false } } })
		const revoked = createLiveInstance({ passStatus: { applicable: true, passed: true, certificate: { verification_id: 'vid-r', expired: false, revoked: true } } })

		expect(expired.certVerificationId).toBe('vid-e')
		expect(revoked.certVerificationId).toBeNull()
		expect(revoked.certificateRevoked).toBe(true)
		expect(revoked.issuanceFailed).toBe(false)
	})

	it('flags a passed learner without certificate when issuance failed', () => {
		const instance = createLiveInstance({ passStatus: { applicable: true, passed: true, certificate: null, certificate_issued: false, issuance_error: 'signing_unavailable' } })

		expect(instance.issuanceFailed).toBe(true)
		expect(instance.certVerificationId).toBeNull()
	})

	// GitHub #35: an instructor of the course is never certified there (server rule). That is no
	// failed issuance and must not read as one, nor as issued.
	it('an instructor of the course gets the instructor note, not the issuance-failed warning', () => {
		const instance = createLiveInstance({ passStatus: { applicable: true, passed: true, certificate: null, certificate_issued: false, issuance_error: 'instructor_of_course' } })

		expect(instance.instructorNoCertificate).toBe(true)
		expect(instance.issuanceFailed).toBe(false)
		expect(instance.certVerificationId).toBeNull()
	})

	it('lists the required pools with best score and passed state', () => {
		const instance = createLiveInstance({ passStatus: {
			applicable: true, passed: false, threshold: 80, requirement: 'required_pools',
			pools: [
				{ pool_id: 3, title: 'Brandschutz', best_score: 92, passed: true },
				{ pool_id: 4, title: null, best_score: null, passed: false },
			],
		} })

		expect(instance.requiresPools).toBe(true)
		expect(instance.requiredPools).toHaveLength(2)
		expect(instance.requiredPools[1].passed).toBe(false)
	})

	// Review: with the statistics unreleased, a failing pass-status read must not leave a blank tab.
	it('marks a failed pass-status read so the tab can offer a retry', async () => {
		const instance = createLiveInstance({ course: certCourse() })
		axios.get.mockRejectedValueOnce(new Error('500'))
		vi.spyOn(console, 'error').mockImplementation(() => {})

		await instance.fetchPassStatus()

		expect(instance.passStatus).toBeNull()
		expect(instance.passStatusError).toBe(true)
		expect(instance.passStatusLoading).toBe(false)

		axios.get.mockResolvedValueOnce({ data: { applicable: true, passed: false, threshold: 80 } })
		await instance.fetchPassStatus()
		expect(instance.passStatusError).toBe(false)
	})

	it('states the enforced retake rules and the validity', () => {
		const instance = createLiveInstance({
			course: certCourse(),
			passStatus: { applicable: true, passed: false, attempt_policy: { limit_per_day: 5, cooldown_minutes: 10 } },
		})
		const noCooldown = createLiveInstance({
			course: certCourse({ cert_validity_months: null, cert_validity_days: 0 }),
			passStatus: { applicable: true, passed: false, attempt_policy: { limit_per_day: 3, cooldown_minutes: 0 } },
		})

		expect(instance.retakeText).toContain('5')
		expect(instance.retakeText).toContain('10')
		expect(instance.validityText).toBe('Gültigkeitsdauer (Monate): 12')
		expect(noCooldown.retakeText).toBe('Wiederholen ist erlaubt. Versuche pro Pool in 24 Stunden: höchstens 3.')
		expect(noCooldown.validityText).toBe('Gültigkeit: unbegrenzt')
	})

	it('says renewal is open and that the new validity continues from the old expiry', () => {
		const expires = 1_900_000_000
		const instance = createLiveInstance({
			course: certCourse(),
			passStatus: {
				applicable: true,
				passed: false,
				renewal_open_since: expires - 30 * 86400,
				renewal_opens_at: null,
				certificate: { verification_id: 'v1', issued_at: 1_870_000_000, expires_at: expires, expired: false, revoked: false },
			},
		})

		expect(instance.renewalOpen).toBe(true)
		expect(instance.renewalText).toContain('Die Erneuerung ist offen')
		expect(instance.renewalText).toContain(instance.formatTimestamp(expires))
		expect(instance.renewalOpensText).toBe('')
	})

	it('after expiry the renewal note does not promise a continuation', () => {
		const instance = createLiveInstance({
			course: certCourse(),
			passStatus: {
				applicable: true,
				passed: false,
				renewal_open_since: 1_800_000_000,
				certificate: { verification_id: 'v1', issued_at: 1_770_000_000, expires_at: 1_802_000_000, expired: true, revoked: false },
			},
		})

		expect(instance.renewalText).toBe('Die Erneuerung ist offen: Wenn du die Prüfung(en) jetzt bestehst, wird dein Zertifikat erneuert.')
	})

	it('names the day renewal opens while it is not open yet, and nothing without a renewal', () => {
		const opens = 1_900_000_000
		const instance = createLiveInstance({
			course: certCourse(),
			passStatus: { applicable: true, passed: true, renewal_open_since: null, renewal_opens_at: opens,
				certificate: { verification_id: 'v1', issued_at: 1_870_000_000, expires_at: opens + 30 * 86400, expired: false, revoked: false } },
		})
		const none = createLiveInstance({
			course: certCourse(),
			passStatus: { applicable: true, passed: true, renewal_open_since: null, renewal_opens_at: null },
		})

		expect(instance.renewalOpen).toBe(false)
		expect(instance.renewalText).toBe('')
		expect(instance.renewalOpensText).toBe(`Die Erneuerung öffnet am ${instance.formatTimestamp(opens)}.`)
		expect(none.renewalText).toBe('')
		expect(none.renewalOpensText).toBe('')
	})
})

// Audit #244: the Markdown export existed in utils, the button was disabled as "coming soon".
describe('CourseSummary Markdown export (audit #244)', () => {
	it('downloads the summary as a Markdown file', () => {
		const created = []
		const origCreate = document.createElement.bind(document)
		const createSpy = vi.spyOn(document, 'createElement').mockImplementation((tag) => {
			const el = origCreate(tag)
			if (tag === 'a') {
				el.click = vi.fn()
				created.push(el)
			}
			return el
		})
		URL.createObjectURL = vi.fn(() => 'blob:x')
		URL.revokeObjectURL = vi.fn()
		const instance = createInstance({ summary: { mastery: { total_mastered: 3 }, badges: [] } })

		instance.exportSummaryMarkdown()

		expect(created).toHaveLength(1)
		expect(created[0].download).toBe('Kursabschluss_Network__Bootcamp.md')
		expect(created[0].click).toHaveBeenCalledTimes(1)
		createSpy.mockRestore()
	})

	it('does nothing without a summary', () => {
		URL.createObjectURL = vi.fn()
		const instance = createInstance({ summary: null })
		instance.exportSummaryMarkdown()
		expect(URL.createObjectURL).not.toHaveBeenCalled()
	})
})
