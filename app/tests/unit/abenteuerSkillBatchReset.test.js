/**
 * Recheck of the 2026-11 high fixes (h2): the server now refuses a skill batch with a question the
 * current scene did not offer, or with a question answered twice. skillAnswersBatch was only
 * cleared after a successful submit, so after an aborted check (or a network error) the next check
 * sent the old answers along, got 400, and every further skill check failed until a page reload.
 */
import { describe, it, expect, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, str) => str,
	t: (_app, str) => str,
	getLanguage: () => 'de',
	isRTL: () => false,
}))
globalThis.t = (_app, str) => str

import AbenteuerMode from '../../src/components/AbenteuerMode.vue'

describe('AbenteuerMode skill check batch', () => {
	it('starting a skill check drops answers left over from an aborted one', () => {
		const vm = {
			...AbenteuerMode.data.call({}),
			makeStubQuestion: AbenteuerMode.methods.makeStubQuestion,
		}
		vm.skillAnswersBatch = [{ question_id: 7, answer_id: 70 }]
		const questions = [{ id: 11, text: 'Q', answers: [] }]
		AbenteuerMode.methods.fetchSkillQuestion.call(vm, { skill_check: { question_count: 1, questions } })
		expect(vm.skillAnswersBatch).toEqual([])
		expect(vm.currentSkillQuestion).toBe(questions[0])
	})
})
