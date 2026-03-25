import { describe, expect, it } from 'vitest'
import {
	buildScanResults,
	buildScanTimeline,
	detectHostType,
	explainScanType,
	findCompromiseIndicators,
	getHostProfiles,
	getServiceInfo,
} from '../../src/utils/portScanner.js'

describe('getServiceInfo', () => {
	it.each([
		[22, 'SSH'],
		[443, 'HTTPS'],
		[5432, 'PostgreSQL'],
		[9999, 'Unknown'],
	])('returns a service label for port %i', (port, expected) => {
		expect(getServiceInfo(port).service).toBe(expected)
	})
})

describe('getHostProfiles', () => {
	it('returns six predefined host profiles', () => {
		expect(getHostProfiles()).toHaveLength(6)
	})

	it('contains a compromised host with suspicious ports', () => {
		const profile = getHostProfiles().find((entry) => entry.id === 'compromised-host')
		expect(profile.ports.map((entry) => entry.port)).toContain(4444)
		expect(profile.ports.map((entry) => entry.port)).toContain(31337)
	})
})

describe('buildScanResults', () => {
	it('builds a sorted result list for a profile', () => {
		const scan = buildScanResults('webserver')
		expect(scan.ports.map((entry) => entry.port)).toEqual([22, 80, 443])
		expect(scan.durationMs).toBe(2000)
	})

	it('applies state overrides', () => {
		const scan = buildScanResults('webserver', {
			ports: [{ port: 80, state: 'filtered' }],
		})
		expect(scan.ports.find((entry) => entry.port === 80).state).toBe('filtered')
	})

	it('throws for unknown profiles', () => {
		expect(() => buildScanResults('unknown')).toThrow('Unknown host profile')
	})
})

describe('detectHostType', () => {
	it.each([
		['webserver', 'webserver'],
		['mailserver', 'mailserver'],
		['domain-controller', 'domain-controller'],
		['network-device', 'network-device'],
		['database', 'database'],
		['compromised-host', 'compromised-host'],
	])('classifies the %s profile correctly', (profileId, expectedType) => {
		expect(detectHostType(buildScanResults(profileId))).toBe(expectedType)
	})
})

describe('findCompromiseIndicators', () => {
	it('finds suspicious backdoor ports', () => {
		const indicators = findCompromiseIndicators(buildScanResults('compromised-host'))
		expect(indicators).toHaveLength(2)
		expect(indicators.map((entry) => entry.port)).toEqual([4444, 31337])
	})

	it('returns an empty list for clean hosts', () => {
		expect(findCompromiseIndicators(buildScanResults('webserver'))).toEqual([])
	})
})

describe('scan metadata', () => {
	it.each([
		['syn', 'half-open TCP SYN probes'],
		['connect', 'full TCP handshake'],
		['udp', 'UDP probes'],
		['fin', 'TCP FIN packets'],
	])('explains %s scans', (scanType, expectedSnippet) => {
		expect(explainScanType(scanType)).toContain(expectedSnippet)
	})

	it('builds a reveal timeline for animated scans', () => {
		const timeline = buildScanTimeline(buildScanResults('mailserver'))
		expect(timeline[0].revealAtMs).toBeGreaterThan(0)
		expect(timeline.at(-1).revealAtMs).toBe(2000)
	})
})
