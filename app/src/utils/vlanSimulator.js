const RESERVED_VLANS = new Set([1002, 1003, 1004, 1005])
const DEFAULT_ROUTER_INTERFACE = 'Gi0/0'

function normalizeVlanEntry(entry, index) {
	const vlanId = Number(entry.vlanId)
	return {
		vlanId,
		name: String(entry.name || `VLAN ${index + 1}`).trim(),
		subnet: String(entry.subnet || '').trim(),
		gateway: String(entry.gateway || '').trim(),
	}
}

function extractSubinterfaceVlanId(subinterface) {
	const parts = String(subinterface || '').split('.')
	return Number(parts[parts.length - 1])
}

export function isValidVlanId(id) {
	const vlanId = Number(id)
	return Number.isInteger(vlanId) && vlanId >= 1 && vlanId <= 4094 && !RESERVED_VLANS.has(vlanId)
}

export function calculateSubinterfaces(vlans) {
	return (vlans || [])
		.map(normalizeVlanEntry)
		.filter((vlan) => isValidVlanId(vlan.vlanId) && vlan.gateway)
		.map((vlan) => ({
			interface: DEFAULT_ROUTER_INTERFACE,
			subinterface: `${DEFAULT_ROUTER_INTERFACE}.${vlan.vlanId}`,
			encapsulation: `dot1Q ${vlan.vlanId}`,
			ip: vlan.gateway,
		}))
}

export function createVlanSetup(entries) {
	const vlans = (entries || [])
		.map(normalizeVlanEntry)
		.filter((vlan) => vlan.name && vlan.subnet && vlan.gateway)

	const accessPorts = vlans.map((vlan, index) => ({
		portId: `Fa0/${index + 1}`,
		mode: 'access',
		accessVlan: vlan.vlanId,
	}))

	const ports = [
		...accessPorts,
		{
			portId: 'Gi0/1',
			mode: 'trunk',
			allowedVlans: vlans.map((vlan) => vlan.vlanId),
			nativeVlan: 1,
		},
	]

	return {
		vlans,
		ports,
		routerConfig: {
			interface: DEFAULT_ROUTER_INTERFACE,
			subinterfaces: calculateSubinterfaces(vlans),
		},
	}
}

export function buildFrameVisualization(port, vlanId) {
	if (!port || !port.mode) {
		return {
			tagged: false,
			vlanId: null,
			fields: ['Dest MAC', 'Src MAC', 'EthType', 'Data'],
		}
	}

	if (port.mode === 'trunk') {
		return {
			tagged: true,
			vlanId: Number(vlanId),
			fields: ['Dest MAC', '802.1Q Tag', 'Src MAC', 'EthType', 'Data'],
		}
	}

	return {
		tagged: false,
		vlanId: null,
		fields: ['Dest MAC', 'Src MAC', 'EthType', 'Data'],
	}
}

export function canRoute(vlanA, vlanB, routerConfig) {
	if (!vlanA || !vlanB || !isValidVlanId(vlanA.vlanId) || !isValidVlanId(vlanB.vlanId)) {
		return false
	}

	if (vlanA.vlanId === vlanB.vlanId) {
		return true
	}

	const subinterfaces = (routerConfig && routerConfig.subinterfaces) || []
	const configuredVlans = new Set(subinterfaces.map((subif) => extractSubinterfaceVlanId(subif.subinterface)))

	return configuredVlans.has(vlanA.vlanId) && configuredVlans.has(vlanB.vlanId)
}
