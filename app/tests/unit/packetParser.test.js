import { describe, expect, it } from 'vitest'
import packetCaptures from '../../data/packet_captures.json'
import {
	buildCaptureTimeline,
	detectCaptureIssues,
	detectDnsSpoofing,
	detectRetransmissions,
	identifyApplicationProtocol,
	parsePacket,
	summarizePacket,
	summarizeTcpFlags,
} from '../../src/utils/packetParser.js'

const tcpHandshake = packetCaptures.captures.find((capture) => capture.id === 'tcp-handshake')
const dnsCapture = packetCaptures.captures.find((capture) => capture.id === 'dns-query-response')
const icmpCapture = packetCaptures.captures.find((capture) => capture.id === 'icmp-ping-problem')

describe('summarizeTcpFlags', () => {
	it('joins array flags', () => {
		expect(summarizeTcpFlags(['SYN', 'ACK'])).toBe('SYN, ACK')
	})

	it('serializes object flags', () => {
		expect(summarizeTcpFlags({ syn: true, ack: true, rst: false })).toBe('SYN, ACK')
	})
})

describe('identifyApplicationProtocol', () => {
	it('detects DNS from the dedicated layer', () => {
		expect(identifyApplicationProtocol(dnsCapture.packets[0])).toBe('DNS')
	})

	it('detects HTTP from port 80', () => {
		expect(identifyApplicationProtocol(packetCaptures.captures.find((capture) => capture.id === 'http-get').packets[0])).toBe('HTTP')
	})

	it('returns Unknown for unclassified traffic', () => {
		expect(identifyApplicationProtocol({ udp: { srcPort: 9999, dstPort: 9998 } })).toBe('Unknown')
	})
})

describe('parsePacket', () => {
	it('builds Ethernet, IP and TCP layers', () => {
		const layers = parsePacket(tcpHandshake.packets[0])
		expect(layers.map((layer) => layer.id)).toEqual(['ethernet', 'ip', 'tcp'])
	})

	it('builds ARP layers without IP/TCP', () => {
		const arpLayers = parsePacket(packetCaptures.captures.find((capture) => capture.id === 'arp').packets[0])
		expect(arpLayers.map((layer) => layer.id)).toEqual(['ethernet', 'arp'])
	})

	it('includes DNS application data', () => {
		const dnsLayers = parsePacket(dnsCapture.packets[1])
		expect(dnsLayers.map((layer) => layer.id)).toContain('dns')
	})

	it('includes ICMP details', () => {
		const icmpLayers = parsePacket(icmpCapture.packets[1])
		expect(icmpLayers.map((layer) => layer.id)).toContain('icmp')
	})
})

describe('summarizePacket', () => {
	it('returns timeline metadata', () => {
		const summary = summarizePacket(tcpHandshake.packets[0])
		expect(summary.protocol).toBe('TCP')
		expect(summary.id).toBe('pkt-1')
	})
})

describe('capture issue detection', () => {
	it('detects retransmissions by duplicate sequence numbers', () => {
		const retransmissions = detectRetransmissions([
			...tcpHandshake.packets,
			{ ...tcpHandshake.packets[0], id: 'pkt-1-retry' },
		])
		expect(retransmissions.map((packet) => packet.id)).toContain('pkt-1-retry')
	})

	it('returns false for clean DNS answers', () => {
		expect(detectDnsSpoofing(dnsCapture.packets)).toBe(false)
	})

	it('detects spoofing when a second DNS answer differs', () => {
		expect(detectDnsSpoofing([
			...dnsCapture.packets,
			{ ...dnsCapture.packets[1], id: 'pkt-5b', dns: { ...dnsCapture.packets[1].dns, answer: '203.0.113.99' } },
		])).toBe(true)
	})

	it('collects TTL exceeded packets', () => {
		const issues = detectCaptureIssues(icmpCapture.packets)
		expect(issues.ttlExceededPackets).toEqual(['pkt-11'])
	})

	it('collects RST packets when present', () => {
		const issues = detectCaptureIssues([
			...tcpHandshake.packets,
			{ ...tcpHandshake.packets[2], id: 'pkt-rst', tcp: { ...tcpHandshake.packets[2].tcp, flags: ['RST'] } },
		])
		expect(issues.resetPackets).toEqual(['pkt-rst'])
	})
})

describe('buildCaptureTimeline', () => {
	it('decorates every packet with parsed layers', () => {
		const timeline = buildCaptureTimeline(tcpHandshake)
		expect(timeline).toHaveLength(3)
		expect(timeline[0].layers[0].label).toBe('Ethernet Frame')
	})
})
