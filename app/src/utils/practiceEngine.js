/**
 * Practice Engine — pure utility for subnet/VLSM/IPv6 practice scenarios.
 *
 * No Vue or Nextcloud dependencies. Scenario-agnostic: scenarios contain
 * pre-computed expectedAnswers so no import of subnetMath needed.
 */

import { expandIPv6 } from './ipv6Math.js'

/**
 * Normalize a user-provided answer for comparison.
 * - Trims whitespace
 * - Strips leading zeros from dotted-quad octets (010.001 -> 10.1)
 * - Preserves CIDR suffix if present
 */
export function normalizeAnswer(raw) {
	if (raw == null) return ''
	const trimmed = String(raw).trim()
	if (trimmed === '') return ''

	// Check for CIDR suffix (e.g. "192.168.0.0/24")
	let ipPart = trimmed
	let cidrSuffix = ''
	const slashIdx = trimmed.indexOf('/')
	if (slashIdx !== -1) {
		ipPart = trimmed.substring(0, slashIdx)
		cidrSuffix = trimmed.substring(slashIdx)
	}

	// If looks like dotted notation, strip leading zeros from each part
	if (ipPart.includes('.')) {
		const parts = ipPart.split('.')
		// Only all-digit parts lose their leading zeros; '63x' must stay '63x' and compare
		// unequal, parseInt turned it into 63 (audit #117).
		const normalized = parts.map(p => (/^\d+$/.test(p) ? String(Number(p)) : p)).join('.')
		return normalized + cidrSuffix
	}

	return trimmed
}

/**
 * Split an answer into an expanded IPv6 address and an optional prefix suffix.
 * Returns null when the address part is not a valid IPv6 address.
 */
function splitIPv6(value) {
	if (typeof value !== 'string' || !value.includes(':')) return null
	const slashIdx = value.indexOf('/')
	const addr = slashIdx === -1 ? value : value.substring(0, slashIdx)
	const expanded = expandIPv6(addr)
	if (expanded === null) return null
	return { address: expanded, prefix: slashIdx === -1 ? '' : value.substring(slashIdx + 1).trim() }
}

/**
 * IPv6 answers are equal when their expanded addresses match (case, zero padding and '::'
 * compression do not matter). A prefix is compared only when the expected answer has one.
 */
function ipv6Equal(expectedNorm, givenNorm) {
	const expected = splitIPv6(expectedNorm)
	if (expected === null) return null
	const given = splitIPv6(givenNorm)
	if (given === null) return false
	if (expected.address !== given.address) return false
	return expected.prefix === '' || expected.prefix === given.prefix
}

/**
 * Compare expected answers with user answers field by field.
 * Special handling for CIDR fields: slash is optional.
 */
export function checkAnswers(expectedAnswers, userAnswers) {
	const results = []

	for (const field of Object.keys(expectedAnswers)) {
		const expectedRaw = expectedAnswers[field]
		const givenRaw = userAnswers[field] || ''

		let expectedNorm = normalizeAnswer(expectedRaw)
		let givenNorm = normalizeAnswer(givenRaw)

		// Special CIDR handling: strip leading slash for comparison
		if (field === 'cidr' || field.toLowerCase().includes('cidr') || field.toLowerCase().includes('prefix')) {
			expectedNorm = expectedNorm.replace(/^\//, '')
			givenNorm = givenNorm.replace(/^\//, '')
		}

		const ipv6Match = ipv6Equal(expectedNorm, givenNorm)

		results.push({
			field,
			correct: ipv6Match === null ? expectedNorm === givenNorm : ipv6Match,
			expected: normalizeAnswer(expectedRaw),
			given: normalizeAnswer(givenRaw),
		})
	}

	return results
}

/**
 * Fisher-Yates shuffle (in-place).
 */
function shuffle(arr) {
	for (let i = arr.length - 1; i > 0; i--) {
		const j = Math.floor(Math.random() * (i + 1));
		[arr[i], arr[j]] = [arr[j], arr[i]]
	}
	return arr
}

/**
 * Create a new practice session.
 * @param {Array} [scenarios] - Optional scenario pool; defaults to DEMO_SCENARIOS
 * @returns {object} Session state
 */
export function createPracticeSession(scenarios) {
	const pool = scenarios || DEMO_SCENARIOS
	const shuffled = shuffle([...pool])

	return {
		scenarios: shuffled,
		current: null,
		answered: new Set(),
		correct: 0,
		total: 0,
		streak: 0,
		maxStreak: 0,
	}
}

/**
 * Pick the next unanswered scenario.
 * @returns {object|null} Next scenario or null if all answered
 */
export function nextScenario(session) {
	const next = session.scenarios.find(s => !session.answered.has(s.id))
	session.current = next || null
	return session.current
}

/**
 * Record a submission against the current scenario.
 * @param {object} session - The session state
 * @param {Array} fieldResults - Output of checkAnswers()
 */
export function submitAnswer(session, fieldResults) {
	session.total++
	if (session.current) {
		session.answered.add(session.current.id)
	}

	const allCorrect = fieldResults.every(r => r.correct)
	if (allCorrect) {
		session.correct++
		session.streak++
		session.maxStreak = Math.max(session.maxStreak, session.streak)
	} else {
		session.streak = 0
	}
}

/**
 * Get current progress snapshot.
 */
export function getProgress(session) {
	return {
		correct: session.correct,
		total: session.total,
		streak: session.streak,
		maxStreak: session.maxStreak,
		remaining: session.scenarios.length - session.answered.size,
	}
}

/**
 * 5 demo scenarios for standalone testing and initial UX.
 */
export const DEMO_SCENARIOS = [
	{
		id: 'demo-subnet-easy-1',
		type: 'subnet',
		difficulty: 'easy',
		question: 'Berechne Netzadresse und Broadcast für 192.168.10.0/26',
		context: null,
		expectedAnswers: {
			networkAddress: '192.168.10.0',
			broadcast: '192.168.10.63',
			hostCount: '62',
		},
	},
	{
		id: 'demo-subnet-easy-2',
		type: 'subnet',
		difficulty: 'easy',
		question: 'Wie viele Hosts passen in 10.0.0.0/28?',
		context: null,
		expectedAnswers: {
			hostCount: '14',
			subnetMask: '255.255.255.240',
		},
	},
	{
		id: 'demo-subnet-medium-1',
		type: 'subnet',
		difficulty: 'medium',
		question: 'Bestimme die Netzadresse für die IP 172.16.45.130/20',
		context: null,
		expectedAnswers: {
			networkAddress: '172.16.32.0',
			broadcast: '172.16.47.255',
			cidr: '/20',
		},
	},
	{
		id: 'demo-vlsm-medium-1',
		type: 'vlsm',
		difficulty: 'medium',
		question: 'Ein /24 Netz wird geteilt: 100 Hosts und 50 Hosts. Welchen Prefix bekommt das größere Subnetz?',
		context: null,
		expectedAnswers: {
			cidr: '/25',
		},
	},
	{
		id: 'demo-ipv6-easy-1',
		type: 'ipv6',
		difficulty: 'easy',
		question: 'Wie lautet die Netzadresse von 2001:db8:abcd:0012::1/48?',
		context: null,
		expectedAnswers: {
			networkAddress: '2001:0db8:abcd:0000:0000:0000:0000:0000',
		},
	},
]
