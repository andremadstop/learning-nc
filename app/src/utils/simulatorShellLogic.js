/**
 * SimulatorShell logic — extracted for testability without Vue SFC parsing.
 *
 * Used by SimulatorShell.vue and tested directly via Vitest.
 */

import firewallScenarios from '../../data/firewall_scenarios.json'
import dnsScenarios from '../../data/dns_scenarios.json'
import routingScenarios from '../../data/routing_scenarios.json'
import natScenarios from '../../data/nat_scenarios.json'
import portscanScenarios from '../../data/portscan_scenarios.json'
import packetCaptures from '../../data/packet_captures.json'
import authflowScenarios from '../../data/authflow_scenarios.json'
import { SCENARIOS as subnetScenarios } from './scenarios.js'

export const SIMULATOR_MAP = {
	firewall: () => import('../components/FirewallBuilder.vue'),
	dns: () => import('../components/DnsResolver.vue'),
	routing: () => import('../components/RoutingTable.vue'),
	nat: () => import('../components/NatTable.vue'),
	portscan: () => import('../components/PortScanner.vue'),
	wireshark: () => import('../components/WiresharkLite.vue'),
	authflow: () => import('../components/AuthFlowSimulator.vue'),
	terminal: () => import('../components/TerminalPuzzle.vue'),
	subnet: () => import('../components/SubnetCalculator.vue'),
}

export const SCENARIOS = {
	firewall: firewallScenarios,
	dns: dnsScenarios,
	routing: routingScenarios,
	nat: natScenarios,
	portscan: portscanScenarios,
	wireshark: packetCaptures.scenarios,
	authflow: authflowScenarios,
	subnet: subnetScenarios,
}

/**
 * Resolve scenario: override takes precedence, then lookup by id.
 * @param {string} type - Simulator type key
 * @param {string} scenarioId - Scenario ID to look up
 * @param {object|null} scenarioOverride - Direct scenario object (takes precedence)
 * @returns {object|null}
 */
export function resolveScenario(type, scenarioId, scenarioOverride) {
	if (scenarioOverride) return scenarioOverride
	const list = SCENARIOS[type] || []
	return list.find(s => s.id === scenarioId) || null
}

/**
 * Normalize divergent @result payloads into a uniform (passed, score, rawResult) triple.
 * Returns null if the event should be ignored (e.g. DNS free-lookup).
 * @param {object} rawResult - Raw result from simulator component
 * @returns {{ passed: boolean, score: number, rawResult: object }|null}
 */
export function normalizeResult(rawResult) {
	if (rawResult.kind === 'lookup') return null

	let correct = false
	if (typeof rawResult.correct === 'boolean') {
		correct = rawResult.correct
	} else if (typeof rawResult.passed === 'boolean') {
		correct = rawResult.passed
	}
	const score = correct ? 1.0 : 0.0
	return { passed: correct, score, rawResult }
}
