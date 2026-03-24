const TOTAL_BITS = 32
const OCTET_COUNT = 4

function isInteger(value) {
	return Number.isInteger(value)
}

function isValidOctet(value) {
	return isInteger(value) && value >= 0 && value <= 255
}

function cloneOctets(octets) {
	return octets.slice(0, OCTET_COUNT)
}

function normalizeOctets(value) {
	if (Array.isArray(value) && value.length === OCTET_COUNT && value.every(isValidOctet)) {
		return cloneOctets(value)
	}

	if (typeof value === 'string') {
		return parseOctets(value)
	}

	return null
}

function parseOctets(input) {
	if (typeof input !== 'string') return null
	const parts = input.trim().split('.')
	if (parts.length !== OCTET_COUNT) return null

	const octets = parts.map(part => {
		if (!/^\d+$/.test(part)) return NaN
		return Number(part)
	})

	return octets.every(isValidOctet) ? octets : null
}

function octetsToInt(octets) {
	return (
		((octets[0] << 24) >>> 0) |
		(octets[1] << 16) |
		(octets[2] << 8) |
		octets[3]
	) >>> 0
}

function intToOctets(value) {
	return [
		(value >>> 24) & 255,
		(value >>> 16) & 255,
		(value >>> 8) & 255,
		value & 255,
	]
}

function isValidPrefix(prefix) {
	return isInteger(prefix) && prefix >= 0 && prefix <= TOTAL_BITS
}

function maskIntFromPrefix(prefix) {
	if (prefix === 0) return 0
	return (0xffffffff << (TOTAL_BITS - prefix)) >>> 0
}

function bitArrayFromInt(value) {
	const bits = []

	for (let index = 0; index < TOTAL_BITS; index++) {
		const shift = TOTAL_BITS - index - 1
		bits.push((value >>> shift) & 1)
	}

	return bits
}

function hostCapacity(prefix) {
	const hostBits = TOTAL_BITS - prefix
	if (hostBits <= 1) return 0
	return Math.pow(2, hostBits) - 2
}

function ipClassFromOctets(octets) {
	const first = octets[0]

	if (first <= 127) return 'A'
	if (first <= 191) return 'B'
	if (first <= 223) return 'C'
	if (first <= 239) return 'D'
	return 'E'
}

function isPrivateRange(octets) {
	const first = octets[0]
	const second = octets[1]

	return (
		first === 10 ||
		(first === 172 && second >= 16 && second <= 31) ||
		(first === 192 && second === 168)
	)
}

function smallestBlockSizeForHosts(hosts) {
	let size = 1
	const needed = Math.max(0, hosts) + 2

	while (size < needed) {
		size *= 2
	}

	return size
}

function alignAddress(address, blockSize) {
	const remainder = address % blockSize
	if (remainder === 0) return address
	return address + (blockSize - remainder)
}

function sanitizeRequirement(requirement, index) {
	if (!requirement || typeof requirement !== 'object') return null

	const hosts = Number(requirement.hosts)
	if (!Number.isFinite(hosts) || hosts <= 0) return null

	const name = String(requirement.name || '').trim() || `Subnetz ${index + 1}`

	return {
		name,
		hosts: Math.floor(hosts),
	}
}

export function ipToString(octets) {
	const normalized = normalizeOctets(octets)
	return normalized ? normalized.join('.') : ''
}

export function prefixToMask(prefix) {
	if (!isValidPrefix(prefix)) return null
	return intToOctets(maskIntFromPrefix(prefix))
}

export function maskToPrefix(mask) {
	const normalized = normalizeOctets(mask)
	if (!normalized) return null

	const bits = normalized
		.map(octet => octet.toString(2).padStart(8, '0'))
		.join('')

	let prefix = 0
	let sawZero = false

	for (let index = 0; index < bits.length; index++) {
		if (bits[index] === '1') {
			if (sawZero) return null
			prefix++
		} else {
			sawZero = true
		}
	}

	return prefix
}

export function parseInput(input) {
	if (typeof input !== 'string') return null

	const value = input.trim()
	if (!value) return null

	const normalized = value
		.replace(/\s*\/\s*/g, '/')
		.replace(/\s+/g, ' ')

	if (normalized.includes('/')) {
		const parts = normalized.split('/')
		if (parts.length !== 2) return null

		const ip = parseOctets(parts[0])
		const prefix = Number(parts[1])

		if (!ip || !isValidPrefix(prefix)) return null

		return {
			ip,
			prefix,
			mask: prefixToMask(prefix),
		}
	}

	const parts = normalized.split(' ')
	if (parts.length !== 2) return null

	const ip = parseOctets(parts[0])
	const mask = parseOctets(parts[1])
	if (!ip || !mask) return null

	const prefix = maskToPrefix(mask)
	if (prefix === null) return null

	return {
		ip,
		prefix,
		mask,
	}
}

export function calculateSubnet(ip, prefix) {
	const normalizedIp = normalizeOctets(ip)
	if (!normalizedIp || !isValidPrefix(prefix)) return null

	const ipInt = octetsToInt(normalizedIp)
	const mask = prefixToMask(prefix)
	if (!mask) return null

	const maskInt = octetsToInt(mask)
	const networkInt = (ipInt & maskInt) >>> 0
	const broadcastInt = (networkInt | (~maskInt >>> 0)) >>> 0
	const hostCount = hostCapacity(prefix)

	return {
		ip: cloneOctets(normalizedIp),
		network: intToOctets(networkInt),
		broadcast: intToOctets(broadcastInt),
		firstHost: hostCount > 0 ? intToOctets((networkInt + 1) >>> 0) : null,
		lastHost: hostCount > 0 ? intToOctets((broadcastInt - 1) >>> 0) : null,
		hostCount,
		mask,
		wildcard: intToOctets((~maskInt) >>> 0),
		prefix,
		networkBits: bitArrayFromInt(maskInt),
		ipBits: bitArrayFromInt(ipInt),
		ipClass: ipClassFromOctets(normalizedIp),
		isPrivate: isPrivateRange(normalizedIp),
	}
}

export function splitSubnet(network, prefix, count) {
	const base = calculateSubnet(network, prefix)
	if (!base || !isInteger(count) || count <= 0) return []

	const bitsNeeded = Math.log(count) / Math.log(2)
	if (!Number.isInteger(bitsNeeded)) return []

	const nextPrefix = prefix + bitsNeeded
	if (nextPrefix > TOTAL_BITS) return []

	const blockSize = Math.pow(2, TOTAL_BITS - nextPrefix)
	const start = octetsToInt(base.network)
	const subnets = []

	for (let index = 0; index < count; index++) {
		const subnetAddress = intToOctets((start + index * blockSize) >>> 0)
		subnets.push(calculateSubnet(subnetAddress, nextPrefix))
	}

	return subnets.filter(Boolean)
}

export function vlsmAllocate(network, prefix, requirements) {
	const base = calculateSubnet(network, prefix)
	if (!base || !Array.isArray(requirements)) return null

	const normalizedRequirements = requirements
		.map((requirement, index) => sanitizeRequirement(requirement, index))
		.filter(Boolean)
		.sort((left, right) => right.hosts - left.hosts)

	if (!normalizedRequirements.length) return []

	let cursor = octetsToInt(base.network)
	const limit = octetsToInt(base.broadcast)
	const allocations = []

	for (let index = 0; index < normalizedRequirements.length; index++) {
		const requirement = normalizedRequirements[index]
		const blockSize = smallestBlockSizeForHosts(requirement.hosts)
		const subnetPrefix = TOTAL_BITS - (Math.log(blockSize) / Math.log(2))
		const alignedCursor = alignAddress(cursor, blockSize)
		const subnetEnd = alignedCursor + blockSize - 1

		if (subnetPrefix < prefix || subnetEnd > limit) {
			return null
		}

		const subnet = calculateSubnet(intToOctets(alignedCursor >>> 0), subnetPrefix)
		if (!subnet) return null

		allocations.push({
			name: requirement.name,
			requestedHosts: requirement.hosts,
			wasted: Math.max(0, subnet.hostCount - requirement.hosts),
			...subnet,
		})

		cursor = subnetEnd + 1
	}

	return allocations
}
