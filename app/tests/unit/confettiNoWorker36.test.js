/**
 * Persona sweep (GitHub #36): passing an exam with 90 % or more logged a Content-Security-Policy
 * violation, because canvas-confetti's default instance starts a blob: Web Worker that
 * Nextcloud's CSP forbids. The app's celebrations must use an instance without the worker.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fire = vi.fn()
const create = vi.fn(() => fire)
const defaultInstance = vi.fn()
defaultInstance.create = create
vi.mock('canvas-confetti', () => ({ default: defaultInstance }))

describe('celebrations never start the blob worker (GitHub #36)', () => {
	beforeEach(() => {
		fire.mockClear()
		defaultInstance.mockClear()
		window.matchMedia = () => ({ matches: false })
	})

	it('fires through an own instance created with useWorker: false', async () => {
		const { celebrateMastery, celebrateBadge, celebrateStreak } = await import('../../src/confetti.js')
		celebrateMastery()
		celebrateBadge()
		celebrateStreak(7)
		expect(defaultInstance).not.toHaveBeenCalled()
		expect(fire).toHaveBeenCalledTimes(3)
		expect(create).toHaveBeenCalledTimes(1)
		expect(create.mock.calls[0][1]).toMatchObject({ useWorker: false })
	})
})
