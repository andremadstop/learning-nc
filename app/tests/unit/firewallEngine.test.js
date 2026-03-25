import { describe, expect, it } from 'vitest'
import {
	createImplicitDenyRule,
	createRule,
	evaluatePacket,
	getRulesWithImplicitDeny,
	isIpInCidr,
	portMatches,
	protocolMatches,
	reorderRules,
	ruleMatches,
	summarizePolicy,
} from '../../src/utils/firewallEngine.js'

describe('isIpInCidr', () => {
	it.each([
		['10.0.1.10', '10.0.1.0/24', true],
		['10.0.2.10', '10.0.1.0/24', false],
		['172.16.0.5', '172.16.0.5', true],
		['172.16.0.6', '172.16.0.5', false],
		['198.51.100.1', 'any', true],
	])('matches %s against %s', (ip, cidr, expected) => {
		expect(isIpInCidr(ip, cidr)).toBe(expected)
	})
})

describe('portMatches', () => {
	it.each([
		['any', 443, true],
		['22', 22, true],
		['22', 23, false],
		['80,443', 443, true],
		['80,443', 25, false],
		['1000-2000', 1500, true],
		['1000-2000', 999, false],
	])('evaluates %s for port %i', (expression, port, expected) => {
		expect(portMatches(expression, port)).toBe(expected)
	})
})

describe('protocolMatches', () => {
	it.each([
		['any', 'tcp', true],
		['tcp', 'tcp', true],
		['udp', 'tcp', false],
	])('evaluates %s against %s', (ruleProtocol, packetProtocol, expected) => {
		expect(protocolMatches(ruleProtocol, packetProtocol)).toBe(expected)
	})
})

describe('ruleMatches', () => {
	const packet = { srcIp: '10.0.1.10', srcPort: 53022, dstIp: '172.16.0.1', dstPort: 22, protocol: 'tcp' }

	it('matches a rule with exact criteria', () => {
		expect(ruleMatches(createRule({
			action: 'allow',
			protocol: 'tcp',
			srcIp: '10.0.1.0/24',
			dstIp: '172.16.0.1',
			dstPort: '22',
		}), packet)).toBe(true)
	})

	it('rejects a packet when protocol differs', () => {
		expect(ruleMatches(createRule({ protocol: 'udp', srcIp: 'any', dstIp: 'any', dstPort: '22' }), packet)).toBe(false)
	})

	it('rejects a packet when source network differs', () => {
		expect(ruleMatches(createRule({ protocol: 'tcp', srcIp: '10.0.2.0/24', dstIp: '172.16.0.1', dstPort: '22' }), packet)).toBe(false)
	})
})

describe('evaluatePacket', () => {
	const rules = [
		createRule({ id: 'deny-admin', action: 'deny', protocol: 'tcp', srcIp: '198.51.100.0/24', dstIp: '172.16.0.1', dstPort: '22' }),
		createRule({ id: 'allow-admin', action: 'allow', protocol: 'tcp', srcIp: '10.0.1.0/24', dstIp: '172.16.0.1', dstPort: '22', log: true }),
	]

	it('uses first-match semantics', () => {
		const result = evaluatePacket(rules, { srcIp: '198.51.100.10', srcPort: 50000, dstIp: '172.16.0.1', dstPort: 22, protocol: 'tcp' })
		expect(result.action).toBe('deny')
		expect(result.matchedRule.id).toBe('deny-admin')
	})

	it('returns the first allow match when deny does not fit', () => {
		const result = evaluatePacket(rules, { srcIp: '10.0.1.55', srcPort: 50000, dstIp: '172.16.0.1', dstPort: 22, protocol: 'tcp' })
		expect(result.action).toBe('allow')
		expect(result.matchedRule.id).toBe('allow-admin')
		expect(result.log).toBe(true)
	})

	it('falls back to implicit deny', () => {
		const result = evaluatePacket(rules, { srcIp: '10.0.2.55', srcPort: 50000, dstIp: '172.16.0.1', dstPort: 22, protocol: 'tcp' })
		expect(result.action).toBe('deny')
		expect(result.matchedRule.id).toBe('implicit-deny')
	})
})

describe('rule set helpers', () => {
	it('always appends implicit deny', () => {
		expect(getRulesWithImplicitDeny([createRule({ id: 'allow-web', action: 'allow' })]).at(-1)).toEqual(createImplicitDenyRule())
	})

	it('reorders rules without mutating input', () => {
		const rules = [createRule({ id: 'r1' }), createRule({ id: 'r2' }), createRule({ id: 'r3' })]
		const reordered = reorderRules(rules, 0, 2)
		expect(rules[0].id).toBe('r1')
		expect(reordered.map((rule) => rule.id)).toEqual(['r2', 'r3', 'r1'])
	})

	it('summarizes the policy including implicit deny', () => {
		const summary = summarizePolicy([createRule({ id: 'allow-web', action: 'allow', protocol: 'tcp', dstPort: '443' })])
		expect(summary).toHaveLength(2)
		expect(summary[0].summary).toContain('ALLOW TCP')
		expect(summary[1].implicit).toBe(true)
	})
})
