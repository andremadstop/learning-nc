/**
 * Pure IPv6 math functions for the SubnetCalculator.
 * Uses native BigInt for 128-bit arithmetic — no external dependencies.
 */

const GROUP_COUNT = 8
const BITS_PER_GROUP = 16
const TOTAL_BITS = 128
const MAX_128 = (1n << 128n) - 1n

/**
 * Expand an IPv6 shorthand address to full 8-group form.
 * Returns null on invalid input.
 * @param {string} input
 * @returns {string|null} e.g. '2001:0db8:0000:0000:0000:0000:0000:0001'
 */
export function expandIPv6(input) {
	if (typeof input !== 'string' || !input) return null
	const trimmed = input.trim()
	if (!trimmed) return null

	// Reject zone IDs
	if (trimmed.includes('%')) return null

	// Reject anything that looks like IPv4 (contains dots)
	if (trimmed.includes('.')) return null

	// Handle :: expansion
	let halves
	if (trimmed.includes('::')) {
		const parts = trimmed.split('::')
		if (parts.length !== 2) return null // more than one ::

		const left = parts[0] ? parts[0].split(':') : []
		const right = parts[1] ? parts[1].split(':') : []
		const missing = GROUP_COUNT - left.length - right.length

		if (missing < 0) return null

		const middle = Array(missing).fill('0')
		halves = [...left, ...middle, ...right]
	} else {
		halves = trimmed.split(':')
	}

	if (halves.length !== GROUP_COUNT) return null

	// Validate and pad each group
	const expanded = []
	for (const group of halves) {
		if (!/^[0-9a-fA-F]{1,4}$/.test(group)) return null
		expanded.push(group.padStart(4, '0').toLowerCase())
	}

	return expanded.join(':')
}

/**
 * Parse an IPv6 address with prefix length.
 * @param {string} input e.g. '2001:db8::1/48'
 * @returns {{ groups: number[], prefix: number }|null}
 */
export function parseIPv6(input) {
	if (typeof input !== 'string' || !input) return null
	const trimmed = input.trim()

	const slashIndex = trimmed.lastIndexOf('/')
	if (slashIndex === -1) return null

	const addrPart = trimmed.slice(0, slashIndex)
	const prefixPart = trimmed.slice(slashIndex + 1)

	if (!/^\d+$/.test(prefixPart)) return null
	const prefix = Number(prefixPart)
	if (prefix < 0 || prefix > TOTAL_BITS) return null

	const expanded = expandIPv6(addrPart)
	if (!expanded) return null

	const groups = expanded.split(':').map(g => parseInt(g, 16))
	return { groups, prefix }
}

/**
 * Convert 8 uint16 groups to a single BigInt.
 * @param {number[]} groups
 * @returns {bigint}
 */
export function ipv6ToBigInt(groups) {
	let result = 0n
	for (let i = 0; i < GROUP_COUNT; i++) {
		result = (result << 16n) | BigInt(groups[i])
	}
	return result
}

/**
 * Convert a BigInt back to 8 uint16 groups.
 * @param {bigint} value
 * @returns {number[]}
 */
export function bigIntToGroups(value) {
	const groups = new Array(GROUP_COUNT)
	let remaining = value
	for (let i = GROUP_COUNT - 1; i >= 0; i--) {
		groups[i] = Number(remaining & 0xFFFFn)
		remaining >>= 16n
	}
	return groups
}

/**
 * Calculate IPv6 subnet details.
 * @param {string} address Expanded or shorthand IPv6 address
 * @param {number} prefix Prefix length 0-128
 * @returns {object|null}
 */
export function calculateIPv6Subnet(address, prefix) {
	const expanded = expandIPv6(address)
	if (!expanded) return null
	if (prefix < 0 || prefix > TOTAL_BITS) return null

	const groups = expanded.split(':').map(g => parseInt(g, 16))
	const ip = ipv6ToBigInt(groups)

	// Build mask: prefix bits set to 1, rest 0
	const mask = prefix === 0 ? 0n : (MAX_128 << BigInt(TOTAL_BITS - prefix)) & MAX_128
	const inverseMask = mask ^ MAX_128

	const network = ip & mask
	const lastAddr = network | inverseMask

	// First host = network + 1, last host = last - 1 (if prefix < 127)
	let firstHost, lastHost, hostCount
	if (prefix >= 127) {
		firstHost = network
		lastHost = lastAddr
		hostCount = prefix === 128 ? 0n : (lastAddr - network + 1n)
	} else {
		firstHost = network + 1n
		lastHost = lastAddr - 1n
		hostCount = lastAddr - network - 1n
	}

	const networkGroups = bigIntToGroups(network)
	const firstHostGroups = bigIntToGroups(firstHost)
	const lastHostGroups = bigIntToGroups(lastHost)

	return {
		network,
		networkGroups,
		firstHost,
		firstHostGroups,
		lastHost,
		lastHostGroups,
		hostCount,
		prefix,
		groups,
	}
}

/**
 * Detect IPv6 address type from groups.
 * @param {number[]} groups 8 uint16 groups
 * @returns {string} German-friendly type label
 */
export function ipv6AddressType(groups) {
	const first = groups[0]

	// Loopback ::1
	if (groups[0] === 0 && groups[1] === 0 && groups[2] === 0 && groups[3] === 0
		&& groups[4] === 0 && groups[5] === 0 && groups[6] === 0 && groups[7] === 1) {
		return 'Loopback'
	}

	// Unspecified ::
	if (groups.every(g => g === 0)) {
		return 'Unspecified'
	}

	// Multicast ff00::/8
	if ((first & 0xFF00) === 0xFF00) {
		return 'Multicast'
	}

	// Link-Local fe80::/10
	if ((first & 0xFFC0) === 0xFE80) {
		return 'Link-Local'
	}

	// Unique Local fc00::/7
	if ((first & 0xFE00) === 0xFC00) {
		return 'Unique Local'
	}

	// Global Unicast 2000::/3
	if ((first & 0xE000) === 0x2000) {
		return 'Global Unicast'
	}

	return 'Reserved'
}

/**
 * Convert groups to a 128-element bit array [0|1, ...].
 * @param {number[]} groups 8 uint16 groups
 * @returns {number[]} 128 elements
 */
export function ipv6ToBitArray(groups) {
	const bits = new Array(TOTAL_BITS)
	for (let g = 0; g < GROUP_COUNT; g++) {
		const val = groups[g]
		for (let b = 0; b < BITS_PER_GROUP; b++) {
			bits[g * BITS_PER_GROUP + b] = (val >> (BITS_PER_GROUP - 1 - b)) & 1
		}
	}
	return bits
}

/**
 * Format groups as full colon-hex string (no shorthand).
 * @param {number[]} groups 8 uint16 groups
 * @returns {string} e.g. '2001:0db8:0000:0000:0000:0000:0000:0001'
 */
export function formatIPv6(groups) {
	return groups.map(g => g.toString(16).padStart(4, '0')).join(':')
}
