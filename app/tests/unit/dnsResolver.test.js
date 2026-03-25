import { describe, expect, it } from 'vitest'
import {
	DNS_SIMULATION_DATA,
	deriveTld,
	getRecordTypeCatalog,
	normalizeDomain,
	normalizeRecordType,
	resolveDnsLookup,
	resolveRecordTypeExample,
} from '../../src/utils/dnsResolver.js'

describe('normalizeDomain', () => {
	it('normalizes lowercase, whitespace and trailing dots', () => {
		expect(normalizeDomain('  Mail.Example.COM. ')).toBe('mail.example.com')
	})

	it('rejects invalid hostnames', () => {
		expect(normalizeDomain('bad domain')).toBe(null)
		expect(normalizeDomain('example..org')).toBe(null)
		expect(normalizeDomain('-edge.example.net')).toBe(null)
	})
})

describe('normalizeRecordType', () => {
	it('accepts supported record types case-insensitively', () => {
		expect(normalizeRecordType('aaaa')).toBe('AAAA')
		expect(normalizeRecordType('mx')).toBe('MX')
	})

	it('rejects unsupported record types', () => {
		expect(normalizeRecordType('SRV')).toBe(null)
	})
})

describe('deriveTld', () => {
	it('extracts classic tlds', () => {
		expect(deriveTld('example.com')).toBe('com')
		expect(deriveTld('branch.example.de')).toBe('de')
	})

	it('maps reverse lookups to arpa', () => {
		expect(deriveTld('25.113.0.203.in-addr.arpa')).toBe('arpa')
	})
})

describe('resolveDnsLookup', () => {
	it('resolves an A lookup successfully', () => {
		const result = resolveDnsLookup('example.com', 'A')
		expect(result.status).toBe('ok')
		expect(result.answers).toEqual(['93.184.216.34'])
		expect(result.steps).toHaveLength(7)
	})

	it('resolves a CNAME chain for A records', () => {
		const result = resolveDnsLookup('mail.example.com', 'A')
		expect(result.status).toBe('ok')
		expect(result.answers).toEqual(['203.0.113.25'])
		expect(result.aliasChain).toContain('mail.example.com -> edge.example.net')
		expect(result.canonicalName).toBe('edge.example.net')
	})

	it('returns AAAA answers', () => {
		const result = resolveDnsLookup('example.com', 'AAAA')
		expect(result.status).toBe('ok')
		expect(result.answers[0]).toContain('2606:2800:220:1')
	})

	it('returns MX answers with priority', () => {
		const result = resolveDnsLookup('example.com', 'MX')
		expect(result.status).toBe('ok')
		expect(result.answers).toEqual(['10 mail.example.com'])
	})

	it('returns CNAME answers directly when queried', () => {
		const result = resolveDnsLookup('www.example.org', 'CNAME')
		expect(result.status).toBe('ok')
		expect(result.answers).toEqual(['portal.example.org'])
		expect(result.aliasChain).toContain('www.example.org -> portal.example.org')
	})

	it('returns PTR answers for reverse lookups', () => {
		const result = resolveDnsLookup('25.113.0.203.in-addr.arpa', 'PTR')
		expect(result.status).toBe('ok')
		expect(result.answers).toEqual(['mail.example.com'])
	})

	it('returns NS answers', () => {
		const result = resolveDnsLookup('example.com', 'NS')
		expect(result.status).toBe('ok')
		expect(result.answers).toContain('ns1.example.com')
	})

	it('returns SOA answers', () => {
		const result = resolveDnsLookup('example.com', 'SOA')
		expect(result.status).toBe('ok')
		expect(result.answers[0]).toContain('Serial 2026032401')
	})

	it('returns TXT answers', () => {
		const result = resolveDnsLookup('example.com', 'TXT')
		expect(result.status).toBe('ok')
		expect(result.answers[0]).toContain('v=spf1')
	})

	it('reports missing records when the domain exists without the requested type', () => {
		const result = resolveDnsLookup('shop.example.org', 'A')
		expect(result.status).toBe('missing_record')
		expect(result.message).toContain('NODATA')
	})

	it('reports missing domains when the zone has no host entry', () => {
		const result = resolveDnsLookup('example.org', 'A')
		expect(result.status).toBe('missing_domain')
		expect(result.message).toContain('NXDOMAIN')
	})

	it('reports invalid DNSSEC signatures', () => {
		const result = resolveDnsLookup('branch.example.de', 'A')
		expect(result.status).toBe('dnssec_failure')
		expect(result.message).toContain('SERVFAIL')
	})

	it('returns an invalid request for bad input', () => {
		const result = resolveDnsLookup('bad domain', 'A')
		expect(result.status).toBe('invalid_request')
		expect(result.steps).toEqual([])
	})

	it('keeps the authoritative step explanatory on errors', () => {
		const result = resolveDnsLookup('34.216.184.93.in-addr.arpa', 'PTR')
		expect(result.steps[5].detail).toContain('Kein PTR-Record')
	})

	it('uses the simulation dataset for example lookups', () => {
		expect(DNS_SIMULATION_DATA['example.com'].records.A).toContain('93.184.216.34')
		const result = resolveRecordTypeExample('TXT')
		expect(result.status).toBe('ok')
		expect(result.domain).toBe('example.com')
	})
})

describe('getRecordTypeCatalog', () => {
	it('includes all required record types', () => {
		const catalog = getRecordTypeCatalog()
		expect(catalog).toHaveLength(8)
		expect(catalog.map(entry => entry.id)).toEqual(['A', 'AAAA', 'MX', 'CNAME', 'PTR', 'NS', 'SOA', 'TXT'])
	})
})
