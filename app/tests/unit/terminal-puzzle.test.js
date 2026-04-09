import { describe, expect, it, vi } from 'vitest'
import {
	validateCommand,
	checkPuzzleComplete,
	checkMaxAttemptsExceeded,
} from '../../src/utils/terminalPuzzleLogic.js'
import TerminalPuzzle from '../../src/components/TerminalPuzzle.vue'

const mockScenario = {
	id: 'process-hunt',
	prompt: 'ghostline@forensics:~$',
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

function createTerminalInstance(scenario = mockScenario) {
	const data = typeof TerminalPuzzle.data === 'function' ? TerminalPuzzle.data() : {}
	const instance = {
		...data,
		scenario,
		scenarioId: scenario.id,
		$emit: vi.fn(),
		$nextTick: vi.fn(cb => {
			if (typeof cb === 'function') cb()
		}),
		$refs: {
			outputArea: { scrollTop: 0, scrollHeight: 321 },
			cmdInput: { focus: vi.fn() },
		},
	}

	Object.defineProperties(instance, {
		effectiveScenario: {
			get: () => TerminalPuzzle.computed.effectiveScenario.call(instance),
		},
		validCommands: {
			get: () => TerminalPuzzle.computed.validCommands.call(instance),
		},
		promptText: {
			get: () => TerminalPuzzle.computed.promptText.call(instance),
		},
		maxAttempts: {
			get: () => TerminalPuzzle.computed.maxAttempts.call(instance),
		},
	})

	if (TerminalPuzzle.methods) {
		for (const [name, fn] of Object.entries(TerminalPuzzle.methods)) {
			instance[name] = fn.bind(instance)
		}
	}

	return instance
}

describe('validateCommand', () => {
	it('returns valid=true with output for a matching command', () => {
		const result = validateCommand('ps aux', mockScenario.valid_commands)
		expect(result.valid).toBe(true)
		expect(result.output).toContain('ghostline.bin')
		expect(result.responseType).toBe('success')
	})

	it('returns valid=false for an unknown command', () => {
		const result = validateCommand('rm -rf /', mockScenario.valid_commands)
		expect(result.valid).toBe(false)
		expect(result.output).toContain('rm -rf /')
		expect(result.responseType).toBe('error')
	})

	it('matches commands case-insensitively', () => {
		const result = validateCommand('PS AUX', mockScenario.valid_commands)
		expect(result.valid).toBe(true)
	})

	it('handles the help command', () => {
		const result = validateCommand('help', mockScenario.valid_commands, {
			hint: 'Try listing processes first, then use kill.',
		})
		expect(result.valid).toBe(true)
		expect(result.output).toContain('Available commands:')
		expect(result.output).toContain('ps aux')
		expect(result.output).toContain('Hint: Try listing processes first, then use kill.')
		expect(result.responseType).toBe('help')
	})

	it('handles the clear command', () => {
		const result = validateCommand('clear', mockScenario.valid_commands)
		expect(result.valid).toBe(true)
		expect(result.clear).toBe(true)
		expect(result.responseType).toBe('system')
	})

	it('trims whitespace from input', () => {
		const result = validateCommand('  ps aux  ', mockScenario.valid_commands)
		expect(result.valid).toBe(true)
	})

	it('switches duplicate commands to the later output once prerequisites are met', () => {
		const scenario = [
			{ command: 'show ip route', output: 'before', required: false },
			{ command: 'ip route add default via 10.0.0.1', output: 'added', required: true },
			{ command: 'show ip route', output: 'after', required: false },
		]

		const firstShow = validateCommand('show ip route', scenario, { matchedCommandIndexes: [] })
		const routeAdd = validateCommand('ip route add default via 10.0.0.1', scenario, {
			matchedCommandIndexes: [firstShow.matchedIndex],
		})
		const secondShow = validateCommand('show ip route', scenario, {
			matchedCommandIndexes: [firstShow.matchedIndex, routeAdd.matchedIndex],
		})

		expect(firstShow.output).toBe('before')
		expect(secondShow.output).toBe('after')
	})
})

describe('checkPuzzleComplete', () => {
	it('returns true when all required commands have been entered', () => {
		const matchedCommandIndexes = [0, 1]
		expect(checkPuzzleComplete(matchedCommandIndexes, mockScenario.valid_commands)).toBe(true)
	})

	it('returns false when some required commands are missing', () => {
		const matchedCommandIndexes = [0]
		expect(checkPuzzleComplete(matchedCommandIndexes, mockScenario.valid_commands)).toBe(false)
	})

	it('returns false with no commands entered', () => {
		expect(checkPuzzleComplete([], mockScenario.valid_commands)).toBe(false)
	})

	it('ignores non-required commands in completion check', () => {
		const matchedCommandIndexes = [2, 3]
		expect(checkPuzzleComplete(matchedCommandIndexes, mockScenario.valid_commands)).toBe(false)
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

describe('TerminalPuzzle component behavior', () => {
	it('uses the scenario prompt text when available', () => {
		const instance = createTerminalInstance()
		expect(instance.promptText).toBe('ghostline@forensics:~$')
	})

	it('records command history and tracked commands on submit', () => {
		const instance = createTerminalInstance()
		instance.currentInput = 'ps aux'

		instance.submitCommand()

		expect(instance.commandHistory).toHaveLength(1)
		expect(instance.commandHistory[0].command).toBe('ps aux')
		expect(instance.commandHistory[0].output).toContain('ghostline.bin')
		expect(instance.commandHistory[0].responseType).toBe('success')
		expect(instance.matchedCommandIndexes).toEqual([0])
		expect(instance.$refs.outputArea.scrollTop).toBe(321)
	})

	it('clears visible history when the clear command is entered', () => {
		const instance = createTerminalInstance()
		instance.commandHistory = [{ command: 'ls', output: 'bin  etc  home  var' }]
		instance.currentInput = 'clear'

		instance.submitCommand()

		expect(instance.commandHistory).toEqual([])
		expect(instance.wrongAttempts).toBe(0)
	})

	it('marks the puzzle solved after all required commands and emits a result', () => {
		const instance = createTerminalInstance()

		instance.currentInput = 'ps aux'
		instance.submitCommand()
		instance.currentInput = 'kill 1337'
		instance.submitCommand()

		expect(instance.solved).toBe(true)
		expect(instance.finished).toBe(true)
		expect(instance.$emit).toHaveBeenCalledWith('result', { correct: true, score: 1.0 })
	})
})
