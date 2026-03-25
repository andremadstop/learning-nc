const SERVICE_DB = Object.freeze({
	22: { service: 'SSH', version: 'OpenSSH 9.6' },
	23: { service: 'Telnet', version: 'BusyBox telnetd' },
	25: { service: 'SMTP', version: 'Postfix 3.8' },
	53: { service: 'DNS', version: 'BIND 9.18' },
	80: { service: 'HTTP', version: 'nginx 1.24' },
	88: { service: 'Kerberos', version: 'Active Directory KDC' },
	110: { service: 'POP3', version: 'Dovecot 2.3' },
	123: { service: 'NTP', version: 'chronyd' },
	135: { service: 'RPC', version: 'Microsoft RPC' },
	137: { service: 'NetBIOS', version: 'NetBIOS Name Service' },
	138: { service: 'NetBIOS', version: 'Datagram Service' },
	139: { service: 'NetBIOS', version: 'Session Service' },
	143: { service: 'IMAP', version: 'Dovecot 2.3' },
	161: { service: 'SNMP', version: 'SNMPv2 agent' },
	389: { service: 'LDAP', version: 'Active Directory LDAP' },
	443: { service: 'HTTPS', version: 'nginx 1.24 TLS' },
	445: { service: 'SMB', version: 'SMB 3.1.1' },
	636: { service: 'LDAPS', version: 'Secure LDAP' },
	993: { service: 'IMAPS', version: 'Dovecot 2.3 TLS' },
	1194: { service: 'OpenVPN', version: 'OpenVPN 2.6' },
	1433: { service: 'MSSQL', version: 'SQL Server 2022' },
	3306: { service: 'MySQL', version: 'MySQL 8.0' },
	3389: { service: 'RDP', version: 'Windows Server RDP' },
	4444: { service: 'Meterpreter', version: 'Suspicious reverse shell' },
	5432: { service: 'PostgreSQL', version: 'PostgreSQL 16' },
	8080: { service: 'HTTP-Alt', version: 'Tomcat 10' },
	8443: { service: 'HTTPS-Alt', version: 'Reverse proxy' },
	31337: { service: 'Backdoor', version: 'Legacy backdoor listener' },
})

const HOST_PROFILES = Object.freeze([
	{
		id: 'webserver',
		label: 'Webserver',
		ports: [
			{ port: 22, state: 'open' },
			{ port: 80, state: 'open' },
			{ port: 443, state: 'open' },
		],
	},
	{
		id: 'mailserver',
		label: 'Mailserver',
		ports: [
			{ port: 25, state: 'open' },
			{ port: 110, state: 'open' },
			{ port: 143, state: 'open' },
			{ port: 443, state: 'open' },
			{ port: 993, state: 'open' },
		],
	},
	{
		id: 'domain-controller',
		label: 'Domain Controller',
		ports: [
			{ port: 53, state: 'open' },
			{ port: 88, state: 'open' },
			{ port: 389, state: 'open' },
			{ port: 636, state: 'open' },
			{ port: 445, state: 'open' },
			{ port: 3389, state: 'open' },
		],
	},
	{
		id: 'network-device',
		label: 'Router/Switch',
		ports: [
			{ port: 22, state: 'open' },
			{ port: 23, state: 'open' },
			{ port: 161, state: 'open' },
			{ port: 443, state: 'open' },
		],
	},
	{
		id: 'database',
		label: 'Datenbank',
		ports: [
			{ port: 22, state: 'open' },
			{ port: 5432, state: 'open' },
		],
	},
	{
		id: 'compromised-host',
		label: 'Kompromittierter Host',
		ports: [
			{ port: 22, state: 'open' },
			{ port: 80, state: 'open' },
			{ port: 443, state: 'open' },
			{ port: 4444, state: 'open' },
			{ port: 31337, state: 'open' },
		],
	},
])

export function getServiceInfo(port) {
	return SERVICE_DB[Number(port)] || { service: 'Unknown', version: 'Unclassified service' }
}

export function getHostProfiles() {
	return HOST_PROFILES.map((profile) => ({
		...profile,
		ports: profile.ports.map((entry) => ({ ...entry })),
	}))
}

export function explainScanType(scanType) {
	const normalized = String(scanType || 'syn').trim().toLowerCase()
	const catalog = {
		syn: 'SYN scan: sends half-open TCP SYN probes and watches for SYN-ACK or RST.',
		connect: 'Connect scan: completes the full TCP handshake and is easiest to detect.',
		udp: 'UDP scan: sends UDP probes and interprets ICMP unreachable responses carefully.',
		fin: 'FIN scan: uses TCP FIN packets to infer state on RFC-compliant stacks.',
	}
	return catalog[normalized] || catalog.syn
}

export function buildScanResults(profileId, overrides = {}) {
	const profile = getHostProfiles().find((entry) => entry.id === profileId)
	if (!profile) {
		throw new Error(`Unknown host profile: ${profileId}`)
	}

	const overrideMap = new Map((overrides.ports || []).map((entry) => [Number(entry.port), entry]))
	const combined = new Map(profile.ports.map((entry) => [Number(entry.port), entry]))
	overrideMap.forEach((value, key) => combined.set(key, value))

	const results = [...combined.values()]
		.map((entry) => {
			const serviceInfo = getServiceInfo(entry.port)
			return {
				port: Number(entry.port),
				state: String(entry.state || 'closed'),
				service: entry.service || serviceInfo.service,
				version: entry.version || serviceInfo.version,
			}
		})
		.sort((left, right) => left.port - right.port)

	return {
		profileId: profile.id,
		label: profile.label,
		scanType: String(overrides.scanType || 'syn'),
		durationMs: Number(overrides.durationMs || 2000),
		ports: results,
	}
}

export function detectHostType(scanResults) {
	const openPorts = (scanResults.ports || [])
		.filter((entry) => entry.state === 'open')
		.map((entry) => entry.port)

	if (openPorts.includes(4444) || openPorts.includes(31337)) {
		return 'compromised-host'
	}
	if (openPorts.includes(53) && openPorts.includes(88) && openPorts.includes(389)) {
		return 'domain-controller'
	}
	if (openPorts.includes(25) && openPorts.includes(143)) {
		return 'mailserver'
	}
	if (openPorts.includes(161) || openPorts.includes(23)) {
		return 'network-device'
	}
	if (openPorts.includes(3306) || openPorts.includes(5432) || openPorts.includes(1433)) {
		return 'database'
	}
	if (openPorts.includes(80) || openPorts.includes(443)) {
		return 'webserver'
	}
	return 'unknown'
}

export function findCompromiseIndicators(scanResults) {
	const suspiciousPorts = [4444, 31337]
	return (scanResults.ports || []).filter((entry) => suspiciousPorts.includes(entry.port) && entry.state === 'open')
}

export function buildScanTimeline(scanResults) {
	return (scanResults.ports || []).map((entry, index) => ({
		...entry,
		revealAtMs: Math.round(((index + 1) / Math.max(1, scanResults.ports.length)) * scanResults.durationMs),
	}))
}
