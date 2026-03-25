import { describe, it, expect } from 'vitest'
import FirewallBuilder from '../../src/components/FirewallBuilder.vue'
import DnsResolver from '../../src/components/DnsResolver.vue'
import RoutingTable from '../../src/components/RoutingTable.vue'
import NatTable from '../../src/components/NatTable.vue'
import PortScanner from '../../src/components/PortScanner.vue'
import WiresharkLite from '../../src/components/WiresharkLite.vue'
import AuthFlowSimulator from '../../src/components/AuthFlowSimulator.vue'

describe('Simulator Lifecycle — beforeDestroy hooks', () => {
	it('FirewallBuilder has beforeDestroy hook', () => {
		expect(typeof FirewallBuilder.beforeDestroy).toBe('function')
	})

	it('RoutingTable has beforeDestroy hook', () => {
		expect(typeof RoutingTable.beforeDestroy).toBe('function')
	})

	it('NatTable has beforeDestroy hook', () => {
		expect(typeof NatTable.beforeDestroy).toBe('function')
	})

	it('WiresharkLite has beforeDestroy hook', () => {
		expect(typeof WiresharkLite.beforeDestroy).toBe('function')
	})

	it('AuthFlowSimulator has beforeDestroy hook', () => {
		expect(typeof AuthFlowSimulator.beforeDestroy).toBe('function')
	})

	it('DnsResolver has beforeDestroy hook (regression guard)', () => {
		expect(typeof DnsResolver.beforeDestroy).toBe('function')
	})

	it('PortScanner has beforeDestroy hook (regression guard)', () => {
		expect(typeof PortScanner.beforeDestroy).toBe('function')
	})
})
