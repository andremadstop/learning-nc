<template>
	<div class="terminal-puzzle">
		<div class="terminal-puzzle__objective">
			<span class="terminal-puzzle__objective-label">OBJECTIVE:</span>
			{{ effectiveScenario.objective || 'Complete the terminal challenge.' }}
		</div>

		<div ref="outputArea" class="terminal-puzzle__output">
			<div
				v-for="(entry, idx) in commandHistory"
				:key="idx"
				class="terminal-puzzle__entry">
				<div class="terminal-puzzle__prompt-line">
					<span class="terminal-puzzle__prompt">{{ promptText }}</span>
					<span class="terminal-puzzle__command">{{ entry.command }}</span>
				</div>
				<pre
					v-if="entry.output"
					class="terminal-puzzle__response"
					:class="responseClass(entry)">{{ entry.output }}</pre>
			</div>
		</div>

		<div v-if="!finished" class="terminal-puzzle__input-row">
			<span class="terminal-puzzle__prompt">{{ promptText }}</span>
			<input
				ref="cmdInput"
				v-model="currentInput"
				class="terminal-puzzle__input"
				type="text"
				autocomplete="off"
				spellcheck="false"
				@keydown.enter.prevent="submitCommand">
		</div>

		<div v-if="finished" class="terminal-puzzle__status" :class="solved ? 'terminal-puzzle__status--success' : 'terminal-puzzle__status--fail'">
			{{ solved ? (effectiveScenario.success_message || 'Puzzle solved.') : 'Max attempts reached. Mission failed.' }}
		</div>
	</div>
</template>

<script>
import {
	validateCommand,
	checkPuzzleComplete,
	checkMaxAttemptsExceeded,
} from '../utils/terminalPuzzleLogic.js'

export default {
	name: 'TerminalPuzzle',

	props: {
		scenario: {
			type: Object,
			default: () => ({}),
		},
		scenarioId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			commandHistory: [],
			currentInput: '',
			matchedCommandIndexes: [],
			wrongAttempts: 0,
			solved: false,
			finished: false,
		}
	},

	computed: {
		effectiveScenario() {
			return this.scenario || {}
		},
		validCommands() {
			return this.effectiveScenario.valid_commands || []
		},
		promptText() {
			return this.effectiveScenario.prompt || 'ghostline@root:~$'
		},
		maxAttempts() {
			return this.effectiveScenario.max_attempts || 0
		},
	},

	mounted() {
		this.$nextTick(() => {
			if (this.$refs.cmdInput) {
				this.$refs.cmdInput.focus()
			}
		})
	},

	methods: {
		responseClass(entry) {
			return `terminal-puzzle__response--${entry.responseType || 'system'}`
		},
		focusInput() {
			this.$nextTick(() => {
				if (this.finished || !this.$refs.cmdInput) {
					return
				}

				this.$refs.cmdInput.focus()
			})
		},
		scrollOutputToBottom() {
			this.$nextTick(() => {
				if (this.$refs.outputArea) {
					this.$refs.outputArea.scrollTop = this.$refs.outputArea.scrollHeight
				}
			})
		},
		finalizeCommandRender() {
			this.scrollOutputToBottom()
			this.focusInput()
		},
		submitCommand() {
			const input = this.currentInput.trim()
			if (!input || this.finished) return

			this.currentInput = ''

			const result = validateCommand(input, this.validCommands, {
				hint: this.effectiveScenario.hint,
				matchedCommandIndexes: this.matchedCommandIndexes,
			})

			// Handle clear
			if (result.clear) {
				this.commandHistory = []
				this.finalizeCommandRender()
				return
			}

			this.commandHistory.push({
				command: input,
				output: result.output,
				responseType: result.responseType,
			})

			if (result.valid && Number.isInteger(result.matchedIndex) && !this.matchedCommandIndexes.includes(result.matchedIndex)) {
				this.matchedCommandIndexes.push(result.matchedIndex)
			} else if (!result.valid) {
				this.wrongAttempts++
			}

			// Check completion
			if (checkPuzzleComplete(this.matchedCommandIndexes, this.validCommands)) {
				this.solved = true
				this.finished = true
				this.finalizeCommandRender()
				this.$emit('result', { correct: true, score: 1.0 })
				return
			}

			// Check max attempts
			if (checkMaxAttemptsExceeded(this.wrongAttempts, this.maxAttempts)) {
				this.finished = true
				this.finalizeCommandRender()
				this.$emit('result', { correct: false, score: 0.0 })
				return
			}

			this.finalizeCommandRender()
		},
	},
}
</script>

<style scoped>
.terminal-puzzle {
	background: #0a0a0a;
	color: #00ff41;
	font-family: 'Courier New', 'Fira Mono', monospace;
	font-size: 14px;
	line-height: 1.5;
	border: 1px solid #1a3a1a;
	border-radius: 4px;
	padding: 16px;
	position: relative;
	overflow: hidden;
	min-height: 300px;
	display: flex;
	flex-direction: column;
}

/* Scanline overlay */
.terminal-puzzle::after {
	content: '';
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: repeating-linear-gradient(
		transparent 0%,
		transparent 50%,
		rgba(0, 0, 0, 0.03) 50%,
		rgba(0, 0, 0, 0.03) 100%
	);
	background-size: 100% 3px;
	pointer-events: none;
	z-index: 1;
}

.terminal-puzzle__objective {
	color: #00bcd4;
	border-bottom: 1px solid #1a3a3a;
	padding-bottom: 8px;
	margin-bottom: 12px;
	font-size: 13px;
}

.terminal-puzzle__objective-label {
	color: #d946ef;
	font-weight: bold;
	margin-right: 8px;
}

.terminal-puzzle__output {
	flex: 1;
	overflow-y: auto;
	margin-bottom: 8px;
	max-height: 400px;
}

.terminal-puzzle__entry {
	margin-bottom: 4px;
}

.terminal-puzzle__prompt-line {
	display: flex;
	gap: 8px;
}

.terminal-puzzle__prompt {
	color: #d946ef;
	white-space: nowrap;
	flex-shrink: 0;
}

.terminal-puzzle__command {
	color: #00ff41;
}

.terminal-puzzle__response {
	margin: 2px 0 0;
	padding: 6px 10px;
	font-family: inherit;
	font-size: inherit;
	white-space: pre-wrap;
	word-break: break-word;
	border-left: 3px solid transparent;
	border-radius: 4px;
	background: rgba(255, 255, 255, 0.04);
}

.terminal-puzzle__response--success {
	color: #86efac;
	border-left-color: #22c55e;
	background: rgba(34, 197, 94, 0.12);
}

.terminal-puzzle__response--error {
	color: #fda4af;
	border-left-color: #f43f5e;
	background: rgba(244, 63, 94, 0.12);
}

.terminal-puzzle__response--help {
	color: #93c5fd;
	border-left-color: #38bdf8;
	background: rgba(56, 189, 248, 0.12);
}

.terminal-puzzle__response--system {
	color: #d1d5db;
	border-left-color: #6b7280;
	background: rgba(107, 114, 128, 0.12);
}

.terminal-puzzle__input-row {
	display: flex;
	gap: 8px;
	align-items: center;
}

.terminal-puzzle__input {
	background: transparent;
	border: none;
	color: #00ff41;
	font-family: inherit;
	font-size: inherit;
	outline: none;
	flex: 1;
	caret-color: #00ff41;
}

.terminal-puzzle__status {
	margin-top: 12px;
	padding: 8px;
	border-radius: 4px;
	font-weight: bold;
	text-align: center;
}

.terminal-puzzle__status--success {
	color: #00ff41;
	border: 1px solid #00ff41;
	background: rgba(0, 255, 65, 0.1);
}

.terminal-puzzle__status--fail {
	color: #f43f5e;
	border: 1px solid #f43f5e;
	background: rgba(244, 63, 94, 0.1);
}

@media (prefers-reduced-motion: reduce) {
	.terminal-puzzle::after {
		display: none;
	}
}
</style>
