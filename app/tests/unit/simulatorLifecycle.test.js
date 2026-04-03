import { describe, it, expect } from 'vitest'
import FirewallBuilder from '../../src/components/FirewallBuilder.vue'
import DnsResolver from '../../src/components/DnsResolver.vue'
import RoutingTable from '../../src/components/RoutingTable.vue'
import NatTable from '../../src/components/NatTable.vue'
import PortScanner from '../../src/components/PortScanner.vue'
import WiresharkLite from '../../src/components/WiresharkLite.vue'
import AuthFlowSimulator from '../../src/components/AuthFlowSimulator.vue'

describe('Simulator Lifecycle — beforeUnmount hooks', () => {
	it('FirewallBuilder has beforeUnmount hook', () => {
		expect(typeof FirewallBuilder.beforeUnmount).toBe('function')
	})

	it('RoutingTable has beforeUnmount hook', () => {
		expect(typeof RoutingTable.beforeUnmount).toBe('function')
	})

	it('NatTable has beforeUnmount hook', () => {
		expect(typeof NatTable.beforeUnmount).toBe('function')
	})

	it('WiresharkLite has beforeUnmount hook', () => {
		expect(typeof WiresharkLite.beforeUnmount).toBe('function')
	})

	it('AuthFlowSimulator has beforeUnmount hook', () => {
		expect(typeof AuthFlowSimulator.beforeUnmount).toBe('function')
	})

	it('DnsResolver has beforeUnmount hook (regression guard)', () => {
		expect(typeof DnsResolver.beforeUnmount).toBe('function')
	})

	it('PortScanner has beforeUnmount hook (regression guard)', () => {
		expect(typeof PortScanner.beforeUnmount).toBe('function')
	})
})
