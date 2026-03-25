const ANY = 'any'

function ipToInt(ip) {
	return String(ip || '')
		.trim()
		.split('.')
		.map((part) => Number(part))
		.reduce((total, part) => ((total << 8) >>> 0) + part, 0) >>> 0
}

function isValidIpv4(ip) {
	return String(ip || '')
		.trim()
		.split('.')
		.every((part) => part !== '' && Number(part) >= 0 && Number(part) <= 255)
		&& String(ip || '').trim().split('.').length === 4
}

function normalizePortExpression(value) {
	if (value === null || value === undefined || value === '') {
		return ANY
	}
	return String(value).trim().toLowerCase()
}

function normalizeRule(rule, index) {
	return {
		id: String(rule.id || `rule-${index + 1}`),
		action: String(rule.action || 'deny').trim().toLowerCase(),
		protocol: String(rule.protocol || ANY).trim().toLowerCase(),
		srcIp: String(rule.srcIp || ANY).trim().toLowerCase(),
		srcPort: normalizePortExpression(rule.srcPort),
		dstIp: String(rule.dstIp || ANY).trim().toLowerCase(),
		dstPort: normalizePortExpression(rule.dstPort),
		log: Boolean(rule.log),
	}
}

export function createRule(overrides = {}) {
	return normalizeRule(overrides, 0)
}

export function createImplicitDenyRule() {
	return {
		id: 'implicit-deny',
		action: 'deny',
		protocol: ANY,
		srcIp: ANY,
		srcPort: ANY,
		dstIp: ANY,
		dstPort: ANY,
		log: false,
		implicit: true,
	}
}

export function getRulesWithImplicitDeny(rules) {
	const normalized = (rules || []).map(normalizeRule)
	return [...normalized, createImplicitDenyRule()]
}

export function isIpInCidr(ip, cidr) {
	const normalizedIp = String(ip || '').trim()
	const normalizedCidr = String(cidr || '').trim().toLowerCase()

	if (normalizedCidr === ANY) {
		return true
	}
	if (!normalizedCidr.includes('/')) {
		return normalizedIp === normalizedCidr
	}
	if (!isValidIpv4(normalizedIp)) {
		return false
	}

	const [networkIp, prefixValue] = normalizedCidr.split('/')
	const prefix = Number(prefixValue)
	if (!isValidIpv4(networkIp) || Number.isNaN(prefix) || prefix < 0 || prefix > 32) {
		return false
	}

	const mask = prefix === 0 ? 0 : ((0xffffffff << (32 - prefix)) >>> 0)
	return (ipToInt(normalizedIp) & mask) === (ipToInt(networkIp) & mask)
}

export function portMatches(rulePort, packetPort) {
	const expression = normalizePortExpression(rulePort)
	const normalizedPort = Number(packetPort)
	if (expression === ANY) {
		return true
	}
	if (!Number.isInteger(normalizedPort) || normalizedPort < 0) {
		return false
	}
	if (expression.includes('-')) {
		const [start, end] = expression.split('-').map((part) => Number(part))
		return normalizedPort >= start && normalizedPort <= end
	}
	if (expression.includes(',')) {
		return expression
			.split(',')
			.map((part) => Number(part.trim()))
			.includes(normalizedPort)
	}
	return Number(expression) === normalizedPort
}

export function protocolMatches(ruleProtocol, packetProtocol) {
	const normalizedRuleProtocol = String(ruleProtocol || ANY).trim().toLowerCase()
	const normalizedPacketProtocol = String(packetProtocol || '').trim().toLowerCase()
	return normalizedRuleProtocol === ANY || normalizedRuleProtocol === normalizedPacketProtocol
}

export function ruleMatches(rule, packet) {
	const normalizedRule = normalizeRule(rule, 0)
	return protocolMatches(normalizedRule.protocol, packet.protocol)
		&& isIpInCidr(packet.srcIp, normalizedRule.srcIp)
		&& isIpInCidr(packet.dstIp, normalizedRule.dstIp)
		&& portMatches(normalizedRule.srcPort, packet.srcPort)
		&& portMatches(normalizedRule.dstPort, packet.dstPort)
}

export function evaluatePacket(rules, packet) {
	const ruleSet = getRulesWithImplicitDeny(rules)
	const trace = ruleSet.map((rule, index) => {
		const matched = ruleMatches(rule, packet)
		return {
			index,
			rule,
			matched,
		}
	})
	const winner = trace.find((entry) => entry.matched) || trace[trace.length - 1]

	return {
		packet,
		trace,
		matchedRule: winner.rule,
		matchedIndex: winner.index,
		action: winner.rule.action,
		log: Boolean(winner.rule.log),
	}
}

export function reorderRules(rules, fromIndex, toIndex) {
	const list = [...(rules || [])]
	if (fromIndex === toIndex || fromIndex < 0 || toIndex < 0 || fromIndex >= list.length || toIndex >= list.length) {
		return list
	}

	const [movedRule] = list.splice(fromIndex, 1)
	list.splice(toIndex, 0, movedRule)
	return list
}

export function summarizePolicy(rules) {
	return getRulesWithImplicitDeny(rules).map((rule, index) => ({
		index,
		id: rule.id,
		summary: `${rule.action.toUpperCase()} ${rule.protocol.toUpperCase()} ${rule.srcIp}:${rule.srcPort} -> ${rule.dstIp}:${rule.dstPort}`,
		implicit: Boolean(rule.implicit),
	}))
}
