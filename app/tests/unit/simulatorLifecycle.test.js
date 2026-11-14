import { describe, it, expect, vi, afterEach } from 'vitest'
import DnsResolver from '../../src/components/DnsResolver.vue'
import PortScanner from '../../src/components/PortScanner.vue'

// Only the two simulators that start timers have something to clean up. The other simulators'
// beforeUnmount hooks are empty, so checking that they exist proved nothing (audit #251).
describe('Simulator lifecycle — timers are cleared on unmount', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('DnsResolver stops its step timer when it unmounts', () => {
		const ctx = { clearTimer: vi.fn() }
		DnsResolver.beforeUnmount.call(ctx)
		expect(ctx.clearTimer).toHaveBeenCalledTimes(1)
	})

	it('DnsResolver.clearTimer clears the step interval and forgets it', () => {
		const spy = vi.spyOn(window, 'clearInterval')
		const ctx = { stepTimer: 42 }
		DnsResolver.methods.clearTimer.call(ctx)
		expect(spy).toHaveBeenCalledWith(42)
		expect(ctx.stepTimer).toBeNull()
	})

	it('PortScanner clears its scan interval when it unmounts', () => {
		const spy = vi.spyOn(window, 'clearInterval')
		PortScanner.beforeUnmount.call({ intervalId: 42 })
		expect(spy).toHaveBeenCalledWith(42)
	})
})
