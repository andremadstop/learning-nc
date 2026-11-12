/**
 * Server-side grading for the bot modes of Duel and Gameshow.
 *
 * Until the 2026-11 audit both modes graded on the client with answers[].is_correct from
 * GET /api/pools/{id}/questions — which handed every course member the answer key. Learners no
 * longer receive is_correct, so the bot modes ask POST /api/questions/{id}/check instead.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * @param {number} questionId
 * @param {number} answerId
 * @returns {Promise<{correct: boolean, correctAnswerId: number|null}>} rejects when the server refuses
 */
export async function gradeBotAnswer(questionId, answerId) {
	const r = await axios.post(
		generateUrl('/apps/learning/api/questions/{id}/check', { id: questionId }),
		{ answerId },
	)
	return {
		correct: r.data?.correct === true,
		correctAnswerId: r.data?.correct_answer_id ?? null,
	}
}
