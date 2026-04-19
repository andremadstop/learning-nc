import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useA11yStore } from '../../src/stores/a11yStore.js'

describe('a11yStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('defaults animations to enabled', () => {
		expect(useA11yStore().animationsEnabled).toBe(true)
	})

	it('hydrates from server payload', () => {
		const store = useA11yStore()
		store.loadFromServerPayload({ animations_enabled: 'no' })
		expect(store.animationsEnabled).toBe(false)
		store.loadFromServerPayload({ animations_enabled: 'yes' })
		expect(store.animationsEnabled).toBe(true)
	})

	it('updates immediately through setEnabled', () => {
		const store = useA11yStore()
		store.setEnabled(false)
		expect(store.animationsEnabled).toBe(false)
		store.setEnabled(true)
		expect(store.animationsEnabled).toBe(true)
	})
})
