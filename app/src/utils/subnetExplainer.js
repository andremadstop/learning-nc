/**
 * Pure explanation generator for subnet calculation results.
 * Produces human-readable step-by-step calculation paths (German).
 * No Vue dependency, no external deps — pure ES module.
 */

/**
 * Format mask octets as dotted binary string.
 * @param {number[]} octets e.g. [255, 255, 255, 0]
 * @returns {string} e.g. "11111111.11111111.11111111.00000000"
 */
function formatBinaryMask(octets) {
	return octets.map(o => o.toString(2).padStart(8, '0')).join('.')
}

/**
 * Join octets with dots.
 * @param {number[]} octets
 * @returns {string}
 */
function formatOctets(octets) {
	return octets.join('.')
}

/**
 * Format IPv6 groups as colon-separated 4-digit hex.
 * @param {number[]} groups 8 uint16 groups
 * @returns {string}
 */
function formatIPv6Groups(groups) {
	return groups.map(g => g.toString(16).padStart(4, '0')).join(':')
}

/**
 * Generate step-by-step IPv4 subnet calculation explanation.
 * @param {object} r calculateSubnet() result
 * @returns {{ label: string, formula: string, result: string }[]}
 */
export function generateIPv4Steps(r) {
	const hostBits = 32 - r.prefix
	const blockSize = Math.pow(2, hostBits)
	const steps = []

	// Step 1: Prefix breakdown
	const hostBitWord = hostBits === 1 ? '1 Host-Bit' : `${hostBits} Host-Bits`
	steps.push({
		label: 'Prefix-Aufteilung',
		formula: `/${r.prefix} => ${r.prefix} Netz-Bits + ${hostBitWord}`,
		result: `${r.prefix} Netz-Bits, ${hostBitWord}`,
	})

	// Step 2: Block size
	steps.push({
		label: 'Blockgroesse',
		formula: `2^${hostBits}`,
		result: `${blockSize}`,
	})

	// Step 3: Mask binary
	steps.push({
		label: 'Subnetzmaske',
		formula: formatBinaryMask(r.mask),
		result: formatOctets(r.mask),
	})

	// Step 4: Network = IP AND Mask
	steps.push({
		label: 'Netzadresse',
		formula: `IP AND Maske = ${formatOctets(r.ip)} AND ${formatOctets(r.mask)}`,
		result: formatOctets(r.network),
	})

	// For /32 and /31: abbreviated output
	if (r.prefix >= 32) {
		steps.push({
			label: 'Wildcard',
			formula: `NOT Maske = NOT ${formatOctets(r.mask)}`,
			result: formatOctets(r.wildcard),
		})
		steps.push({
			label: 'Hosts',
			formula: `/${r.prefix} = Einzelhost`,
			result: '0 (Einzelhost)',
		})
		return steps
	}

	// Step 5: Wildcard
	steps.push({
		label: 'Wildcard',
		formula: `NOT Maske = NOT ${formatOctets(r.mask)}`,
		result: formatOctets(r.wildcard),
	})

	// Step 6: Broadcast
	steps.push({
		label: 'Broadcast',
		formula: `Netzadresse OR Wildcard = ${formatOctets(r.network)} OR ${formatOctets(r.wildcard)}`,
		result: formatOctets(r.broadcast),
	})

	// Step 7: Usable hosts
	if (r.prefix >= 31) {
		steps.push({
			label: 'Nutzbare Hosts',
			formula: `2^${hostBits} - 2`,
			result: `0 (Point-to-Point)`,
		})
	} else {
		steps.push({
			label: 'Nutzbare Hosts',
			formula: `2^${hostBits} - 2`,
			result: `${r.hostCount}`,
		})
	}

	return steps
}

/**
 * Generate step-by-step IPv6 subnet calculation explanation.
 * @param {object} r calculateIPv6Subnet() result
 * @param {number} prefix Prefix length
 * @returns {{ label: string, formula: string, result: string }[]}
 */
export function generateIPv6Steps(r, prefix) {
	const interfaceBits = 128 - prefix
	const steps = []

	// Step 1: Prefix breakdown
	steps.push({
		label: 'Prefix-Aufteilung',
		formula: `/${prefix} => ${prefix} Prefix-Bits + ${interfaceBits} Interface-ID-Bits`,
		result: `${prefix} Prefix-Bits, ${interfaceBits} Interface-ID-Bits`,
	})

	// Step 2: Network address
	steps.push({
		label: 'Netzadresse',
		formula: 'Adresse AND Prefix-Maske',
		result: formatIPv6Groups(r.networkGroups),
	})

	// Step 3: Address range (skip for /128)
	if (prefix < 128) {
		steps.push({
			label: 'Adressbereich',
			formula: `${formatIPv6Groups(r.networkGroups)} bis ${formatIPv6Groups(r.lastHostGroups)}`,
			result: `${formatIPv6Groups(r.firstHostGroups)} - ${formatIPv6Groups(r.lastHostGroups)}`,
		})
	}

	// Step 4: Address count
	if (prefix === 128) {
		steps.push({
			label: 'Anzahl Adressen',
			formula: '2^0',
			result: '1 (Einzeladresse)',
		})
	} else {
		steps.push({
			label: 'Anzahl Adressen',
			formula: `2^${interfaceBits}`,
			result: `${r.hostCount}`,
		})
	}

	return steps
}

/**
 * Generate a "why" explanation string for a specific IPv4 result field.
 * @param {string} fieldKey One of: network, broadcast, mask, wildcard, hostCount, firstHost, lastHost, cidr
 * @param {object} r calculateSubnet() result
 * @returns {string|null} Explanation string or null for unknown keys
 */
export function generateWhyExplanation(fieldKey, r) {
	const hostBits = 32 - r.prefix

	switch (fieldKey) {
	case 'network':
		return `IP AND Maske = ${formatOctets(r.ip)} AND ${formatOctets(r.mask)} = ${formatOctets(r.network)}`

	case 'broadcast':
		return `Netzadresse OR Wildcard = ${formatOctets(r.network)} OR ${formatOctets(r.wildcard)} = ${formatOctets(r.broadcast)}`

	case 'mask':
		return `/${r.prefix} => ${r.prefix} Einsen + ${hostBits} Nullen = ${formatOctets(r.mask)}`

	case 'wildcard':
		return `NOT Maske = NOT ${formatOctets(r.mask)} = ${formatOctets(r.wildcard)}`

	case 'hostCount':
		if (r.prefix >= 31) {
			return `2^${hostBits} - 2 = 0 (keine nutzbaren Hosts)`
		}
		return `2^${hostBits} - 2 = ${r.hostCount} (Netzadresse und Broadcast abziehen)`

	case 'firstHost':
		if (!r.firstHost) return 'Keine Hosts bei diesem Prefix'
		return `Netzadresse + 1 = ${formatOctets(r.firstHost)}`

	case 'lastHost':
		if (!r.lastHost) return 'Keine Hosts bei diesem Prefix'
		return `Broadcast - 1 = ${formatOctets(r.lastHost)}`

	case 'cidr':
		return `Anzahl der fuehrenden 1-Bits in der Maske = /${r.prefix}`

	default:
		return null
	}
}
