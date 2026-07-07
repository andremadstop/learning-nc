import { describe, it, expect, afterEach, vi } from 'vitest'
import { todayStamp, storageKey } from '../../src/utils/virtuprof-storage.js'

// Charakterisierungs-Tests: fixieren das Verhalten aus VirtuProf.vue. Zero-Behavior-Change.

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('todayStamp', () => {
	it('liefert ISO-Datum YYYY-MM-DD', () => {
		expect(todayStamp()).toMatch(/^\d{4}-\d{2}-\d{2}$/)
	})
})

describe('storageKey', () => {
	it('ohne OC → uid "user"', () => {
		vi.stubGlobal('OC', undefined)
		expect(storageKey('welcome')).toBe('learning:virtuprof:daily:user:welcome')
	})
	it('mit OC-User → dessen uid', () => {
		vi.stubGlobal('OC', { getCurrentUser: () => ({ uid: 'alice' }) })
		expect(storageKey('welcome')).toBe('learning:virtuprof:daily:alice:welcome')
	})
	it('OC-User ohne uid → "user"', () => {
		vi.stubGlobal('OC', { getCurrentUser: () => ({}) })
		expect(storageKey('t')).toBe('learning:virtuprof:daily:user:t')
	})
})
