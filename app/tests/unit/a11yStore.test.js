import { describe, it, expect, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useA11yStore } from '../../src/stores/a11yStore.js'

describe('a11yStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('defaults animationsEnabled to true', () => {
		const store = useA11yStore()
		expect(store.animationsEnabled).toBe(true)
	})

	it('setEnabled(false) disables animations', () => {
		const store = useA11yStore()
		store.setEnabled(false)
		expect(store.animationsEnabled).toBe(false)
	})

	it('setEnabled coerces truthy/falsy values', () => {
		const store = useA11yStore()
		store.setEnabled(0)
		expect(store.animationsEnabled).toBe(false)
		store.setEnabled('yes')
		expect(store.animationsEnabled).toBe(true)
	})

	it('loadFromServerPayload sets state from "no"', () => {
		const store = useA11yStore()
		store.loadFromServerPayload({ animations_enabled: 'no' })
		expect(store.animationsEnabled).toBe(false)
	})

	it('loadFromServerPayload sets state from "yes"', () => {
		const store = useA11yStore()
		store.setEnabled(false)
		store.loadFromServerPayload({ animations_enabled: 'yes' })
		expect(store.animationsEnabled).toBe(true)
	})

	it('loadFromServerPayload leaves default untouched when key missing', () => {
		const store = useA11yStore()
		store.loadFromServerPayload({})
		expect(store.animationsEnabled).toBe(true)
	})

	it('loadFromServerPayload is safe with null/undefined', () => {
		const store = useA11yStore()
		store.loadFromServerPayload(null)
		store.loadFromServerPayload(undefined)
		expect(store.animationsEnabled).toBe(true)
	})
})
