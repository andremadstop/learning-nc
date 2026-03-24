import { describe, expect, it } from 'vitest'
import { SCENARIOS, SCENARIO_SPECS } from '../../src/utils/scenarios.js'
import {
	calculateSubnet,
	ipToString,
	parseInput,
	prefixToMask,
	vlsmAllocate,
} from '../../src/utils/subnetMath.js'
import {
	calculateIPv6Subnet,
	formatIPv6,
	ipv6AddressType,
	parseIPv6,
} from '../../src/utils/ipv6Math.js'

function usableHostCount(prefix) {
	if (prefix >= 31) return 0
	return Math.pow(2, 32 - prefix) - 2
}

function smallestPrefixForHosts(hosts) {
	for (let prefix = 32; prefix >= 0; prefix--) {
		if (usableHostCount(prefix) >= hosts) return prefix
	}
	return 0
}

function blockSizeForHosts(hosts) {
	let size = 1
	const needed = Number(hosts) + 2
	while (size < needed) size *= 2
	return size
}

function deriveExpectedAnswers(spec) {
	if (spec.kind === 'subnetFromCidr') {
		const parsed = parseInput(spec.input)
		const result = calculateSubnet(parsed.ip, parsed.prefix)
		return pickSubnetAnswers(result, spec.fields)
	}

	if (spec.kind === 'hostSizing') {
		const prefix = smallestPrefixForHosts(spec.requiredHosts)
		const result = calculateSubnet(spec.baseIp, prefix)
		return pickSubnetAnswers(result, spec.fields)
	}

	if (spec.kind === 'vlsmSizing' || spec.kind === 'vlsmFit') {
		const parsed = parseInput(spec.supernet)
		const base = calculateSubnet(parsed.ip, parsed.prefix)
		const allocations = vlsmAllocate(base.network, base.prefix, spec.requirements)
		const largestHosts = Math.max(...spec.requirements.map((requirement) => requirement.hosts))
		const largestPrefix = smallestPrefixForHosts(largestHosts)
		const totalUsed = spec.requirements.reduce((sum, requirement) => sum + blockSizeForHosts(requirement.hosts), 0)

		const values = {
			cidr: `/${largestPrefix}`,
			subnetMask: ipToString(prefixToMask(largestPrefix)),
			hostCount: String(usableHostCount(largestPrefix)),
			fitsInSupernet: allocations ? 'Ja' : 'Nein',
			totalUsed: String(totalUsed),
		}

		return spec.fields.reduce((acc, field) => {
			acc[field] = values[field]
			return acc
		}, {})
	}

	if (spec.kind === 'ipv6SubnetCount') {
		return {
			subnetCount: (1n << BigInt(spec.targetPrefix - spec.basePrefix)).toString(),
		}
	}

	if (spec.kind === 'ipv6Network' || spec.kind === 'ipv6Type') {
		const parsed = parseIPv6(spec.input)
		const address = parsed.groups.map((group) => group.toString(16)).join(':')
		const result = calculateIPv6Subnet(address, parsed.prefix)
		const values = {
			networkAddress: formatIPv6(result.networkGroups),
			addressType: ipv6AddressType(parsed.groups),
		}

		return spec.fields.reduce((acc, field) => {
			acc[field] = values[field]
			return acc
		}, {})
	}

	throw new Error(`Unsupported scenario kind in test: ${spec.kind}`)
}

function pickSubnetAnswers(result, fields) {
	const values = {
		networkAddress: ipToString(result.network),
		broadcast: ipToString(result.broadcast),
		firstHost: ipToString(result.firstHost),
		lastHost: ipToString(result.lastHost),
		hostCount: String(result.hostCount),
		subnetMask: ipToString(result.mask),
		cidr: `/${result.prefix}`,
		maxServers: String(Math.max(result.hostCount - 1, 0)),
	}

	return fields.reduce((acc, field) => {
		acc[field] = values[field]
		return acc
	}, {})
}

describe('SCENARIOS', () => {
	it('exports one runtime scenario per spec', () => {
		expect(SCENARIOS).toHaveLength(SCENARIO_SPECS.length)
		expect(SCENARIOS.length).toBeGreaterThanOrEqual(30)
	})

	it('uses unique ids and valid enum values', () => {
		const ids = new Set()
		const validTypes = new Set(['subnet', 'vlsm', 'praxis', 'ipv6'])
		const validDifficulty = new Set(['easy', 'medium', 'hard'])

		for (const scenario of SCENARIOS) {
			expect(ids.has(scenario.id)).toBe(false)
			ids.add(scenario.id)

			expect(validTypes.has(scenario.type)).toBe(true)
			expect(validDifficulty.has(scenario.difficulty)).toBe(true)
			expect(typeof scenario.question).toBe('string')
			expect(Object.keys(scenario.expectedAnswers).length).toBeGreaterThan(0)
		}
	})

	it('meets the minimum per-type counts and difficulty split', () => {
		const countsByType = SCENARIOS.reduce((acc, scenario) => {
			acc[scenario.type] = (acc[scenario.type] || 0) + 1
			return acc
		}, {})

		expect(countsByType.subnet).toBeGreaterThanOrEqual(15)
		expect(countsByType.vlsm).toBeGreaterThanOrEqual(5)
		expect(countsByType.praxis).toBeGreaterThanOrEqual(5)
		expect(countsByType.ipv6).toBeGreaterThanOrEqual(5)

		const difficultyCount = (type, difficulty) => SCENARIOS.filter((scenario) => scenario.type === type && scenario.difficulty === difficulty).length

		expect(difficultyCount('subnet', 'easy')).toBeGreaterThanOrEqual(5)
		expect(difficultyCount('subnet', 'medium')).toBeGreaterThanOrEqual(5)
		expect(difficultyCount('subnet', 'hard')).toBeGreaterThanOrEqual(5)

		expect(difficultyCount('vlsm', 'easy')).toBeGreaterThanOrEqual(2)
		expect(difficultyCount('vlsm', 'medium')).toBeGreaterThanOrEqual(2)
		expect(difficultyCount('vlsm', 'hard')).toBeGreaterThanOrEqual(1)

		expect(difficultyCount('praxis', 'easy')).toBeGreaterThanOrEqual(1)
		expect(difficultyCount('praxis', 'medium')).toBeGreaterThanOrEqual(2)
		expect(difficultyCount('praxis', 'hard')).toBeGreaterThanOrEqual(2)

		expect(difficultyCount('ipv6', 'easy')).toBeGreaterThanOrEqual(2)
		expect(difficultyCount('ipv6', 'medium')).toBeGreaterThanOrEqual(2)
		expect(difficultyCount('ipv6', 'hard')).toBeGreaterThanOrEqual(1)
	})

	it('contains all user-mandated scenarios by id', () => {
		[
			'praxis-medium-01',
			'praxis-medium-02',
			'subnet-medium-01',
			'subnet-medium-02',
			'subnet-medium-03',
			'praxis-hard-01',
			'praxis-hard-02',
			'praxis-hard-03',
			'vlsm-hard-01',
			'ipv6-hard-01',
			'vlsm-hard-02',
		].forEach((id) => {
			expect(SCENARIOS.some((scenario) => scenario.id === id)).toBe(true)
		})
	})

	it('mathematically verifies every expectedAnswers payload', () => {
		for (const spec of SCENARIO_SPECS) {
			const runtimeScenario = SCENARIOS.find((scenario) => scenario.id === spec.id)
			expect(runtimeScenario).toBeTruthy()
			expect(runtimeScenario.expectedAnswers).toEqual(deriveExpectedAnswers(spec))
		}
	})
})
