import { describe, expect, it } from 'vitest'
import {
	validateCommand,
	checkPuzzleComplete,
	checkMaxAttemptsExceeded,
} from '../../src/utils/terminalPuzzleLogic.js'

const mockScenario = {
	prompt: 'Find the suspicious process and kill it.',
	objective: 'Identify and terminate the malicious process ghostline.bin',
	hint: 'Try listing processes first, then use kill.',
	max_attempts: 5,
	valid_commands: [
		{ command: 'ps aux', output: 'PID  USER  %CPU  COMMAND\n1337  root  98.2  ghostline.bin\n1  root  0.0  init', required: true },
		{ command: 'kill 1337', output: 'Process 1337 terminated.', required: true },
		{ command: 'ls', output: 'bin  etc  home  var', required: false },
		{ command: 'whoami', output: 'root', required: false },
	],
	success_message: 'Threat neutralized.',
}

describe('validateCommand', () => {
	it('returns valid=true with output for a matching command', () => {
		const result = validateCommand('ps aux', mockScenario.valid_commands)
		expect(result.valid).toBe(true)
		expect(result.output).toContain('ghostline.bin')
	})

	it('returns valid=false for an unknown command', () => {
		const result = validateCommand('rm -rf /', mockScenario.valid_commands)
		expect(result.valid).toBe(false)
		expect(result.output).toBeTruthy()
	})

	it('matches commands case-insensitively', () => {
		const result = validateCommand('PS AUX', mockScenario.valid_commands)
		expect(result.valid).toBe(true)
	})

	it('handles the help command', () => {
		const result = validateCommand('help', mockScenario.valid_commands, 'Try listing processes first, then use kill.')
		expect(result.valid).toBe(true)
		expect(result.output).toContain('Try listing processes')
	})

	it('handles the clear command', () => {
		const result = validateCommand('clear', mockScenario.valid_commands)
		expect(result.valid).toBe(true)
		expect(result.clear).toBe(true)
	})

	it('trims whitespace from input', () => {
		const result = validateCommand('  ps aux  ', mockScenario.valid_commands)
		expect(result.valid).toBe(true)
	})
})

describe('checkPuzzleComplete', () => {
	it('returns true when all required commands have been entered', () => {
		const enteredCommands = ['ps aux', 'kill 1337']
		expect(checkPuzzleComplete(enteredCommands, mockScenario.valid_commands)).toBe(true)
	})

	it('returns false when some required commands are missing', () => {
		const enteredCommands = ['ps aux']
		expect(checkPuzzleComplete(enteredCommands, mockScenario.valid_commands)).toBe(false)
	})

	it('returns false with no commands entered', () => {
		expect(checkPuzzleComplete([], mockScenario.valid_commands)).toBe(false)
	})

	it('ignores non-required commands in completion check', () => {
		const enteredCommands = ['ls', 'whoami']
		expect(checkPuzzleComplete(enteredCommands, mockScenario.valid_commands)).toBe(false)
	})
})

describe('checkMaxAttemptsExceeded', () => {
	it('returns false when wrong attempts are under max', () => {
		expect(checkMaxAttemptsExceeded(3, 5)).toBe(false)
	})

	it('returns true when wrong attempts equal max', () => {
		expect(checkMaxAttemptsExceeded(5, 5)).toBe(true)
	})

	it('returns true when wrong attempts exceed max', () => {
		expect(checkMaxAttemptsExceeded(7, 5)).toBe(true)
	})

	it('returns false when max_attempts is 0 (unlimited)', () => {
		expect(checkMaxAttemptsExceeded(100, 0)).toBe(false)
	})
})
