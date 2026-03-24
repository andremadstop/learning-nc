import { describe, it, expect } from 'vitest'
import {
	generateIPv4Steps,
	generateIPv6Steps,
	generateWhyExplanation,
} from '../../src/utils/subnetExplainer.js'

describe('generateIPv4Steps', () => {
	it('returns correct steps for /24', () => {
		const result = {
			ip: [192, 168, 1, 100],
			network: [192, 168, 1, 0],
			broadcast: [192, 168, 1, 255],
			firstHost: [192, 168, 1, 1],
			lastHost: [192, 168, 1, 254],
			hostCount: 254,
			mask: [255, 255, 255, 0],
			wildcard: [0, 0, 0, 255],
			prefix: 24,
			networkBits: [],
			ipBits: [],
			ipClass: 'C',
			isPrivate: true,
		}

		const steps = generateIPv4Steps(result)
		expect(steps).toHaveLength(7)

		// Step 1: prefix info
		expect(steps[0].label).toContain('Prefix')
		expect(steps[0].result).toContain('24')
		expect(steps[0].result).toContain('8')

		// Step 2: block size
		expect(steps[1].formula).toContain('2^8')
		expect(steps[1].result).toContain('256')

		// Step 3: mask binary
		expect(steps[2].result).toContain('255.255.255.0')
		expect(steps[2].formula).toContain('11111111')

		// Step 4: network = IP AND mask
		expect(steps[3].formula).toContain('AND')
		expect(steps[3].result).toContain('192.168.1.0')

		// Step 5: wildcard
		expect(steps[4].formula).toContain('NOT')
		expect(steps[4].result).toContain('0.0.0.255')

		// Step 6: broadcast
		expect(steps[5].formula).toContain('OR')
		expect(steps[5].result).toContain('192.168.1.255')

		// Step 7: host count
		expect(steps[6].formula).toContain('2^8')
		expect(steps[6].result).toContain('254')
	})

	it('returns correct steps for /25', () => {
		const result = {
			ip: [10, 0, 0, 1],
			network: [10, 0, 0, 0],
			broadcast: [10, 0, 0, 127],
			firstHost: [10, 0, 0, 1],
			lastHost: [10, 0, 0, 126],
			hostCount: 126,
			mask: [255, 255, 255, 128],
			wildcard: [0, 0, 0, 127],
			prefix: 25,
			networkBits: [],
			ipBits: [],
			ipClass: 'A',
			isPrivate: true,
		}

		const steps = generateIPv4Steps(result)
		expect(steps).toHaveLength(8)
		expect(steps[0].result).toContain('7 Host-Bits')
		expect(steps[1].result).toContain('128')
		// Step 3 is binary breakdown of the interesting octet
		expect(steps[3].label).toBe('Binär-Rechnung')
		expect(steps[3].formula).toContain('1 Netz')
		expect(steps[3].formula).toContain('7 Host')
		expect(steps[7].result).toContain('126')
	})

	it('returns correct steps for /32 (single host)', () => {
		const result = {
			ip: [10, 0, 0, 1],
			network: [10, 0, 0, 1],
			broadcast: [10, 0, 0, 1],
			firstHost: null,
			lastHost: null,
			hostCount: 0,
			mask: [255, 255, 255, 255],
			wildcard: [0, 0, 0, 0],
			prefix: 32,
			networkBits: [],
			ipBits: [],
			ipClass: 'A',
			isPrivate: true,
		}

		const steps = generateIPv4Steps(result)
		// /32 has fewer steps (no broadcast formula, no host count formula)
		expect(steps.length).toBeGreaterThanOrEqual(4)
		expect(steps[0].result).toContain('0 Host-Bits')
		// Should mention single host or no hosts
		const lastStep = steps[steps.length - 1]
		expect(lastStep.result).toMatch(/0|Einzelhost/)
	})

	it('returns correct steps for /31 (point-to-point)', () => {
		const result = {
			ip: [10, 0, 0, 0],
			network: [10, 0, 0, 0],
			broadcast: [10, 0, 0, 1],
			firstHost: null,
			lastHost: null,
			hostCount: 0,
			mask: [255, 255, 255, 254],
			wildcard: [0, 0, 0, 1],
			prefix: 31,
			networkBits: [],
			ipBits: [],
			ipClass: 'A',
			isPrivate: true,
		}

		const steps = generateIPv4Steps(result)
		expect(steps.length).toBeGreaterThanOrEqual(4)
		expect(steps[0].result).toContain('1 Host-Bit')
	})

	it('returns correct steps for /16', () => {
		const result = {
			ip: [172, 16, 5, 10],
			network: [172, 16, 0, 0],
			broadcast: [172, 16, 255, 255],
			firstHost: [172, 16, 0, 1],
			lastHost: [172, 16, 255, 254],
			hostCount: 65534,
			mask: [255, 255, 0, 0],
			wildcard: [0, 0, 255, 255],
			prefix: 16,
			networkBits: [],
			ipBits: [],
			ipClass: 'B',
			isPrivate: true,
		}

		const steps = generateIPv4Steps(result)
		expect(steps).toHaveLength(7)
		expect(steps[0].result).toContain('16 Host-Bits')
		expect(steps[1].result).toContain('65536')
		expect(steps[6].result).toContain('65534')
	})

	it('each step has label, formula, result', () => {
		const result = {
			ip: [192, 168, 1, 100],
			network: [192, 168, 1, 0],
			broadcast: [192, 168, 1, 255],
			firstHost: [192, 168, 1, 1],
			lastHost: [192, 168, 1, 254],
			hostCount: 254,
			mask: [255, 255, 255, 0],
			wildcard: [0, 0, 0, 255],
			prefix: 24,
			networkBits: [],
			ipBits: [],
			ipClass: 'C',
			isPrivate: true,
		}

		const steps = generateIPv4Steps(result)
		for (const step of steps) {
			expect(step).toHaveProperty('label')
			expect(step).toHaveProperty('formula')
			expect(step).toHaveProperty('result')
			expect(typeof step.label).toBe('string')
			expect(typeof step.formula).toBe('string')
			expect(typeof step.result).toBe('string')
		}
	})
})

describe('generateIPv6Steps', () => {
	it('returns correct steps for /48', () => {
		const ipv6Result = {
			network: 0n,
			networkGroups: [0x2001, 0x0db8, 0x0000, 0x0000, 0x0000, 0x0000, 0x0000, 0x0000],
			firstHost: 1n,
			firstHostGroups: [0x2001, 0x0db8, 0x0000, 0x0000, 0x0000, 0x0000, 0x0000, 0x0001],
			lastHost: 0n,
			lastHostGroups: [0x2001, 0x0db8, 0xffff, 0xffff, 0xffff, 0xffff, 0xffff, 0xfffe],
			hostCount: 1208925819614629174706174n,
			prefix: 48,
			groups: [0x2001, 0x0db8, 0x0000, 0x0000, 0x0000, 0x0000, 0x0000, 0x0001],
		}

		const steps = generateIPv6Steps(ipv6Result, 48)
		expect(steps.length).toBeGreaterThanOrEqual(3)

		expect(steps[0].result).toContain('80')
		expect(steps[0].label).toContain('Prefix')
	})

	it('returns correct steps for /64', () => {
		const ipv6Result = {
			network: 0n,
			networkGroups: [0xfe80, 0, 0, 0, 0, 0, 0, 0],
			firstHost: 1n,
			firstHostGroups: [0xfe80, 0, 0, 0, 0, 0, 0, 1],
			lastHost: 0n,
			lastHostGroups: [0xfe80, 0, 0, 0, 0xffff, 0xffff, 0xffff, 0xfffe],
			hostCount: 18446744073709551614n,
			prefix: 64,
			groups: [0xfe80, 0, 0, 0, 0, 0, 0, 1],
		}

		const steps = generateIPv6Steps(ipv6Result, 64)
		expect(steps.length).toBeGreaterThanOrEqual(3)
		expect(steps[0].result).toContain('64 Interface-ID-Bits')
	})

	it('returns correct steps for /128 (single host)', () => {
		const ipv6Result = {
			network: 0n,
			networkGroups: [0, 0, 0, 0, 0, 0, 0, 1],
			firstHost: 0n,
			firstHostGroups: [0, 0, 0, 0, 0, 0, 0, 1],
			lastHost: 0n,
			lastHostGroups: [0, 0, 0, 0, 0, 0, 0, 1],
			hostCount: 0n,
			prefix: 128,
			groups: [0, 0, 0, 0, 0, 0, 0, 1],
		}

		const steps = generateIPv6Steps(ipv6Result, 128)
		expect(steps.length).toBeGreaterThanOrEqual(2)
		expect(steps[0].result).toContain('0 Interface-ID-Bits')
	})

	it('each step has label, formula, result', () => {
		const ipv6Result = {
			network: 0n,
			networkGroups: [0x2001, 0x0db8, 0, 0, 0, 0, 0, 0],
			firstHost: 1n,
			firstHostGroups: [0x2001, 0x0db8, 0, 0, 0, 0, 0, 1],
			lastHost: 0n,
			lastHostGroups: [0x2001, 0x0db8, 0xffff, 0xffff, 0xffff, 0xffff, 0xffff, 0xfffe],
			hostCount: 1208925819614629174706174n,
			prefix: 48,
			groups: [0x2001, 0x0db8, 0, 0, 0, 0, 0, 1],
		}

		const steps = generateIPv6Steps(ipv6Result, 48)
		for (const step of steps) {
			expect(step).toHaveProperty('label')
			expect(step).toHaveProperty('formula')
			expect(step).toHaveProperty('result')
		}
	})
})

describe('generateWhyExplanation', () => {
	const result = {
		ip: [192, 168, 1, 100],
		network: [192, 168, 1, 0],
		broadcast: [192, 168, 1, 255],
		firstHost: [192, 168, 1, 1],
		lastHost: [192, 168, 1, 254],
		hostCount: 254,
		mask: [255, 255, 255, 0],
		wildcard: [0, 0, 0, 255],
		prefix: 24,
		networkBits: [],
		ipBits: [],
		ipClass: 'C',
		isPrivate: true,
	}

	it('explains network field', () => {
		const why = generateWhyExplanation('network', result)
		expect(why).toContain('AND')
		expect(why).toContain('192.168.1.0')
	})

	it('explains broadcast field', () => {
		const why = generateWhyExplanation('broadcast', result)
		expect(why).toContain('OR')
		expect(why).toContain('192.168.1.255')
	})

	it('explains mask field', () => {
		const why = generateWhyExplanation('mask', result)
		expect(why).toContain('/24')
		expect(why).toContain('255.255.255.0')
	})

	it('explains wildcard field', () => {
		const why = generateWhyExplanation('wildcard', result)
		expect(why).toContain('NOT')
		expect(why).toContain('0.0.0.255')
	})

	it('explains hostCount field', () => {
		const why = generateWhyExplanation('hostCount', result)
		expect(why).toContain('254')
		expect(why).toContain('2^8')
	})

	it('explains firstHost field', () => {
		const why = generateWhyExplanation('firstHost', result)
		expect(why).toContain('+ 1')
		expect(why).toContain('192.168.1.1')
	})

	it('explains lastHost field', () => {
		const why = generateWhyExplanation('lastHost', result)
		expect(why).toContain('- 1')
		expect(why).toContain('192.168.1.254')
	})

	it('explains cidr field', () => {
		const why = generateWhyExplanation('cidr', result)
		expect(why).toContain('/24')
		expect(why).toContain('1-Bits')
	})

	it('returns null for unknown field', () => {
		const why = generateWhyExplanation('unknown', result)
		expect(why).toBeNull()
	})
})
