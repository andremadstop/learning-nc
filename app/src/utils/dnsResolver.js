const ROOT_SERVERS = Object.freeze({
	com: 'a.gtld-servers.net',
	de: 'f.nic.de',
	net: 'a.gtld-servers.net',
	org: 'b.pir.org',
	arpa: 'a.in-addr-servers.arpa',
})

const RECORD_TYPE_CATALOG = Object.freeze([
	{
		id: 'A',
		example: '93.184.216.34',
		description: 'IPv4-Adresse',
		exampleDomain: 'example.com',
	},
	{
		id: 'AAAA',
		example: '2606:2800:220:1:248:1893:25c8:1946',
		description: 'IPv6-Adresse',
		exampleDomain: 'example.com',
	},
	{
		id: 'MX',
		example: '10 mail.example.com',
		description: 'Mailserver mit Priorität',
		exampleDomain: 'example.com',
	},
	{
		id: 'CNAME',
		example: 'www -> portal.example.org',
		description: 'Alias auf einen kanonischen Namen',
		exampleDomain: 'www.example.org',
	},
	{
		id: 'PTR',
		example: '25.113.0.203.in-addr.arpa -> mail.example.com',
		description: 'Reverse Lookup',
		exampleDomain: '25.113.0.203.in-addr.arpa',
	},
	{
		id: 'NS',
		example: 'ns1.example.com',
		description: 'Nameserver der Zone',
		exampleDomain: 'example.com',
	},
	{
		id: 'SOA',
		example: 'Serial, Refresh, Retry, Expire',
		description: 'Start of Authority',
		exampleDomain: 'example.com',
	},
	{
		id: 'TXT',
		example: 'v=spf1 include:_spf.example.com -all',
		description: 'Freitext für SPF, DKIM oder Verifikation',
		exampleDomain: 'example.com',
	},
])

const KNOWN_RECORD_TYPES = new Set(RECORD_TYPE_CATALOG.map(entry => entry.id))

export const DNS_SIMULATION_DATA = Object.freeze({
	'example.com': {
		authoritative: 'ns1.example.com',
		records: {
			A: ['93.184.216.34'],
			AAAA: ['2606:2800:220:1:248:1893:25c8:1946'],
			MX: [{ priority: 10, exchange: 'mail.example.com' }],
			NS: ['ns1.example.com', 'ns2.example.com'],
			SOA: [{
				serial: '2026032401',
				refresh: '7200',
				retry: '3600',
				expire: '1209600',
			}],
			TXT: ['v=spf1 include:_spf.example.com -all'],
		},
	},
	'mail.example.com': {
		authoritative: 'ns1.example.com',
		cname: 'edge.example.net',
	},
	'edge.example.net': {
		authoritative: 'ns1.example.net',
		records: {
			A: ['203.0.113.25'],
		},
	},
	'www.example.org': {
		authoritative: 'ns1.example.org',
		cname: 'portal.example.org',
	},
	'portal.example.org': {
		authoritative: 'ns1.example.org',
		records: {
			A: ['198.51.100.42'],
		},
	},
	'shop.example.org': {
		authoritative: 'ns1.example.org',
		records: {
			AAAA: ['2001:db8:feed::25'],
		},
	},
	'example.net': {
		authoritative: 'ns1.example.net',
		records: {
			NS: ['ns1.example.net'],
			SOA: [{
				serial: '2026032402',
				refresh: '7200',
				retry: '3600',
				expire: '1209600',
			}],
			TXT: ['v=spf1 -all'],
		},
	},
	'25.113.0.203.in-addr.arpa': {
		authoritative: 'ns1.reverse.example',
		records: {
			PTR: ['mail.example.com'],
		},
	},
	'34.216.184.93.in-addr.arpa': {
		authoritative: 'ns1.reverse.example',
		records: {},
	},
	'branch.example.de': {
		authoritative: 'ns1.example.de',
		dnssec: 'invalid',
		records: {
			A: ['198.51.100.55'],
		},
	},
})

function normalizeDomainLabel(label) {
	return /^[a-z0-9-]+$/.test(label) && !label.startsWith('-') && !label.endsWith('-')
}

function formatRecordValue(recordType, value) {
	if (recordType === 'MX' && value && typeof value === 'object') {
		return `${value.priority} ${value.exchange}`
	}

	if (recordType === 'SOA' && value && typeof value === 'object') {
		return `Serial ${value.serial}, Refresh ${value.refresh}, Retry ${value.retry}, Expire ${value.expire}`
	}

	return String(value)
}

function guessAuthoritativeServer(domain) {
	if (!domain) return 'ns1.authoritative.invalid'
	if (domain.endsWith('.in-addr.arpa')) return 'ns1.reverse.example'

	const labels = domain.split('.')
	if (labels.length >= 2) {
		return `ns1.${labels.slice(-2).join('.')}`
	}

	return `ns1.${domain}`
}

function summarizeAuthoritativeAnswer(domain, recordType, resolution) {
	if (resolution.status === 'ok' && resolution.aliasChain.length) {
		return `Alias aufgelöst: ${resolution.aliasChain.join(', ')}. Finaler ${recordType}-Record für ${resolution.canonicalName}: ${resolution.answers.join(', ')}`
	}

	if (resolution.status === 'ok') {
		return `${recordType}-Antwort für ${domain}: ${resolution.answers.join(', ')}`
	}

	if (resolution.status === 'dnssec_failure') {
		return `DNSSEC-Prüfung für ${domain} fehlgeschlagen. Antwort wird verworfen.`
	}

	if (resolution.status === 'cname_loop') {
		return `CNAME-Schleife erkannt. Resolver stoppt die Auflösung für ${domain}.`
	}

	if (resolution.status === 'missing_record') {
		return `Kein ${recordType}-Record für ${domain} vorhanden.`
	}

	return `${domain} ist in keiner bekannten Zone eingetragen.`
}

function summarizeClientAnswer(domain, recordType, resolution) {
	if (resolution.status === 'ok') {
		return `${recordType}-Lookup erfolgreich: ${resolution.answers.join(', ')}`
	}

	if (resolution.status === 'dnssec_failure') {
		return `Client erhält SERVFAIL für ${domain}, weil DNSSEC die Antwort blockiert.`
	}

	if (resolution.status === 'cname_loop') {
		return `Client erhält SERVFAIL, weil der Alias für ${domain} in einer Schleife endet.`
	}

	if (resolution.status === 'missing_record') {
		return `Client erhält NODATA: Die Domain existiert, aber kein ${recordType}-Record ist vorhanden.`
	}

	return `Client erhält NXDOMAIN: ${domain} konnte nicht gefunden werden.`
}

function resolveFromAuthoritative(domain, recordType, dataset, visited = []) {
	const entry = dataset[domain]
	const authoritative = entry?.authoritative || guessAuthoritativeServer(domain)

	if (!entry) {
		return {
			status: 'missing_domain',
			answers: [],
			aliasChain: [],
			authoritative,
			canonicalName: domain,
		}
	}

	if (entry.dnssec === 'invalid') {
		return {
			status: 'dnssec_failure',
			answers: [],
			aliasChain: [],
			authoritative,
			canonicalName: domain,
		}
	}

	if (recordType === 'CNAME') {
		if (!entry.cname) {
			return {
				status: 'missing_record',
				answers: [],
				aliasChain: [],
				authoritative,
				canonicalName: domain,
			}
		}

		return {
			status: 'ok',
			answers: [entry.cname],
			aliasChain: [`${domain} -> ${entry.cname}`],
			authoritative,
			canonicalName: entry.cname,
		}
	}

	const records = entry.records?.[recordType]
	if (Array.isArray(records) && records.length > 0) {
		return {
			status: 'ok',
			answers: records.map(value => formatRecordValue(recordType, value)),
			aliasChain: [],
			authoritative,
			canonicalName: domain,
		}
	}

	if ((recordType === 'A' || recordType === 'AAAA') && entry.cname) {
		if (visited.includes(domain)) {
			return {
				status: 'cname_loop',
				answers: [],
				aliasChain: [],
				authoritative,
				canonicalName: domain,
			}
		}

		const nested = resolveFromAuthoritative(entry.cname, recordType, dataset, [...visited, domain])
		return {
			...nested,
			authoritative: nested.authoritative || authoritative,
			aliasChain: [`${domain} -> ${entry.cname}`].concat(nested.aliasChain || []),
		}
	}

	return {
		status: 'missing_record',
		answers: [],
		aliasChain: [],
		authoritative,
		canonicalName: domain,
	}
}

export function normalizeDomain(input) {
	if (typeof input !== 'string') return null

	const value = input.trim().toLowerCase().replace(/\.$/, '')
	if (!value || value.length > 253 || value.includes('..')) return null

	const labels = value.split('.')
	if (labels.some(label => label.length === 0 || label.length > 63 || !normalizeDomainLabel(label))) {
		return null
	}

	return value
}

export function normalizeRecordType(recordType) {
	const normalized = String(recordType || '').trim().toUpperCase()
	return KNOWN_RECORD_TYPES.has(normalized) ? normalized : null
}

export function deriveTld(domain) {
	const normalized = normalizeDomain(domain)
	if (!normalized) return null
	if (normalized.endsWith('.in-addr.arpa')) return 'arpa'

	const labels = normalized.split('.')
	return labels[labels.length - 1] || null
}

export function getRecordTypeCatalog() {
	return RECORD_TYPE_CATALOG.map(entry => ({ ...entry }))
}

export function resolveDnsLookup(domain, recordType = 'A', dataset = DNS_SIMULATION_DATA) {
	const normalizedDomain = normalizeDomain(domain)
	const normalizedRecordType = normalizeRecordType(recordType)

	if (!normalizedDomain || !normalizedRecordType) {
		return {
			domain: normalizedDomain || '',
			recordType: normalizedRecordType || '',
			status: 'invalid_request',
			answers: [],
			aliasChain: [],
			tld: null,
			tldServer: '',
			authoritativeServer: '',
			canonicalName: normalizedDomain || '',
			message: 'Bitte eine gueltige Domain und einen unterstuetzten Record-Typ angeben.',
			steps: [],
		}
	}

	const tld = deriveTld(normalizedDomain)
	const tldServer = ROOT_SERVERS[tld] || `ns1.nic.${tld}`
	const authoritativeServer = dataset[normalizedDomain]?.authoritative || guessAuthoritativeServer(normalizedDomain)
	const resolution = resolveFromAuthoritative(normalizedDomain, normalizedRecordType, dataset)

	return {
		domain: normalizedDomain,
		recordType: normalizedRecordType,
		status: resolution.status,
		answers: resolution.answers,
		aliasChain: resolution.aliasChain,
		tld,
		tldServer,
		authoritativeServer: resolution.authoritative || authoritativeServer,
		canonicalName: resolution.canonicalName,
		message: summarizeClientAnswer(normalizedDomain, normalizedRecordType, resolution),
		steps: [
			{
				id: 'client-resolver',
				source: 'client',
				target: 'resolver',
				title: 'Client fragt den rekursiven Resolver',
				detail: `${normalizedRecordType} ${normalizedDomain}`,
			},
			{
				id: 'resolver-root',
				source: 'resolver',
				target: 'root',
				title: 'Resolver fragt die Root-Zone',
				detail: `Wer kennt die TLD ${tld === 'arpa' ? 'in-addr.arpa' : `.${tld}`}?`,
			},
			{
				id: 'root-resolver',
				source: 'root',
				target: 'resolver',
				title: 'Root liefert die TLD-Referral',
				detail: `Nutze ${tldServer} für ${tld === 'arpa' ? 'Reverse DNS' : `.${tld}`}.`,
			},
			{
				id: 'resolver-tld',
				source: 'resolver',
				target: 'tld',
				title: 'Resolver fragt den TLD-Server',
				detail: `Bitte die zuständige Zone für ${normalizedDomain}.`,
			},
			{
				id: 'tld-resolver',
				source: 'tld',
				target: 'resolver',
				title: 'TLD liefert den autoritativen Nameserver',
				detail: `Referral zu ${resolution.authoritative || authoritativeServer}.`,
			},
			{
				id: 'resolver-authoritative',
				source: 'resolver',
				target: 'authoritative',
				title: 'Resolver fragt den autoritativen Server',
				detail: summarizeAuthoritativeAnswer(normalizedDomain, normalizedRecordType, resolution),
			},
			{
				id: 'resolver-client',
				source: 'resolver',
				target: 'client',
				title: 'Antwort geht zurück an den Client',
				detail: summarizeClientAnswer(normalizedDomain, normalizedRecordType, resolution),
			},
		],
	}
}

export function resolveRecordTypeExample(recordType, dataset = DNS_SIMULATION_DATA) {
	const normalizedRecordType = normalizeRecordType(recordType)
	if (!normalizedRecordType) {
		return resolveDnsLookup('', '', dataset)
	}

	const catalogEntry = RECORD_TYPE_CATALOG.find(entry => entry.id === normalizedRecordType)
	return resolveDnsLookup(catalogEntry.exampleDomain, normalizedRecordType, dataset)
}
