/**
 * Scenario / info text shown before a question (Codeberg #10).
 */

// Mirrors QuestionService::SCENARIO_MAX_LENGTH.
export const SCENARIO_MAX_LENGTH = 10000

/**
 * The scenario of a question payload, or '' when there is none.
 * Exam reviews carry it as `scenario` too, so one accessor serves every mode.
 *
 * @param {object|null|undefined} question question payload
 * @return {string}
 */
export function questionScenario(question) {
	return question && typeof question.scenario === 'string' ? question.scenario.trim() : ''
}
