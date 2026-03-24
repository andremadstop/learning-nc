import { describe, expect, it } from 'vitest'
import {
	buildFrameVisualization,
	calculateSubinterfaces,
	canRoute,
	createVlanSetup,
	isValidVlanId,
} from '../../src/utils/vlanSimulator.js'

const VLANS = [
	{ vlanId: 10, name: 'HR', subnet: '10.10.10.0/24', gateway: '10.10.10.1' },
	{ vlanId: 20, name: 'Dev', subnet: '10.10.20.0/24', gateway: '10.10.20.1' },
	{ vlanId: 30, name: 'Finance', subnet: '10.10.30.0/24', gateway: '10.10.30.1' },
]

describe('isValidVlanId', () => {
	it('accepts standard VLAN ids', () => {
		expect(isValidVlanId(1)).toBe(true)
		expect(isValidVlanId(10)).toBe(true)
		expect(isValidVlanId(4094)).toBe(true)
	})

	it('rejects out-of-range or reserved ids', () => {
		expect(isValidVlanId(0)).toBe(false)
		expect(isValidVlanId(4095)).toBe(false)
		expect(isValidVlanId(1002)).toBe(false)
		expect(isValidVlanId(1005)).toBe(false)
	})
})

describe('createVlanSetup', () => {
	it('creates normalized vlans, access ports and one trunk', () => {
		const setup = createVlanSetup(VLANS)

		expect(setup.vlans).toHaveLength(3)
		expect(setup.ports).toHaveLength(4)
		expect(setup.ports[0]).toEqual({
			portId: 'Fa0/1',
			mode: 'access',
			accessVlan: 10,
		})
		expect(setup.ports[3]).toEqual({
			portId: 'Gi0/1',
			mode: 'trunk',
			allowedVlans: [10, 20, 30],
			nativeVlan: 1,
		})
		expect(setup.routerConfig.subinterfaces).toHaveLength(3)
	})
})

describe('buildFrameVisualization', () => {
	it('creates an untagged frame for access ports', () => {
		const frame = buildFrameVisualization({ portId: 'Fa0/1', mode: 'access', accessVlan: 10 }, 10)
		expect(frame).toEqual({
			tagged: false,
			vlanId: null,
			fields: ['Dest MAC', 'Src MAC', 'EthType', 'Data'],
		})
	})

	it('creates a tagged frame for trunk ports', () => {
		const frame = buildFrameVisualization({ portId: 'Gi0/1', mode: 'trunk', allowedVlans: [10, 20], nativeVlan: 1 }, 20)
		expect(frame.tagged).toBe(true)
		expect(frame.vlanId).toBe(20)
		expect(frame.fields).toContain('802.1Q Tag')
	})
})

describe('calculateSubinterfaces', () => {
	it('builds router-on-a-stick subinterfaces', () => {
		const subinterfaces = calculateSubinterfaces(VLANS)
		expect(subinterfaces).toEqual([
			{ interface: 'Gi0/0', subinterface: 'Gi0/0.10', encapsulation: 'dot1Q 10', ip: '10.10.10.1' },
			{ interface: 'Gi0/0', subinterface: 'Gi0/0.20', encapsulation: 'dot1Q 20', ip: '10.10.20.1' },
			{ interface: 'Gi0/0', subinterface: 'Gi0/0.30', encapsulation: 'dot1Q 30', ip: '10.10.30.1' },
		])
	})
})

describe('canRoute', () => {
	it('returns true for same VLAN and for configured routed VLANs', () => {
		const routerConfig = { subinterfaces: calculateSubinterfaces(VLANS) }
		expect(canRoute(VLANS[0], VLANS[0], routerConfig)).toBe(true)
		expect(canRoute(VLANS[0], VLANS[1], routerConfig)).toBe(true)
	})

	it('returns false when one VLAN is not configured on the router', () => {
		const routerConfig = { subinterfaces: calculateSubinterfaces([VLANS[0]]) }
		expect(canRoute(VLANS[0], VLANS[1], routerConfig)).toBe(false)
	})
})
