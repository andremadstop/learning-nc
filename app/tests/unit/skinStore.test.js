import { describe, it, expect, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useSkinStore } from '../../src/stores/skinStore.js'

describe('skinStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('defaults skinId to "nova" (Zero-Change-Default per MIGR-01 + CLASSIC-04 read-path)', () => {
		const store = useSkinStore()
		expect(store.skinId).toBe('nova')
	})

	it('setSkin("prof_lern_classic") updates skinId', () => {
		const store = useSkinStore()
		store.setSkin('prof_lern_classic')
		expect(store.skinId).toBe('prof_lern_classic')
	})

	it('setSkin("architect") updates skinId (campaign characters are valid skins)', () => {
		const store = useSkinStore()
		store.setSkin('architect')
		expect(store.skinId).toBe('architect')
	})

	it('PICK-05 setSkin coerces invalid id to "nova" without throwing', () => {
		const store = useSkinStore()
		expect(() => store.setSkin('__nope__')).not.toThrow()
		expect(store.skinId).toBe('nova')
	})

	it('PICK-05 setSkin coerces null/undefined/non-string to "nova"', () => {
		const store = useSkinStore()
		store.setSkin(null)
		expect(store.skinId).toBe('nova')
		store.setSkin(undefined)
		expect(store.skinId).toBe('nova')
		store.setSkin(42)
		expect(store.skinId).toBe('nova')
	})

	it('loadFromServerPayload sets state from {skin:"prof_lern_classic"}', () => {
		const store = useSkinStore()
		store.loadFromServerPayload({ skin: 'prof_lern_classic' })
		expect(store.skinId).toBe('prof_lern_classic')
	})

	it('CLASSIC-04 loadFromServerPayload leaves default "nova" when payload missing skin key (read-path only in 151)', () => {
		const store = useSkinStore()
		store.loadFromServerPayload({})
		expect(store.skinId).toBe('nova')
	})

	it('loadFromServerPayload is safe with null/undefined', () => {
		const store = useSkinStore()
		expect(() => store.loadFromServerPayload(null)).not.toThrow()
		expect(() => store.loadFromServerPayload(undefined)).not.toThrow()
		expect(store.skinId).toBe('nova')
	})

	it('META-03 availableSkins getter returns only entries with user_selectable === true', () => {
		const store = useSkinStore()
		const list = store.availableSkins
		expect(Array.isArray(list)).toBe(true)
		const ids = list.map(c => c.id)
		expect(ids).toContain('nova')
		expect(ids).not.toContain('architect')
	})
})
