import {
	calculateSubnet,
	ipToString,
	parseInput,
	prefixToMask,
	vlsmAllocate,
} from './subnetMath.js'
import {
	calculateIPv6Subnet,
	formatIPv6,
	ipv6AddressType,
	parseIPv6,
} from './ipv6Math.js'

function usableHostCount(prefix) {
	if (prefix >= 31) return 0
	return Math.pow(2, 32 - prefix) - 2
}

function smallestPrefixForHosts(hosts) {
	for (let prefix = 32; prefix >= 0; prefix--) {
		if (usableHostCount(prefix) >= hosts) return prefix
	}
	return 0
}

function blockSizeForHosts(hosts) {
	let size = 1
	const needed = Math.max(0, Number(hosts)) + 2
	while (size < needed) size *= 2
	return size
}

function parseCidr(input) {
	const parsed = parseInput(input)
	if (!parsed) {
		throw new Error(`Invalid CIDR input for scenario: ${input}`)
	}
	return parsed
}

function calculateFromCidr(input) {
	const parsed = parseCidr(input)
	return calculateSubnet(parsed.ip, parsed.prefix)
}

function calculateFromBaseIp(baseIp, prefix) {
	const result = calculateSubnet(baseIp, prefix)
	if (!result) {
		throw new Error(`Invalid subnet calculation for ${baseIp}/${prefix}`)
	}
	return result
}

function pickSubnetAnswers(result, fields) {
	const values = {
		networkAddress: ipToString(result.network),
		broadcast: ipToString(result.broadcast),
		firstHost: ipToString(result.firstHost),
		lastHost: ipToString(result.lastHost),
		hostCount: String(result.hostCount),
		subnetMask: ipToString(result.mask),
		cidr: `/${result.prefix}`,
		maxServers: String(Math.max(result.hostCount - 1, 0)),
	}

	return fields.reduce((acc, field) => {
		acc[field] = values[field]
		return acc
	}, {})
}

function pickVlsmAnswers(spec) {
	const parsed = parseCidr(spec.supernet)
	const requirements = spec.requirements.map((requirement) => ({
		name: requirement.name,
		hosts: requirement.hosts,
	}))
	const base = calculateSubnet(parsed.ip, parsed.prefix)
	const allocations = base ? vlsmAllocate(base.network, base.prefix, requirements) : null
	const largestHosts = Math.max(...requirements.map((requirement) => requirement.hosts))
	const largestPrefix = smallestPrefixForHosts(largestHosts)
	const totalUsed = requirements.reduce((sum, requirement) => sum + blockSizeForHosts(requirement.hosts), 0)

	const values = {
		cidr: `/${largestPrefix}`,
		subnetMask: ipToString(prefixToMask(largestPrefix)),
		hostCount: String(usableHostCount(largestPrefix)),
		fitsInSupernet: allocations ? 'Ja' : 'Nein',
		totalUsed: String(totalUsed),
	}

	return spec.fields.reduce((acc, field) => {
		acc[field] = values[field]
		return acc
	}, {})
}

function pickIPv6Answers(spec) {
	if (spec.kind === 'ipv6SubnetCount') {
		const subnetCount = 1n << BigInt(spec.targetPrefix - spec.basePrefix)
		return {
			subnetCount: subnetCount.toString(),
		}
	}

	const parsed = parseIPv6(spec.input)
	if (!parsed) {
		throw new Error(`Invalid IPv6 input for scenario: ${spec.input}`)
	}
	const address = parsed.groups.map((group) => group.toString(16)).join(':')
	const result = calculateIPv6Subnet(address, parsed.prefix)
	if (!result) {
		throw new Error(`Invalid IPv6 subnet calculation for ${spec.input}`)
	}

	const values = {
		networkAddress: formatIPv6(result.networkGroups),
		addressType: ipv6AddressType(parsed.groups),
		subnetCount: spec.targetPrefix == null ? null : (1n << BigInt(spec.targetPrefix - parsed.prefix)).toString(),
	}

	return spec.fields.reduce((acc, field) => {
		acc[field] = values[field]
		return acc
	}, {})
}

function buildExpectedAnswers(spec) {
	switch (spec.kind) {
	case 'subnetFromCidr':
		return pickSubnetAnswers(calculateFromCidr(spec.input), spec.fields)
	case 'hostSizing': {
		const prefix = smallestPrefixForHosts(spec.requiredHosts)
		return pickSubnetAnswers(calculateFromBaseIp(spec.baseIp, prefix), spec.fields)
	}
	case 'vlsmSizing':
	case 'vlsmFit':
		return pickVlsmAnswers(spec)
	case 'ipv6Network':
	case 'ipv6Type':
	case 'ipv6SubnetCount':
		return pickIPv6Answers(spec)
	default:
		throw new Error(`Unsupported scenario kind: ${spec.kind}`)
	}
}

function createScenario(spec) {
	return {
		id: spec.id,
		type: spec.type,
		difficulty: spec.difficulty,
		question: spec.question,
		context: spec.context || null,
		expectedAnswers: buildExpectedAnswers(spec),
	}
}

export const SCENARIO_SPECS = [
	{
		id: 'subnet-easy-01',
		type: 'subnet',
		difficulty: 'easy',
		kind: 'subnetFromCidr',
		input: '192.168.10.0/26',
		fields: ['networkAddress', 'broadcast', 'hostCount'],
		question: 'Berechne für 192.168.10.0/26 die Netzadresse, den Broadcast und die nutzbaren Hosts.',
		context: 'Eingabe: 192.168.10.0/26',
	},
	{
		id: 'subnet-easy-02',
		type: 'subnet',
		difficulty: 'easy',
		kind: 'subnetFromCidr',
		input: '10.1.4.0/27',
		fields: ['broadcast', 'hostCount', 'subnetMask'],
		question: 'Welcher Broadcast, wie viele Hosts und welche Maske gehören zu 10.1.4.0/27?',
		context: 'Eingabe: 10.1.4.0/27',
	},
	{
		id: 'subnet-easy-03',
		type: 'subnet',
		difficulty: 'easy',
		kind: 'subnetFromCidr',
		input: '172.16.8.0/25',
		fields: ['firstHost', 'lastHost', 'hostCount'],
		question: 'Bestimme den ersten Host, den letzten Host und die Host-Anzahl für 172.16.8.0/25.',
		context: 'Eingabe: 172.16.8.0/25',
	},
	{
		id: 'subnet-easy-04',
		type: 'subnet',
		difficulty: 'easy',
		kind: 'subnetFromCidr',
		input: '192.168.77.128/28',
		fields: ['networkAddress', 'broadcast', 'hostCount'],
		question: 'Berechne Netzadresse, Broadcast und Host-Anzahl für 192.168.77.128/28.',
		context: 'Eingabe: 192.168.77.128/28',
	},
	{
		id: 'subnet-easy-05',
		type: 'subnet',
		difficulty: 'easy',
		kind: 'subnetFromCidr',
		input: '10.20.30.64/30',
		fields: ['broadcast', 'hostCount', 'subnetMask'],
		question: 'Ein Punkt-zu-Punkt-Link nutzt 10.20.30.64/30. Welche Maske, welcher Broadcast und wie viele Hosts sind möglich?',
		context: 'Eingabe: 10.20.30.64/30',
	},
	{
		id: 'subnet-medium-01',
		type: 'subnet',
		difficulty: 'medium',
		kind: 'hostSizing',
		baseIp: '192.168.30.0',
		requiredHosts: 254,
		fields: ['cidr', 'broadcast', 'subnetMask'],
		question: 'Ein Netzwerk mit 253 PCs und 1 Router-Interface startet bei 192.168.30.0. Welches CIDR und welche Broadcast-Adresse?',
		context: 'Hostbedarf: 254 | Basis: 192.168.30.0',
	},
	{
		id: 'subnet-medium-02',
		type: 'subnet',
		difficulty: 'medium',
		kind: 'subnetFromCidr',
		input: '1.2.3.67/28',
		fields: ['networkAddress', 'broadcast', 'hostCount', 'maxServers'],
		question: 'Ein Server hat die IP 1.2.3.67/28. Was ist die Netzadresse, wie viele Server passen maximal rein (abzüglich Broadcast und Router)?',
		context: 'Eingabe: 1.2.3.67/28 | 1 Router reserviert',
	},
	{
		id: 'subnet-medium-03',
		type: 'subnet',
		difficulty: 'medium',
		kind: 'hostSizing',
		baseIp: '172.16.0.0',
		requiredHosts: 509,
		fields: ['cidr', 'subnetMask', 'hostCount'],
		question: 'Ein Netzwerk für 509 PCs startet bei 172.16.0.0. Welches CIDR ist das kleinste passende?',
		context: 'Hostbedarf: 509 | Basis: 172.16.0.0',
	},
	{
		id: 'subnet-medium-04',
		type: 'subnet',
		difficulty: 'medium',
		kind: 'subnetFromCidr',
		input: '10.1.2.67/20',
		fields: ['networkAddress', 'broadcast', 'cidr'],
		question: 'Eine Host-IP 10.1.2.67/20 liegt nicht auf einer Netzgrenze. Bestimme Netzadresse, Broadcast und CIDR.',
		context: 'Eingabe: 10.1.2.67/20',
	},
	{
		id: 'subnet-medium-05',
		type: 'subnet',
		difficulty: 'medium',
		kind: 'subnetFromCidr',
		input: '192.168.99.200/27',
		fields: ['networkAddress', 'firstHost', 'lastHost', 'broadcast'],
		question: 'Bestimme für 192.168.99.200/27 Netzadresse, ersten Host, letzten Host und Broadcast.',
		context: 'Eingabe: 192.168.99.200/27',
	},
	{
		id: 'subnet-hard-01',
		type: 'subnet',
		difficulty: 'hard',
		kind: 'subnetFromCidr',
		input: '10.200.14.199/21',
		fields: ['networkAddress', 'broadcast', 'firstHost', 'lastHost'],
		question: 'Eine Monitoring-VM nutzt 10.200.14.199/21. Berechne Netzadresse, Broadcast sowie ersten und letzten Host.',
		context: 'Eingabe: 10.200.14.199/21',
	},
	{
		id: 'subnet-hard-02',
		type: 'subnet',
		difficulty: 'hard',
		kind: 'subnetFromCidr',
		input: '172.31.255.254/31',
		fields: ['networkAddress', 'broadcast', 'hostCount', 'cidr'],
		question: 'Ein WAN-Link nutzt 172.31.255.254/31. Welche Netzadresse, welcher Broadcast und wie viele klassische Hosts ergeben sich?',
		context: 'Eingabe: 172.31.255.254/31',
	},
	{
		id: 'subnet-hard-03',
		type: 'subnet',
		difficulty: 'hard',
		kind: 'subnetFromCidr',
		input: '192.0.2.17/32',
		fields: ['networkAddress', 'broadcast', 'hostCount'],
		question: 'Eine Firewall-Regel referenziert 192.0.2.17/32. Bestimme Netzadresse, Broadcast und nutzbare Hosts.',
		context: 'Eingabe: 192.0.2.17/32',
	},
	{
		id: 'subnet-hard-04',
		type: 'subnet',
		difficulty: 'hard',
		kind: 'subnetFromCidr',
		input: '198.51.100.130/26',
		fields: ['networkAddress', 'broadcast', 'firstHost', 'lastHost'],
		question: 'Ein Backup-Server hängt an 198.51.100.130/26. Berechne Netzadresse, Broadcast, ersten und letzten Host.',
		context: 'Eingabe: 198.51.100.130/26',
	},
	{
		id: 'subnet-hard-05',
		type: 'subnet',
		difficulty: 'hard',
		kind: 'subnetFromCidr',
		input: '203.0.113.255/29',
		fields: ['networkAddress', 'broadcast', 'firstHost', 'lastHost'],
		question: 'Eine DMZ-Adresse 203.0.113.255/29 liegt am Ende eines Blocks. Berechne Netzadresse, Broadcast, ersten und letzten Host.',
		context: 'Eingabe: 203.0.113.255/29',
	},
	{
		id: 'praxis-easy-01',
		type: 'praxis',
		difficulty: 'easy',
		kind: 'hostSizing',
		baseIp: '192.168.50.0',
		requiredHosts: 13,
		fields: ['cidr', 'subnetMask', 'hostCount'],
		question: 'Ein kleines Büro hat 12 Clients und 1 Router-Interface im Netz 192.168.50.0. Welches minimale CIDR wird benötigt?',
		context: 'Hostbedarf: 13 | Basis: 192.168.50.0',
	},
	{
		id: 'praxis-medium-01',
		type: 'praxis',
		difficulty: 'medium',
		kind: 'hostSizing',
		baseIp: '192.168.0.0',
		requiredHosts: 30,
		fields: ['cidr', 'subnetMask', 'hostCount'],
		question: 'Eine Berufsschule hat 15 Klassen mit je 29 PCs und 1 Router-Interface pro Klasse. Verwende den privaten Bereich ab 192.168.0.0. Welche Subnetzmaske braucht jede Klasse?',
		context: 'Pro Klasse: 30 Hosts | Basis: 192.168.0.0',
	},
	{
		id: 'praxis-medium-02',
		type: 'praxis',
		difficulty: 'medium',
		kind: 'hostSizing',
		baseIp: '192.168.0.0',
		requiredHosts: 30000,
		fields: ['cidr', 'subnetMask', 'hostCount'],
		question: 'Ein Firmencampus hat 30.000 mobile Geraete im Bereich 192.168.0.0/16. Was ist das kleinste Subnetz das alle Geraete aufnimmt?',
		context: 'Hostbedarf: 30000 | Supernetz: 192.168.0.0/16',
	},
	{
		id: 'praxis-hard-01',
		type: 'praxis',
		difficulty: 'hard',
		kind: 'hostSizing',
		baseIp: '10.0.0.0',
		requiredHosts: 200,
		fields: ['cidr', 'subnetMask', 'hostCount'],
		question: 'Du planst eine AWS VPC mit 3 Availability Zones. Jede AZ braucht ein Public-Subnet (max 50 Hosts) und ein Private-Subnet (max 200 Hosts). Zusätzlich ein /28 für NAT Gateways. Basis: 10.0.0.0/16. Welchen Prefix braucht das größte Subnetz?',
		context: 'Größter Hostbedarf: 200 | Basis: 10.0.0.0/16',
	},
	{
		id: 'praxis-hard-02',
		type: 'praxis',
		difficulty: 'hard',
		kind: 'hostSizing',
		baseIp: '10.0.0.0',
		requiredHosts: 1048574,
		fields: ['cidr', 'hostCount'],
		question: 'Ein Kubernetes-Cluster nutzt 10.0.0.0/8 aufgeteilt in: Pod-CIDR /12, Service-CIDR /24, Node-CIDR /28. Wie viele Pods passen maximal ins Pod-Netz?',
		context: 'Pod-CIDR: /12 aus 10.0.0.0/8',
	},
	{
		id: 'praxis-hard-03',
		type: 'praxis',
		difficulty: 'hard',
		kind: 'hostSizing',
		baseIp: '172.16.0.0',
		requiredHosts: 500,
		fields: ['cidr', 'subnetMask', 'hostCount'],
		question: 'Ein Smart-Campus hat 500 Sensoren, 50 Kameras und 200 Steuergeräte. Jede Gruppe bekommt ein eigenes Subnetz aus 172.16.0.0/16. Welchen Prefix braucht das Sensor-Subnetz?',
		context: 'Größter Hostbedarf: 500 | Basis: 172.16.0.0/16',
	},
	{
		id: 'vlsm-easy-01',
		type: 'vlsm',
		difficulty: 'easy',
		kind: 'vlsmSizing',
		supernet: '10.0.0.0/24',
		requirements: [
			{ name: 'LAN A', hosts: 100 },
			{ name: 'LAN B', hosts: 50 },
		],
		fields: ['cidr', 'totalUsed'],
		question: 'Ein /24-Netz wird in 100 Hosts und 50 Hosts geteilt. Welchen Prefix bekommt das größere Subnetz und wie viele Adressen werden insgesamt belegt?',
		context: 'Supernetz: 10.0.0.0/24 | Hosts: 100, 50',
	},
	{
		id: 'vlsm-easy-02',
		type: 'vlsm',
		difficulty: 'easy',
		kind: 'vlsmFit',
		supernet: '192.168.10.0/24',
		requirements: [
			{ name: 'A', hosts: 20 },
			{ name: 'B', hosts: 20 },
			{ name: 'C', hosts: 20 },
			{ name: 'D', hosts: 20 },
		],
		fields: ['cidr', 'fitsInSupernet', 'totalUsed'],
		question: 'Ein /24 wird in vier gleich große Netze für je 20 Hosts aufgeteilt. Welcher Prefix wird benötigt, passt alles hinein und wie viele Adressen werden belegt?',
		context: 'Supernetz: 192.168.10.0/24 | Hosts: 20, 20, 20, 20',
	},
	{
		id: 'vlsm-medium-01',
		type: 'vlsm',
		difficulty: 'medium',
		kind: 'vlsmFit',
		supernet: '172.20.0.0/23',
		requirements: [
			{ name: 'App', hosts: 120 },
			{ name: 'DB', hosts: 60 },
			{ name: 'Mgmt', hosts: 30 },
		],
		fields: ['cidr', 'fitsInSupernet', 'totalUsed'],
		question: 'Ein /23-Netz wird per VLSM in 120, 60 und 30 Hosts geteilt. Welcher Prefix wird für das größte Netz benötigt, passt alles und wie viele Adressen werden belegt?',
		context: 'Supernetz: 172.20.0.0/23 | Hosts: 120, 60, 30',
	},
	{
		id: 'vlsm-medium-02',
		type: 'vlsm',
		difficulty: 'medium',
		kind: 'vlsmFit',
		supernet: '192.0.2.0/25',
		requirements: [
			{ name: 'Ops', hosts: 50 },
			{ name: 'Voice', hosts: 30 },
			{ name: 'Printer', hosts: 10 },
		],
		fields: ['cidr', 'fitsInSupernet', 'totalUsed'],
		question: 'Ein /25-Netz soll 50 Hosts, 30 Hosts und 10 Hosts aufnehmen. Welcher Prefix wird für das größte Netz benötigt, passt alles und wie viele Adressen werden belegt?',
		context: 'Supernetz: 192.0.2.0/25 | Hosts: 50, 30, 10',
	},
	{
		id: 'vlsm-hard-01',
		type: 'vlsm',
		difficulty: 'hard',
		kind: 'vlsmFit',
		supernet: '10.10.0.0/22',
		requirements: [
			{ name: 'HR', hosts: 60 },
			{ name: 'Dev', hosts: 60 },
			{ name: 'Finance', hosts: 60 },
			{ name: 'Management', hosts: 5 },
			{ name: 'Drucker', hosts: 10 },
		],
		fields: ['fitsInSupernet', 'totalUsed'],
		question: 'Ein Firmennetz 10.10.0.0/22 wird aufgeteilt: HR braucht 60 Hosts, Dev 60 Hosts, Finance 60 Hosts, Management 5 Hosts, Drucker 10 Hosts. Passt alles rein?',
		context: 'Supernetz: 10.10.0.0/22 | Hosts: 60, 60, 60, 5, 10',
	},
	{
		id: 'vlsm-hard-02',
		type: 'vlsm',
		difficulty: 'hard',
		kind: 'vlsmSizing',
		supernet: '10.10.0.0/16',
		requirements: [
			{ name: 'Zentrale', hosts: 500 },
			{ name: 'Filiale POS', hosts: 30 },
			{ name: 'Filiale WLAN', hosts: 100 },
			{ name: 'Filiale VoIP', hosts: 20 },
		],
		fields: ['cidr', 'hostCount'],
		question: 'Eine Zentrale und 12 Filialen teilen sich 10.10.0.0/16. Jede Filiale braucht POS (30 Hosts), WLAN-Gast (100 Hosts), VoIP (20 Hosts). Die Zentrale braucht 500 Hosts. Welchen Prefix braucht die Zentrale?',
		context: 'Supernetz: 10.10.0.0/16 | Größter Hostbedarf: 500',
	},
	{
		id: 'ipv6-easy-01',
		type: 'ipv6',
		difficulty: 'easy',
		kind: 'ipv6Type',
		input: 'fe80::1234/64',
		fields: ['networkAddress', 'addressType'],
		question: 'Welche Netzadresse und welcher Adresstyp gehören zu fe80::1234/64?',
		context: 'Eingabe: fe80::1234/64',
	},
	{
		id: 'ipv6-easy-02',
		type: 'ipv6',
		difficulty: 'easy',
		kind: 'ipv6Type',
		input: 'fd00:abcd::1/64',
		fields: ['networkAddress', 'addressType'],
		question: 'Welche Netzadresse und welcher Adresstyp gehören zu fd00:abcd::1/64?',
		context: 'Eingabe: fd00:abcd::1/64',
	},
	{
		id: 'ipv6-medium-01',
		type: 'ipv6',
		difficulty: 'medium',
		kind: 'ipv6Network',
		input: '2001:db8:1234:5678::abcd/64',
		fields: ['networkAddress', 'addressType'],
		question: 'Bestimme die Netzadresse und den Adresstyp von 2001:db8:1234:5678::abcd/64.',
		context: 'Eingabe: 2001:db8:1234:5678::abcd/64',
	},
	{
		id: 'ipv6-medium-02',
		type: 'ipv6',
		difficulty: 'medium',
		kind: 'ipv6SubnetCount',
		basePrefix: 56,
		targetPrefix: 64,
		fields: ['subnetCount'],
		question: 'Ein Standort bekommt 2001:db8:beef::/56. Wie viele /64-Subnetze können daraus gebildet werden?',
		context: 'Supernetz: /56 | Zielprefix: /64',
	},
	{
		id: 'ipv6-hard-01',
		type: 'ipv6',
		difficulty: 'hard',
		kind: 'ipv6SubnetCount',
		basePrefix: 48,
		targetPrefix: 64,
		fields: ['subnetCount'],
		question: 'Ein Unternehmen hat 2001:db8:cafe::/48 und braucht Subnetze für 4 Standorte. Wie viele /64-Subnetze sind verfügbar?',
		context: 'Supernetz: /48 | Zielprefix: /64',
	},
]

export const SCENARIOS = SCENARIO_SPECS.map(createScenario)

export default SCENARIOS
