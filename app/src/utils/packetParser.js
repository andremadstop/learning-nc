export function summarizeTcpFlags(flags) {
	if (Array.isArray(flags)) {
		return flags.join(', ')
	}
	if (flags && typeof flags === 'object') {
		return Object.keys(flags)
			.filter((key) => Boolean(flags[key]))
			.map((key) => key.toUpperCase())
			.join(', ')
	}
	return String(flags || '')
}

export function identifyApplicationProtocol(packet) {
	if (packet.dns) {
		return 'DNS'
	}
	if (packet.http) {
		return 'HTTP'
	}
	const port = Number(packet.tcp?.dstPort || packet.tcp?.srcPort || packet.udp?.dstPort || packet.udp?.srcPort || 0)
	const protocolMap = {
		53: 'DNS',
		80: 'HTTP',
		443: 'HTTPS',
		8080: 'HTTP',
	}
	return protocolMap[port] || 'Unknown'
}

export function parsePacket(packet) {
	const layers = []

	if (packet.ethernet) {
		layers.push({
			id: 'ethernet',
			label: 'Ethernet Frame',
			fields: [
				{ label: 'Src MAC', value: packet.ethernet.srcMac },
				{ label: 'Dst MAC', value: packet.ethernet.dstMac },
				{ label: 'Type', value: packet.ethernet.type },
			],
		})
	}

	if (packet.arp) {
		layers.push({
			id: 'arp',
			label: 'ARP',
			fields: [
				{ label: 'Operation', value: packet.arp.op },
				{ label: 'Sender', value: packet.arp.senderIp },
				{ label: 'Target', value: packet.arp.targetIp },
			],
		})
		return layers
	}

	if (packet.ip) {
		layers.push({
			id: 'ip',
			label: 'IP Packet',
			fields: [
				{ label: 'Version', value: packet.ip.version },
				{ label: 'TTL', value: packet.ip.ttl },
				{ label: 'Src', value: packet.ip.srcIp },
				{ label: 'Dst', value: packet.ip.dstIp },
				{ label: 'Protocol', value: packet.ip.protocol },
			],
		})
	}

	if (packet.tcp) {
		layers.push({
			id: 'tcp',
			label: 'TCP Segment',
			fields: [
				{ label: 'Src Port', value: packet.tcp.srcPort },
				{ label: 'Dst Port', value: packet.tcp.dstPort },
				{ label: 'Seq', value: packet.tcp.seq },
				{ label: 'Ack', value: packet.tcp.ack },
				{ label: 'Flags', value: summarizeTcpFlags(packet.tcp.flags) },
			],
		})
	}

	if (packet.udp) {
		layers.push({
			id: 'udp',
			label: 'UDP Datagram',
			fields: [
				{ label: 'Src Port', value: packet.udp.srcPort },
				{ label: 'Dst Port', value: packet.udp.dstPort },
				{ label: 'Length', value: packet.udp.length },
			],
		})
	}

	if (packet.icmp) {
		layers.push({
			id: 'icmp',
			label: 'ICMP',
			fields: [
				{ label: 'Type', value: packet.icmp.type },
				{ label: 'Code', value: packet.icmp.code },
				{ label: 'Detail', value: packet.icmp.detail },
			],
		})
	}

	if (packet.dns) {
		layers.push({
			id: 'dns',
			label: 'DNS',
			fields: [
				{ label: 'Query', value: packet.dns.query },
				{ label: 'Type', value: packet.dns.recordType },
				{ label: 'Answer', value: packet.dns.answer || '-' },
			],
		})
	}

	if (packet.http) {
		layers.push({
			id: 'http',
			label: 'HTTP',
			fields: [
				{ label: 'Method', value: packet.http.method || '-' },
				{ label: 'Path', value: packet.http.path || '-' },
				{ label: 'Status', value: packet.http.status || '-' },
			],
		})
	}

	return layers
}

export function summarizePacket(packet) {
	const protocol = packet.arp ? 'ARP' : packet.icmp ? 'ICMP' : packet.tcp ? 'TCP' : packet.udp ? 'UDP' : 'Unknown'
	return {
		id: packet.id,
		timestamp: packet.timestamp,
		protocol,
		summary: packet.summary || identifyApplicationProtocol(packet),
	}
}

export function detectRetransmissions(packets) {
	const seen = new Set()
	return (packets || []).filter((packet) => {
		if (!packet.tcp) {
			return false
		}
		const key = `${packet.ip?.srcIp}-${packet.ip?.dstIp}-${packet.tcp.seq}-${packet.tcp.dstPort}`
		if (seen.has(key)) {
			return true
		}
		seen.add(key)
		return false
	})
}

export function detectDnsSpoofing(packets) {
	const answers = new Map()
	for (const packet of packets || []) {
		if (!packet.dns || !packet.dns.answer) {
			continue
		}
		const key = `${packet.dns.query}-${packet.dns.recordType}`
		const existing = answers.get(key)
		if (existing && existing !== packet.dns.answer) {
			return true
		}
		answers.set(key, packet.dns.answer)
	}
	return false
}

export function detectCaptureIssues(packets) {
	return {
		retransmissions: detectRetransmissions(packets).map((packet) => packet.id),
		resetPackets: (packets || []).filter((packet) => summarizeTcpFlags(packet.tcp?.flags).includes('RST')).map((packet) => packet.id),
		ttlExceededPackets: (packets || []).filter((packet) => packet.icmp?.detail === 'TTL exceeded').map((packet) => packet.id),
		dnsSpoofing: detectDnsSpoofing(packets),
	}
}

export function buildCaptureTimeline(capture) {
	return (capture.packets || []).map((packet) => ({
		...summarizePacket(packet),
		layers: parsePacket(packet),
	}))
}
