import { describe, it, expect } from 'vitest'
import { ROW_KEYS, PRESETS, getVisibleRows } from '../../src/utils/togglePresets.js'

describe('togglePresets', () => {
	describe('ROW_KEYS', () => {
		it('contains exactly 10 keys in correct order', () => {
			expect(ROW_KEYS).toEqual([
				'network', 'broadcast', 'firstHost', 'lastHost', 'hostCount',
				'mask', 'wildcard', 'cidr', 'ipClass', 'isPrivate',
			])
		})
	})

	describe('PRESETS', () => {
		it('has 4 presets: all, beginner, advanced, basics', () => {
			expect(Object.keys(PRESETS).sort()).toEqual(['advanced', 'all', 'basics', 'beginner'])
		})

		it('all preset includes all 10 keys', () => {
			expect(PRESETS.all).toEqual(ROW_KEYS)
		})

		it('beginner preset includes only network, mask, cidr, hostCount', () => {
			expect(PRESETS.beginner.sort()).toEqual(['cidr', 'hostCount', 'mask', 'network'])
		})

		it('advanced preset includes all except ipClass, isPrivate, wildcard', () => {
			expect(PRESETS.advanced.sort()).toEqual([
				'broadcast', 'cidr', 'firstHost', 'hostCount', 'lastHost', 'mask', 'network',
			])
		})

		it('basics preset includes network, broadcast, mask, cidr, hostCount', () => {
			expect(PRESETS.basics.sort()).toEqual(['broadcast', 'cidr', 'hostCount', 'mask', 'network'])
		})

		it('every preset only contains keys from ROW_KEYS', () => {
			for (const [name, keys] of Object.entries(PRESETS)) {
				for (const key of keys) {
					expect(ROW_KEYS, `preset "${name}" has invalid key "${key}"`).toContain(key)
				}
			}
		})
	})

	describe('getVisibleRows', () => {
		it('returns an object with all 10 ROW_KEYS as keys', () => {
			const result = getVisibleRows('all')
			expect(Object.keys(result).sort()).toEqual([...ROW_KEYS].sort())
		})

		it('all preset returns all keys as true', () => {
			const result = getVisibleRows('all')
			for (const key of ROW_KEYS) {
				expect(result[key], `key "${key}" should be true`).toBe(true)
			}
		})

		it('beginner preset returns only 4 keys as true', () => {
			const result = getVisibleRows('beginner')
			const trueKeys = Object.entries(result).filter(([, v]) => v).map(([k]) => k)
			expect(trueKeys.sort()).toEqual(['cidr', 'hostCount', 'mask', 'network'])
		})

		it('beginner preset returns remaining 6 keys as false', () => {
			const result = getVisibleRows('beginner')
			expect(result.broadcast).toBe(false)
			expect(result.firstHost).toBe(false)
			expect(result.lastHost).toBe(false)
			expect(result.wildcard).toBe(false)
			expect(result.ipClass).toBe(false)
			expect(result.isPrivate).toBe(false)
		})

		it('advanced preset returns 7 keys as true', () => {
			const result = getVisibleRows('advanced')
			const trueKeys = Object.entries(result).filter(([, v]) => v).map(([k]) => k)
			expect(trueKeys).toHaveLength(7)
			expect(result.ipClass).toBe(false)
			expect(result.isPrivate).toBe(false)
			expect(result.wildcard).toBe(false)
		})

		it('basics preset returns 5 keys as true', () => {
			const result = getVisibleRows('basics')
			const trueKeys = Object.entries(result).filter(([, v]) => v).map(([k]) => k)
			expect(trueKeys.sort()).toEqual(['broadcast', 'cidr', 'hostCount', 'mask', 'network'])
		})

		it('defaults to all when preset is undefined', () => {
			const result = getVisibleRows(undefined)
			for (const key of ROW_KEYS) {
				expect(result[key]).toBe(true)
			}
		})

		it('defaults to all when preset is unknown', () => {
			const result = getVisibleRows('nonexistent')
			for (const key of ROW_KEYS) {
				expect(result[key]).toBe(true)
			}
		})
	})
})
