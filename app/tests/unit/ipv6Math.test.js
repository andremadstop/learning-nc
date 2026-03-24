import { describe, it, expect } from 'vitest'
import {
	expandIPv6,
	parseIPv6,
	ipv6ToBigInt,
	bigIntToGroups,
	calculateIPv6Subnet,
	ipv6AddressType,
	ipv6ToBitArray,
	formatIPv6,
} from '../../src/utils/ipv6Math.js'

describe('ipv6Math', () => {
	describe('expandIPv6', () => {
		it('expands shorthand with double colon in the middle', () => {
			expect(expandIPv6('2001:db8::1')).toBe('2001:0db8:0000:0000:0000:0000:0000:0001')
		})

		it('expands loopback ::1', () => {
			expect(expandIPv6('::1')).toBe('0000:0000:0000:0000:0000:0000:0000:0001')
		})

		it('expands full address with leading-zero omission', () => {
			expect(expandIPv6('fe80:0:0:0:0:0:0:1')).toBe('fe80:0000:0000:0000:0000:0000:0000:0001')
		})

		it('returns null for zone IDs', () => {
			expect(expandIPv6('fe80::1%eth0')).toBeNull()
		})

		it('returns null for invalid input', () => {
			expect(expandIPv6('invalid')).toBeNull()
		})

		it('returns null for empty string', () => {
			expect(expandIPv6('')).toBeNull()
		})

		it('returns null for IPv4 address', () => {
			expect(expandIPv6('192.168.1.1')).toBeNull()
		})

		it('expands :: alone to all zeros', () => {
			expect(expandIPv6('::')).toBe('0000:0000:0000:0000:0000:0000:0000:0000')
		})
	})

	describe('parseIPv6', () => {
		it('parses address with prefix', () => {
			const result = parseIPv6('2001:db8::1/48')
			expect(result).not.toBeNull()
			expect(result.prefix).toBe(48)
			expect(result.groups).toHaveLength(8)
			expect(result.groups[0]).toBe(0x2001)
			expect(result.groups[1]).toBe(0x0db8)
			expect(result.groups[7]).toBe(0x0001)
		})

		it('returns null for IPv4 input', () => {
			expect(parseIPv6('192.168.1.1/24')).toBeNull()
		})

		it('returns null for prefix > 128', () => {
			expect(parseIPv6('2001:db8::1/129')).toBeNull()
		})

		it('returns null for negative prefix', () => {
			expect(parseIPv6('2001:db8::1/-1')).toBeNull()
		})

		it('returns null for missing prefix', () => {
			expect(parseIPv6('2001:db8::1')).toBeNull()
		})
	})

	describe('ipv6ToBigInt / bigIntToGroups roundtrip', () => {
		it('converts groups to BigInt and back', () => {
			const groups = [0x2001, 0x0db8, 0, 0, 0, 0, 0, 1]
			const bigint = ipv6ToBigInt(groups)
			expect(typeof bigint).toBe('bigint')
			const back = bigIntToGroups(bigint)
			expect(Array.from(back)).toEqual(groups)
		})

		it('handles all-zeros', () => {
			const groups = [0, 0, 0, 0, 0, 0, 0, 0]
			expect(Array.from(bigIntToGroups(ipv6ToBigInt(groups)))).toEqual(groups)
		})

		it('handles all-ones (ffff:ffff:...)', () => {
			const groups = [0xffff, 0xffff, 0xffff, 0xffff, 0xffff, 0xffff, 0xffff, 0xffff]
			expect(Array.from(bigIntToGroups(ipv6ToBigInt(groups)))).toEqual(groups)
		})
	})

	describe('calculateIPv6Subnet', () => {
		it('calculates /48 subnet correctly', () => {
			const result = calculateIPv6Subnet('2001:0db8:abcd:1234:0000:0000:0000:0001', 48)
			expect(result).not.toBeNull()
			expect(result.networkGroups[0]).toBe(0x2001)
			expect(result.networkGroups[1]).toBe(0x0db8)
			expect(result.networkGroups[2]).toBe(0xabcd)
			expect(result.networkGroups[3]).toBe(0x0000)
			expect(result.prefix).toBe(48)
			expect(result.hostCount).toBe((1n << 80n) - 2n)
		})

		it('calculates /128 (single host)', () => {
			const result = calculateIPv6Subnet('::1', 128)
			expect(result).not.toBeNull()
			expect(result.hostCount).toBe(0n)
		})

		it('returns null for invalid address', () => {
			expect(calculateIPv6Subnet('invalid', 48)).toBeNull()
		})
	})

	describe('ipv6AddressType', () => {
		it('detects Link-Local', () => {
			expect(ipv6AddressType([0xfe80, 0, 0, 0, 0, 0, 0, 1])).toBe('Link-Local')
		})

		it('detects Global Unicast', () => {
			expect(ipv6AddressType([0x2001, 0x0db8, 0, 0, 0, 0, 0, 1])).toBe('Global Unicast')
		})

		it('detects Multicast', () => {
			expect(ipv6AddressType([0xff02, 0, 0, 0, 0, 0, 0, 1])).toBe('Multicast')
		})

		it('detects Loopback', () => {
			expect(ipv6AddressType([0, 0, 0, 0, 0, 0, 0, 1])).toBe('Loopback')
		})

		it('detects Unique Local', () => {
			expect(ipv6AddressType([0xfc00, 0, 0, 0, 0, 0, 0, 1])).toBe('Unique Local')
		})

		it('detects Unspecified (::)', () => {
			expect(ipv6AddressType([0, 0, 0, 0, 0, 0, 0, 0])).toBe('Unspecified')
		})
	})

	describe('ipv6ToBitArray', () => {
		it('returns 128-element array', () => {
			const bits = ipv6ToBitArray([0, 0, 0, 0, 0, 0, 0, 0])
			expect(bits).toHaveLength(128)
			expect(bits.every(b => b === 0)).toBe(true)
		})

		it('has correct bits for ::1', () => {
			const bits = ipv6ToBitArray([0, 0, 0, 0, 0, 0, 0, 1])
			expect(bits[127]).toBe(1)
			expect(bits[126]).toBe(0)
		})

		it('has correct bits for ffff::...', () => {
			const bits = ipv6ToBitArray([0xffff, 0, 0, 0, 0, 0, 0, 0])
			expect(bits.slice(0, 16).every(b => b === 1)).toBe(true)
			expect(bits.slice(16).every(b => b === 0)).toBe(true)
		})
	})

	describe('formatIPv6', () => {
		it('formats groups to full colon-hex string', () => {
			expect(formatIPv6([0x2001, 0x0db8, 0, 0, 0, 0, 0, 1]))
				.toBe('2001:0db8:0000:0000:0000:0000:0000:0001')
		})

		it('formats all-zeros', () => {
			expect(formatIPv6([0, 0, 0, 0, 0, 0, 0, 0]))
				.toBe('0000:0000:0000:0000:0000:0000:0000:0000')
		})
	})
})
