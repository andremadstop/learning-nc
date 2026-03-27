/**
 * TerminalPuzzle logic — extracted for testability without Vue SFC parsing.
 *
 * Used by TerminalPuzzle.vue and tested directly via Vitest.
 */

/**
 * Validate a user command against the scenario's valid_commands list.
 * @param {string} input - Raw user input
 * @param {Array<{command: string, output: string, required: boolean}>} validCommands
 * @param {string} [hint] - Hint text for the help command
 * @returns {{valid: boolean, output: string, clear?: boolean, matched?: string}}
 */
export function validateCommand(input, validCommands, hint) {
	const trimmed = input.trim()
	const lower = trimmed.toLowerCase()

	// Built-in: clear
	if (lower === 'clear') {
		return { valid: true, output: '', clear: true }
	}

	// Built-in: help
	if (lower === 'help') {
		const helpText = hint || 'No hint available. Try exploring the system.'
		return { valid: true, output: helpText }
	}

	// Match against valid_commands (case-insensitive)
	const match = validCommands.find(vc => vc.command.toLowerCase() === lower)
	if (match) {
		return { valid: true, output: match.output, matched: match.command }
	}

	return { valid: false, output: `Command not found: ${trimmed}` }
}

/**
 * Check if all required commands have been entered.
 * @param {string[]} enteredCommands - Commands the user has entered (lowercased)
 * @param {Array<{command: string, output: string, required: boolean}>} validCommands
 * @returns {boolean}
 */
export function checkPuzzleComplete(enteredCommands, validCommands) {
	const requiredCommands = validCommands.filter(vc => vc.required)
	if (requiredCommands.length === 0) return true

	const enteredLower = enteredCommands.map(c => c.toLowerCase())
	return requiredCommands.every(rc =>
		enteredLower.includes(rc.command.toLowerCase()),
	)
}

/**
 * Check if the user has exceeded the maximum number of wrong attempts.
 * @param {number} wrongAttempts - Number of wrong commands entered
 * @param {number} maxAttempts - Maximum allowed wrong attempts (0 = unlimited)
 * @returns {boolean}
 */
export function checkMaxAttemptsExceeded(wrongAttempts, maxAttempts) {
	if (maxAttempts <= 0) return false
	return wrongAttempts >= maxAttempts
}
