/**
 * TerminalPuzzle logic — extracted for testability without Vue SFC parsing.
 *
 * Used by TerminalPuzzle.vue and tested directly via Vitest.
 */

function normalizeCommand(command) {
	return String(command || '').trim().toLowerCase()
}

/**
 * Domain-aware default outputs for common commands that aren't in valid_commands.
 * Gives realistic feedback instead of "Command not recognized".
 */
const DOMAIN_DEFAULTS = {
	whoami: 'student',
	hostname: 'training-vm',
	date: () => new Date().toUTCString(),
	uptime: ' 10:23:45 up 3 days,  2:15,  1 user,  load average: 0.12, 0.08, 0.05',
	id: 'uid=1000(student) gid=1000(student) groups=1000(student),27(sudo)',
	uname: 'Linux',
	'uname -a': 'Linux training-vm 6.1.0-18-amd64 #1 SMP PREEMPT_DYNAMIC x86_64 GNU/Linux',
	'uname -r': '6.1.0-18-amd64',
	echo: (input) => input.replace(/^echo\s+/i, '').replace(/^["']|["']$/g, ''),
	'cat /etc/hostname': 'training-vm',
	'cat /etc/os-release': 'PRETTY_NAME="Debian GNU/Linux 12 (bookworm)"\nNAME="Debian GNU/Linux"\nVERSION_ID="12"\nID=debian',
	ifconfig: 'eth0: flags=4163<UP,BROADCAST,RUNNING,MULTICAST>  mtu 1500\n        inet 192.168.10.25  netmask 255.255.255.0  broadcast 192.168.10.255',
	'ip a': (input, validCommands) => {
		const full = (validCommands || []).find(c => normalizeCommand(c.command).startsWith('ip addr'))
		return full ? full.output : '2: eth0: <BROADCAST,UP> mtu 1500\n    inet 192.168.10.25/24 scope global eth0'
	},
	'ip route': 'default via 192.168.10.1 dev eth0 proto dhcp\n192.168.10.0/24 dev eth0 proto kernel scope link src 192.168.10.25',
	'ip link': '1: lo: <LOOPBACK,UP> mtu 65536 state UNKNOWN\n2: eth0: <BROADCAST,UP> mtu 1500 state UP',
	ls: (input, validCommands) => {
		const laMatch = (validCommands || []).find(c => normalizeCommand(c.command) === 'ls -la')
		if (laMatch) return laMatch.output.split('\n').filter(l => !l.startsWith('total') && !l.includes(' .')).map(l => l.split(/\s+/).pop()).filter(Boolean).join('  ')
		return 'Desktop  Documents  Downloads'
	},
	'which': (input) => {
		const cmd = input.replace(/^which\s+/i, '').trim()
		const known = { ping: '/usr/bin/ping', nslookup: '/usr/bin/nslookup', curl: '/usr/bin/curl', ss: '/usr/bin/ss', ip: '/sbin/ip', cat: '/usr/bin/cat', grep: '/usr/bin/grep', iptables: '/usr/sbin/iptables' }
		return known[cmd] || cmd + ' not found'
	},
	'ping localhost': 'PING localhost (127.0.0.1): 56 data bytes\n64 bytes from 127.0.0.1: icmp_seq=1 ttl=64 time=0.1 ms',
	man: 'What manual page do you want?\nFor example, try \'man ls\'.',
}

/**
 * Try to find a near-miss from valid_commands and suggest it.
 */
function findNearMatch(input, validCommands) {
	const lower = normalizeCommand(input)
	const words = lower.split(/\s+/)
	const baseCmd = words[0]

	for (const vc of (validCommands || [])) {
		const vcLower = normalizeCommand(vc.command)
		const vcBase = vcLower.split(/\s+/)[0]

		// Same base command, different flags
		if (baseCmd === vcBase && lower !== vcLower) {
			return vc.command
		}
	}
	return null
}

/**
 * Check domain defaults for a realistic response.
 */
function checkDomainDefault(input, validCommands) {
	const lower = normalizeCommand(input)

	// Exact match
	if (DOMAIN_DEFAULTS[lower]) {
		const val = DOMAIN_DEFAULTS[lower]
		return typeof val === 'function' ? val(input, validCommands) : val
	}

	// Base command match (e.g. "echo hello" matches "echo")
	const baseCmd = lower.split(/\s+/)[0]
	if (DOMAIN_DEFAULTS[baseCmd]) {
		const val = DOMAIN_DEFAULTS[baseCmd]
		return typeof val === 'function' ? val(input, validCommands) : val
	}

	return null
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

	// Near-miss: same base command, different flags → suggest the right one
	const nearMatch = findNearMatch(trimmed, validCommands)
	if (nearMatch) {
		return {
			valid: false,
			output: `Versuch: ${nearMatch}`,
			responseType: 'hint',
		}
	}

	// Domain defaults: realistic output for common commands
	const defaultOutput = checkDomainDefault(trimmed, validCommands)
	if (defaultOutput) {
		return {
			valid: false,
			output: defaultOutput,
			responseType: 'system',
		}
	}

	return {
		valid: false,
		output: `bash: ${trimmed}: Befehl nicht gefunden. Tippe 'help' für verfügbare Befehle.`,
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
