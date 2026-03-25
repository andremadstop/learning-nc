/**
 * dauBotEngine.js — Pure JS state machine for the DauBot challenge UI.
 */

function findOptionLabel(options, selectedId) {
	if (!Array.isArray(options) || !selectedId) {
		return selectedId || ''
	}

	const match = options.find(option => option.id === selectedId)
	return match?.label || selectedId
}

/**
 * Create a fresh client-side session from a bot correction scenario.
 *
 * @param {object|null|undefined} scenario
 * @returns {{phase: 'diagnose', scenario: object, selectedErrorId: string|null, selectedFixId: string|null, passed: boolean|null, result: object|null}}
 */
export function createBotSession(scenario) {
	return {
		phase: 'diagnose',
		scenario: scenario && typeof scenario === 'object' ? scenario : {},
		selectedErrorId: null,
		selectedFixId: null,
		passed: null,
		result: null,
	}
}

/**
 * Move from diagnosis to fix selection.
 *
 * @param {object} session
 * @param {string} errorId
 * @returns {object}
 */
export function submitDiagnosis(session, errorId) {
	return {
		...(session || createBotSession({})),
		phase: 'fix',
		selectedErrorId: errorId || null,
	}
}

/**
 * Move from fix selection to awaiting server validation.
 *
 * @param {object} session
 * @param {string} fixId
 * @returns {object}
 */
export function submitFix(session, fixId) {
	return {
		...(session || createBotSession({})),
		phase: 'awaiting_result',
		selectedFixId: fixId || null,
	}
}

/**
 * Apply the backend result and enter summary mode.
 *
 * @param {object} session
 * @param {object|null|undefined} serverResult
 * @returns {object}
 */
export function applyResult(session, serverResult) {
	const passed = Boolean(
		serverResult?.passed ??
		serverResult?.bot_correction?.passed,
	)

	return {
		...(session || createBotSession({})),
		phase: 'summary',
		passed,
		result: serverResult || null,
	}
}

/**
 * German reaction text with mild comic panic.
 *
 * @param {string} phase
 * @param {boolean|null|undefined} passed
 * @returns {string}
 */
export function getKlausReaction(phase, passed) {
	const normalizedPassed = Boolean(passed)

	if (phase === 'diagnose') {
		return normalizedPassed
			? 'Puh, das war es also! Ich dachte schon, der Server ist explodiert...'
			: 'Hmm... bist du sicher? Die LEDs blinken immer noch so komisch...'
	}

	if (phase === 'fix' || phase === 'summary' || phase === 'awaiting_result') {
		return normalizedPassed
			? 'Wow, das hat geklappt! Ich haette einfach nochmal neu gestartet...'
			: 'Aehm... ich glaube jetzt blinkt ALLES rot. Ist das normal?'
	}

	return 'Ich versuche nur, nichts mehr aus Versehen abzuziehen.'
}

/**
 * Map the client phase to CharacterAvatar states.
 *
 * @param {string} phase
 * @param {boolean|null|undefined} passed
 * @returns {string}
 */
export function getKlausAvatarState(phase, passed) {
	if (phase === 'diagnose') return 'confused'
	if (phase === 'fix') return 'alert'
	if (phase === 'awaiting_result') return 'thinking'
	if (phase === 'summary') {
		return passed ? 'relieved' : 'frustrated'
	}
	return 'idle'
}

/**
 * Build a short German summary for the final panel.
 *
 * @param {object} session
 * @param {object|null|undefined} serverResult
 * @returns {string}
 */
export function computeBotSummary(session, serverResult) {
	const safeSession = session || createBotSession({})
	const effectivePassed = serverResult?.passed ?? serverResult?.bot_correction?.passed ?? safeSession.passed
	const errorLabel = findOptionLabel(safeSession.scenario?.error_options, safeSession.selectedErrorId)
	const fixLabel = findOptionLabel(safeSession.scenario?.fix_options, safeSession.selectedFixId)

	if (effectivePassed) {
		return `Du hast "${errorLabel}" erkannt und mit "${fixLabel}" sauber reagiert. Klaus atmet wieder.`
	}

	return `Der Ansatz "${fixLabel}" hat das Problem "${errorLabel}" nicht geloest. Klaus hat immerhin gelernt, diesmal erst zu fragen.`
}

/**
 * Compatibility helper for a generic phase advance API from early notes.
 *
 * @param {object} session
 * @param {string} selectedId
 * @returns {object}
 */
export function advanceBotPhase(session, selectedId) {
	if (session?.phase === 'diagnose') {
		return submitDiagnosis(session, selectedId)
	}
	if (session?.phase === 'fix') {
		return submitFix(session, selectedId)
	}
	return session || createBotSession({})
}

/**
 * Read score/reputation changes from a backend response if available.
 *
 * @param {object|null|undefined} serverResponse
 * @returns {{scoreChange: number, reputationChanges: Record<string, number>}}
 */
export function computeBotScoring(serverResponse) {
	return {
		scoreChange: Number(serverResponse?.score_change) || 0,
		reputationChanges: serverResponse?.reputation_changes || {},
	}
}
