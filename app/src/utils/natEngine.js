const PAT_PORT_START = 40000

function cloneTable(table) {
	return (table || []).map((entry) => ({ ...entry }))
}

export function createNatConfig(type, overrides = {}) {
	return {
		type,
		publicIp: overrides.publicIp || '203.0.113.10',
		pool: overrides.pool || ['203.0.113.10', '203.0.113.11', '203.0.113.12'],
		mappings: overrides.mappings || [],
		table: cloneTable(overrides.table),
	}
}

export function translateStaticNat(packet, mappings) {
	const mapping = (mappings || []).find((entry) => entry.insideLocal === packet.sourceIp)
	if (!mapping) {
		return {
			status: 'no-mapping',
			table: [],
			packet,
		}
	}

	return {
		status: 'translated',
		table: [{
			insideLocal: mapping.insideLocal,
			insideGlobal: mapping.insideGlobal,
			outsideLocal: packet.destinationIp,
			outsideGlobal: packet.destinationIp,
			protocol: packet.protocol,
		}],
		packet: {
			...packet,
			sourceIp: mapping.insideGlobal,
		},
	}
}

export function allocateDynamicMapping(insideLocal, pool, table) {
	const existing = (table || []).find((entry) => entry.insideLocal === insideLocal)
	if (existing) {
		return existing
	}

	const usedIps = new Set((table || []).map((entry) => entry.insideGlobal))
	const availableIp = (pool || []).find((candidate) => !usedIps.has(candidate))
	if (!availableIp) {
		return null
	}

	return {
		insideLocal,
		insideGlobal: availableIp,
	}
}

export function translateDynamicNat(packet, pool, table) {
	const nextTable = cloneTable(table)
	const mapping = allocateDynamicMapping(packet.sourceIp, pool, nextTable)
	if (!mapping) {
		return {
			status: 'pool-exhausted',
			table: nextTable,
			packet,
		}
	}

	if (!nextTable.find((entry) => entry.insideLocal === mapping.insideLocal)) {
		nextTable.push({
			...mapping,
			outsideLocal: packet.destinationIp,
			outsideGlobal: packet.destinationIp,
			protocol: packet.protocol,
		})
	}

	return {
		status: 'translated',
		table: nextTable,
		packet: {
			...packet,
			sourceIp: mapping.insideGlobal,
		},
	}
}

export function translatePat(packet, publicIp, table) {
	const nextTable = cloneTable(table)
	const existing = nextTable.find((entry) => entry.insideLocal === packet.sourceIp && entry.insidePort === packet.sourcePort)
	if (existing) {
		return {
			status: 'translated',
			table: nextTable,
			packet: {
				...packet,
				sourceIp: publicIp,
				sourcePort: existing.insideGlobalPort,
			},
		}
	}

	const usedPorts = new Set(nextTable.map((entry) => entry.insideGlobalPort))
	let translatedPort = PAT_PORT_START
	while (usedPorts.has(translatedPort)) {
		translatedPort += 1
	}

	nextTable.push({
		insideLocal: packet.sourceIp,
		insidePort: packet.sourcePort,
		insideGlobal: publicIp,
		insideGlobalPort: translatedPort,
		outsideLocal: packet.destinationIp,
		outsideGlobal: packet.destinationIp,
		protocol: packet.protocol,
	})

	return {
		status: 'translated',
		table: nextTable,
		packet: {
			...packet,
			sourceIp: publicIp,
			sourcePort: translatedPort,
		},
	}
}

export function findHairpinTranslation(packet, mappings) {
	const mapping = (mappings || []).find((entry) => entry.insideGlobal === packet.destinationIp)
	if (!mapping) {
		return null
	}

	return {
		...packet,
		destinationIp: mapping.insideLocal,
	}
}

export function simulateNat(packet, config) {
	const normalizedConfig = createNatConfig(config.type, config)
	if (normalizedConfig.type === 'static') {
		return translateStaticNat(packet, normalizedConfig.mappings)
	}
	if (normalizedConfig.type === 'dynamic') {
		return translateDynamicNat(packet, normalizedConfig.pool, normalizedConfig.table)
	}
	return translatePat(packet, normalizedConfig.publicIp, normalizedConfig.table)
}

export function buildNatVisualization(packet, translation) {
	return {
		inside: `${packet.sourceIp}:${packet.sourcePort} -> ${packet.destinationIp}:${packet.destinationPort}`,
		device: translation.status === 'translated' ? 'NAT translation applied' : 'No translation available',
		outside: `${translation.packet.sourceIp}:${translation.packet.sourcePort} -> ${translation.packet.destinationIp}:${translation.packet.destinationPort}`,
	}
}
