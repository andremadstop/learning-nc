/**
 * TerminalPuzzle logic — extracted for testability without Vue SFC parsing.
 *
 * Used by TerminalPuzzle.vue and tested directly via Vitest.
 */

function normalizeCommand(command) {
	return String(command || '').trim().toLowerCase()
}

function buildHelpOutput(validCommands, hint) {
	const seen = new Set()
	const availableCommands = []

	for (const command of validCommands || []) {
		const rawCommand = String(command.command || '').trim()
		const normalized = normalizeCommand(rawCommand)
		if (!normalized || seen.has(normalized)) {
			continue
		}

		seen.add(normalized)
		availableCommands.push(rawCommand)
	}

	const lines = ['Available commands:']
	for (const command of availableCommands) {
		lines.push(`  ${command}`)
	}

	lines.push('Built-in commands: help, clear')

	if (hint) {
		lines.push('')
		lines.push(`Hint: ${hint}`)
	}

	return lines.join('\n')
}

function hasRequiredPrerequisites(candidateIndex, validCommands, matchedCommandIndexes) {
	const matchedIndexes = new Set(matchedCommandIndexes || [])

	for (let index = 0; index < candidateIndex; index++) {
		if (validCommands[index] && validCommands[index].required && !matchedIndexes.has(index)) {
			return false
		}
	}

	return true
}

function resolveMatchingCommand(input, validCommands, matchedCommandIndexes) {
	const normalizedInput = normalizeCommand(input)
	const matches = (validCommands || [])
		.map((command, index) => ({
			...command,
			index,
		}))
		.filter(command => normalizeCommand(command.command) === normalizedInput)

	if (matches.length === 0) {
		return null
	}

	const matchedSet = new Set(matchedCommandIndexes || [])
	const eligibleMatches = matches.filter(command =>
		hasRequiredPrerequisites(command.index, validCommands, matchedCommandIndexes),
	)
	const nextUnusedEligibleMatch = eligibleMatches.find(command => !matchedSet.has(command.index))

	if (nextUnusedEligibleMatch) {
		return nextUnusedEligibleMatch
	}

	if (eligibleMatches.length > 0) {
		return eligibleMatches[eligibleMatches.length - 1]
	}

	return matches[0]
}

/**
 * Validate a user command against the scenario's valid_commands list.
 * @param {string} input - Raw user input
 * @param {Array<{command: string, output: string, required: boolean}>} validCommands
 * @param {{hint?: string, matchedCommandIndexes?: number[]}} [options]
 * @returns {{valid: boolean, output: string, clear?: boolean, matched?: string, matchedIndex?: number, responseType: string}}
 */
export function validateCommand(input, validCommands, options = {}) {
	const trimmed = input.trim()
	const lower = normalizeCommand(trimmed)
	const hint = options.hint || ''
	const matchedCommandIndexes = options.matchedCommandIndexes || []

	// Built-in: clear
	if (lower === 'clear') {
		return { valid: true, output: '', clear: true, responseType: 'system' }
	}

	// Built-in: help
	if (lower === 'help') {
		return {
			valid: true,
			output: buildHelpOutput(validCommands, hint),
			responseType: 'help',
		}
	}

	// Match against valid_commands (case-insensitive) and respect scenario progress.
	const match = resolveMatchingCommand(trimmed, validCommands, matchedCommandIndexes)
	if (match) {
		return {
			valid: true,
			output: match.output,
			matched: match.command,
			matchedIndex: match.index,
			responseType: 'success',
		}
	}

	return {
		valid: false,
		output: "Command not recognized. Try 'help' for available commands.",
		responseType: 'error',
	}
}

/**
 * Check if all required command entries have been matched.
 * @param {number[]} matchedCommandIndexes - Indexes of matched valid_commands entries
 * @param {Array<{command: string, output: string, required: boolean}>} validCommands
 * @returns {boolean}
 */
export function checkPuzzleComplete(matchedCommandIndexes, validCommands) {
	const requiredCommands = validCommands
		.map((command, index) => ({ ...command, index }))
		.filter(command => command.required)
	if (requiredCommands.length === 0) return true

	const matchedSet = new Set(matchedCommandIndexes || [])
	return requiredCommands.every(command => matchedSet.has(command.index))
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
