import { describe, it, expect } from 'vitest'
import fs from 'fs'

function readJson(relativePath) {
	return JSON.parse(
		fs.readFileSync(new URL(relativePath, import.meta.url), 'utf8'),
	)
}

const campaign = readJson('../../data/campaigns/der_grosse_ausfall.json')
const schema = readJson('../../data/campaigns/campaign-schema.json')

describe('campaign content: Der grosse Ausfall', () => {
	it('parses and matches the schema version', () => {
		expect(campaign.campaign_id).toBe('der_grosse_ausfall')
		expect(campaign.version).toBe(schema.campaign_version)
		expect(Array.isArray(campaign.graph.nodes)).toBe(true)
		expect(Array.isArray(campaign.graph.edges)).toBe(true)
		expect(Array.isArray(campaign.graph.acts)).toBe(true)
	})

	it('has unique node ids, exactly one start node and three endings', () => {
		const nodeIds = campaign.graph.nodes.map(node => node.id)
		const startNodes = campaign.graph.nodes.filter(node => node.start)
		const endingNodes = campaign.graph.nodes.filter(node => node.is_ending)

		expect(new Set(nodeIds).size).toBe(nodeIds.length)
		expect(startNodes.map(node => node.id)).toEqual(['start'])
		expect(endingNodes).toHaveLength(3)
		expect(campaign.graph.nodes.length).toBeGreaterThanOrEqual(17)
	})

	it('has unique edge ids and only references known nodes', () => {
		const nodeIds = new Set(campaign.graph.nodes.map(node => node.id))
		const edgeIds = campaign.graph.edges.map(edge => edge.id)

		expect(new Set(edgeIds).size).toBe(edgeIds.length)

		for (const edge of campaign.graph.edges) {
			expect(nodeIds.has(edge.from)).toBe(true)
			expect(nodeIds.has(edge.to)).toBe(true)
		}
	})

	it('assigns every node to exactly one act and keeps all acts populated', () => {
		const assigned = new Map()

		expect(campaign.graph.acts).toHaveLength(3)

		for (const act of campaign.graph.acts) {
			expect(Array.isArray(act.node_ids)).toBe(true)
			expect(act.node_ids.length).toBeGreaterThan(0)
			for (const nodeId of act.node_ids) {
				expect(assigned.has(nodeId)).toBe(false)
				assigned.set(nodeId, act.name)
			}
		}

		for (const node of campaign.graph.nodes) {
			expect(assigned.has(node.id)).toBe(true)
		}
	})

	it('has no dead-end non-endings and every ending is reachable', () => {
		const outgoing = new Map(campaign.graph.nodes.map(node => [node.id, 0]))
		const incoming = new Map(campaign.graph.nodes.map(node => [node.id, 0]))

		for (const edge of campaign.graph.edges) {
			outgoing.set(edge.from, (outgoing.get(edge.from) || 0) + 1)
			incoming.set(edge.to, (incoming.get(edge.to) || 0) + 1)
		}

		for (const node of campaign.graph.nodes) {
			if (node.is_ending) {
				expect(incoming.get(node.id)).toBeGreaterThan(0)
			} else {
				expect(outgoing.get(node.id)).toBeGreaterThan(0)
			}
		}
	})

	it('covers showcase features: timers, DauBot and at least three simulator types', () => {
		const simulatorNodes = campaign.graph.nodes.filter(node => node.simulator)
		const simulatorTypes = new Set(simulatorNodes.map(node => node.simulator.type))
		const timerNodes = campaign.graph.nodes.filter(node => Number(node.timer_seconds) > 0)
		const botNodes = campaign.graph.nodes.filter(node => node.type === 'bot_correction')

		expect(simulatorTypes.size).toBeGreaterThanOrEqual(3)
		expect([...simulatorTypes].sort()).toEqual([
			'dns',
			'firewall',
			'routing',
			'wireshark',
		])
		expect(timerNodes).toHaveLength(2)
		expect(botNodes).toHaveLength(2)
	})
})
