import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, s, p, count) => (count === 1 ? s : p)
})

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		patch: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

vi.mock('../../src/utils/virtuprof-i18n.js', () => ({
	translateVirtuProf: vi.fn((lang, key) => key),
	VIRTUPROF_LANGUAGES: ['de', 'en'],
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

import axios from '@nextcloud/axios'
import CourseTabVerwaltung from '../../src/components/CourseTabVerwaltung.vue'
import VirtuProf from '../../src/components/VirtuProf.vue'

/**
 * Codeberg #4 was one instance of a class: a frontend that posts a body the controller does not
 * read. Nextcloud's dispatcher does not reject unknown keys and fills the missing parameter with
 * its default, so the request succeeds and the app quietly does the wrong thing. PHPStan cannot
 * see across the HTTP boundary and service-level tests start after it, so nothing catches these.
 *
 * The audit that followed the #4 fix turned up three more. These tests pin the request bodies at
 * the boundary itself — the only place the defect is visible.
 */
describe('request payload contracts', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	/**
	 * HIGH — this one destroyed data. CourseController::setSchedule() takes `entries`; the
	 * component sent `schedule`, so `$entries` stayed at its [] default and ScheduleService read
	 * that as "replace the schedule with nothing": deleteByCourse() ran, no row was inserted, and
	 * the response was a 200 that the UI reported as "saved".
	 */
	it('CourseTabVerwaltung.saveSchedule posts `entries`, the key the controller reads', async () => {
		const ctx = {
			courseId: 7,
			savingSchedule: false,
			scheduleSaved: false,
			scheduleItems: [
				{ chapter_ref: 'ch-1', chapter_title: 'Intro', start_date: '2026-09-01', target_date: '2026-09-08' },
			],
			$emit: vi.fn(),
		}

		await CourseTabVerwaltung.methods.saveSchedule.call(ctx)

		expect(axios.put).toHaveBeenCalledTimes(1)
		const [url, body] = axios.put.mock.calls[0]
		expect(url).toContain('/schedule')
		expect(Object.keys(body)).toEqual(['entries'])
		expect(body.entries).toHaveLength(1)
		expect(body.entries[0].chapter_ref).toBe('ch-1')
		expect(body.entries[0].sort_order).toBe(1)
	})

	/**
	 * MED — SupportTicketController::create() reads questionId/poolId/courseId out of `context`.
	 * Sent at the top level they were dropped, and the ticket's structured columns were stored as
	 * NULL, leaving the reported question identifiable only through the subject string. The same
	 * component already builds the correct { context: {...} } shape for its other ticket call.
	 */
	it('VirtuProf.handleReportError nests the ids under `context`', async () => {
		const ctx = {
			currentContext: { questionContext: { questionId: 42, poolId: 7, courseId: 3 } },
			chatMessages: [],
			vt: (s) => s,
		}

		await VirtuProf.methods.handleReportError.call(ctx)

		expect(axios.post).toHaveBeenCalledTimes(1)
		const body = axios.post.mock.calls[0][1]
		expect(body.context).toEqual({ questionId: 42, poolId: 7, courseId: 3 })
		expect(body.questionId).toBeUndefined()
		expect(body.poolId).toBeUndefined()
		expect(body.courseId).toBeUndefined()
	})

	/**
	 * LOW — VirtuProfController::chat() has no `questionId` parameter; it expects
	 * `lastWrongQuestionId`. The value was silently dropped, so RagContextService never loaded
	 * the question the user had just got wrong. The richer `questionContext` usually masked it.
	 */
	it('VirtuProf chat posts lastWrongQuestionId, the parameter the controller declares', async () => {
		const ctx = {
			chatLoading: false,
			aiConsentVersion: 'v1',
			consentData: { version: 'v1' },
			visible: true,
			isMinimized: false,
			helpView: 'chat',
			chatMessages: [],
			currentContext: { poolId: 7, courseId: 3, questionId: 42 },
			currentAnimation: 'idle',
			vt: (s) => s,
			scrollChatToBottom: vi.fn(),
			applyReaction: vi.fn(),
			isHintRequest: VirtuProf.methods.isHintRequest,
			hintLevel: 0,
			$nextTick: vi.fn((fn) => fn && fn()),
			$refs: {},
		}

		await VirtuProf.methods.handleChatSend.call(ctx, 'why was that wrong?')

		const chatCall = axios.post.mock.calls.find((c) => String(c[0]).includes('virtu-prof/chat'))
		expect(chatCall, 'a chat request must have been sent').toBeTruthy()
		expect(chatCall[1].lastWrongQuestionId).toBe(42)
		expect(chatCall[1].questionId).toBeUndefined()
	})
})
