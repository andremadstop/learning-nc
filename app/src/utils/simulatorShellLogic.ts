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
import terminalScenarios from '../../data/terminal_scenarios.json'
import { SCENARIOS as subnetScenarios } from './scenarios.js'

import { defineAsyncComponent } from 'vue'

export type SimulatorType =
	| 'firewall'
	| 'dns'
	| 'routing'
	| 'nat'
	| 'portscan'
	| 'wireshark'
	| 'authflow'
	| 'terminal'
	| 'subnet'

export interface SimulatorScenario {
	id?: string
	[key: string]: unknown
}

export interface SimulatorResult {
	kind?: string
	correct?: boolean
	passed?: boolean
	[key: string]: unknown
}

export interface NormalizedSimulatorResult {
	passed: boolean
	score: number
	rawResult: SimulatorResult
}

type ScenarioCollection = SimulatorScenario[] | Record<string, SimulatorScenario>

export const SIMULATOR_MAP: Record<SimulatorType, ReturnType<typeof defineAsyncComponent>> = {
	firewall: defineAsyncComponent(() => import('../components/FirewallBuilder.vue')),
	dns: defineAsyncComponent(() => import('../components/DnsResolver.vue')),
	routing: defineAsyncComponent(() => import('../components/RoutingTable.vue')),
	nat: defineAsyncComponent(() => import('../components/NatTable.vue')),
	portscan: defineAsyncComponent(() => import('../components/PortScanner.vue')),
	wireshark: defineAsyncComponent(() => import('../components/WiresharkLite.vue')),
	authflow: defineAsyncComponent(() => import('../components/AuthFlowSimulator.vue')),
	terminal: defineAsyncComponent(() => import('../components/TerminalPuzzle.vue')),
	subnet: defineAsyncComponent(() => import('../components/SubnetCalculator.vue')),
}

export const SCENARIOS: Record<SimulatorType, ScenarioCollection> = {
	firewall: firewallScenarios,
	dns: dnsScenarios,
	routing: routingScenarios,
	nat: natScenarios,
	portscan: portscanScenarios,
	wireshark: packetCaptures.scenarios,
	authflow: authflowScenarios,
	terminal: terminalScenarios.scenarios,
	subnet: subnetScenarios,
}

/**
 * Resolve scenario: override takes precedence, then lookup by id in an array or keyed map.
 * @param {string} type - Simulator type key
 * @param {string} scenarioId - Scenario ID to look up
 * @param {object|null} scenarioOverride - Direct scenario object (takes precedence)
 * @returns {object|null}
 */
export function resolveScenario(
	type: SimulatorType | string,
	scenarioId: string,
	scenarioOverride: SimulatorScenario | null,
): SimulatorScenario | null {
	if (scenarioOverride) return scenarioOverride
	const collection = SCENARIOS[type as SimulatorType]
	if (!collection || !scenarioId) return null

	if (Array.isArray(collection)) {
		return collection.find((scenario) => scenario.id === scenarioId) || null
	}

	if (typeof collection === 'object') {
		const scenario = collection[scenarioId]
		if (!scenario) return null
		return scenario.id ? scenario : { id: scenarioId, ...scenario }
	}

	return null
}

/**
 * Normalize divergent @result payloads into a uniform (passed, score, rawResult) triple.
 * Returns null if the event should be ignored (e.g. DNS free-lookup).
 * @param {object} rawResult - Raw result from simulator component
 * @returns {{ passed: boolean, score: number, rawResult: object }|null}
 */
export function normalizeResult(rawResult: SimulatorResult): NormalizedSimulatorResult | null {
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
