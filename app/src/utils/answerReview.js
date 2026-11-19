/**
 * The review of the options after answering (Training, Leitner, Smart Queue).
 *
 * The server strips `is_correct` from every answer before a question reaches a learner, so the
 * review cannot read it from the options. The answer response names the right options instead
 * (`correct_answer_ids`, older responses only `correct_answer_id`). Without this the review marked
 * the chosen option red even when it was right, and never marked the right one green.
 */

/** The ids of the right options an answer response names; [] when it names none. */
export function correctIdsFrom(data) {
	if (Array.isArray(data?.correct_answer_ids)) {
		return data.correct_answer_ids.map(Number).filter(Number.isFinite)
	}
	const one = Number(data?.correct_answer_id)
	return data?.correct_answer_id !== null && data?.correct_answer_id !== undefined && Number.isFinite(one) ? [one] : []
}

/** Whether an option is a right one: its own flag (instructor previews keep it) or the response's ids. */
export function isRightOption(answer, correctIds) {
	if (!answer) {
		return false
	}
	if (answer.is_correct) {
		return true
	}
	return Array.isArray(correctIds) && correctIds.includes(Number(answer.id))
}
