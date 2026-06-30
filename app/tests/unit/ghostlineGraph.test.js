import { describe, it, expect } from 'vitest'
import fs from 'fs'

function readJson(relativePath) {
	return JSON.parse(
		fs.readFileSync(new URL(relativePath, import.meta.url), 'utf8'),
	)
}

const campaign = readJson('../../data/campaigns/ghostline_act1.json')
const schema = readJson('../../data/campaigns/campaign-schema.json')

describe('ghostline_act1.json structure', () => {
	const { nodes, edges, acts } = campaign.graph
	const nodeIds = new Set(nodes.map((n) => n.id))

	const VALID_NODE_TYPES = new Set([
		'story',
		'simulator',
		'bot_correction',
		'terminal',
		'ending',
	])

	// Build edges-out index: nodeId → [edges leaving that node]
	const edgesOut = new Map(nodes.map((n) => [n.id, []]))
	for (const edge of edges) {
		edgesOut.get(edge.from).push(edge)
	}

	// -------------------------------------------------------------------------
	// Basic parse / schema checks
	// -------------------------------------------------------------------------

	it('loads and parses without error', () => {
		expect(campaign).toBeDefined()
		expect(campaign.campaign_id).toBe('ghostline_act1')
	})

	// StoryEngineService::validateCampaignAgainstSchema rejects (silently skips
	// from the campaign list) any campaign whose top-level `version` does not
	// exactly match the schema's `campaign_version` — error "Kampagne
	// aktualisiert — bitte neu starten". json_decode alone does NOT catch this,
	// so the deploy looks fine but the campaign never appears. Guard it here.
	it('version exactly matches schema campaign_version (else StoryEngine skips it)', () => {
		expect(campaign.version).toBe(schema.campaign_version)
	})

	it('has exactly 1 node with start:true', () => {
		const startNodes = nodes.filter((n) => n.start === true)
		expect(startNodes).toHaveLength(1)
	})

	it('has at least 1 node with is_ending:true', () => {
		const endingNodes = nodes.filter((n) => n.is_ending === true)
		expect(endingNodes.length).toBeGreaterThanOrEqual(1)
	})

	it('has at least 1 node with type === "ending"', () => {
		const endingTypeNodes = nodes.filter((n) => n.type === 'ending')
		expect(endingTypeNodes.length).toBeGreaterThanOrEqual(1)
	})

	it('uses only valid node types', () => {
		for (const node of nodes) {
			expect(
				VALID_NODE_TYPES.has(node.type),
				`Node "${node.id}" has invalid type "${node.type}"`,
			).toBe(true)
		}
	})

	// -------------------------------------------------------------------------
	// Act / node assignment integrity
	// -------------------------------------------------------------------------

	it('assigns every node to exactly one act', () => {
		const assigned = new Map()
		for (const act of acts) {
			for (const nodeId of act.node_ids) {
				expect(
					assigned.has(nodeId),
					`Node "${nodeId}" appears in more than one act`,
				).toBe(false)
				assigned.set(nodeId, true)
			}
		}
		for (const node of nodes) {
			expect(
				assigned.has(node.id),
				`Node "${node.id}" is not assigned to any act`,
			).toBe(true)
		}
	})

	it('all acts[] node_ids reference existing nodes', () => {
		for (const act of acts) {
			for (const nodeId of act.node_ids) {
				expect(
					nodeIds.has(nodeId),
					`Act references unknown node "${nodeId}"`,
				).toBe(true)
			}
		}
	})

	// -------------------------------------------------------------------------
	// Edge integrity
	// -------------------------------------------------------------------------

	it('all edge from/to values reference existing node IDs', () => {
		for (const edge of edges) {
			expect(
				nodeIds.has(edge.from),
				`Edge "${edge.id}" has unknown from: "${edge.from}"`,
			).toBe(true)
			expect(
				nodeIds.has(edge.to),
				`Edge "${edge.id}" has unknown to: "${edge.to}"`,
			).toBe(true)
		}
	})

	// -------------------------------------------------------------------------
	// TERM-01: Terminal node constraints
	// -------------------------------------------------------------------------

	it('TERM-01: every terminal node has ≥3 valid_commands entries', () => {
		const terminalNodes = nodes.filter(
			(n) => n.simulator && n.simulator.type === 'terminal',
		)
		expect(terminalNodes.length).toBeGreaterThanOrEqual(1)
		for (const node of terminalNodes) {
			const cmds = node.simulator.scenario_override?.valid_commands ?? []
			expect(
				cmds.length,
				`Terminal "${node.id}" has only ${cmds.length} valid_commands (need ≥3 for quote variants)`,
			).toBeGreaterThanOrEqual(3)
		}
	})

	it('TERM-01: every terminal node has a non-empty hint', () => {
		const terminalNodes = nodes.filter(
			(n) => n.simulator && n.simulator.type === 'terminal',
		)
		for (const node of terminalNodes) {
			const hint = node.simulator.scenario_override?.hint ?? ''
			expect(
				hint.length,
				`Terminal "${node.id}" has empty or missing hint`,
			).toBeGreaterThan(0)
		}
	})

	it('TERM-01: every terminal node has exactly 1 valid_commands entry with required:true', () => {
		const terminalNodes = nodes.filter(
			(n) => n.simulator && n.simulator.type === 'terminal',
		)
		for (const node of terminalNodes) {
			const cmds = node.simulator.scenario_override?.valid_commands ?? []
			const requiredCmds = cmds.filter((c) => c.required === true)
			expect(
				requiredCmds.length,
				`Terminal "${node.id}" has ${requiredCmds.length} required commands (need exactly 1)`,
			).toBe(1)
		}
	})

	// -------------------------------------------------------------------------
	// K3-04: Pattern B gate integrity — the anti-skip assertion
	// -------------------------------------------------------------------------

	it('K3-04: every terminal has ≥1 unconditional exit, gate node has ≥1 correctly-flagged exit, and ZERO ungated exits (anti-escape)', () => {
		const terminalNodes = nodes.filter(
			(n) => n.simulator && n.simulator.type === 'terminal',
		)
		expect(terminalNodes.length).toBeGreaterThanOrEqual(1)

		const nodeMap = new Map(nodes.map((n) => [n.id, n]))

		for (const T of terminalNodes) {
			const tEdgesOut = edgesOut.get(T.id) || []
			const passFlag = T.simulator.pass_flag

			// Terminal T must have at least one unconditional forward edge (Pattern B leg 1)
			const unconditionalExits = tEdgesOut.filter(
				(e) => !e.conditions || !e.conditions.requires_flag,
			)
			expect(
				unconditionalExits.length,
				`Terminal "${T.id}": no unconditional exit edge found (Pattern B requires one)`,
			).toBeGreaterThanOrEqual(1)

			// For each gate node R reachable via unconditional edge from T
			for (const unconditionalEdge of unconditionalExits) {
				const R = nodeMap.get(unconditionalEdge.to)
				const rEdgesOut = edgesOut.get(R.id) || []

				// Gate R must have ≥1 exit gated by T's pass_flag (Pattern B leg 2)
				const gatedByFlag = rEdgesOut.filter(
					(e) =>
						e.conditions &&
						e.conditions.requires_flag === passFlag,
				)
				expect(
					gatedByFlag.length,
					`Gate node "${R.id}" (after terminal "${T.id}"): no exit with conditions.requires_flag === "${passFlag}"`,
				).toBeGreaterThanOrEqual(1)

				// CRITICAL K3-04 anti-escape: gate R must have ZERO ungated exits.
				// A single ungated exit lets the player bypass the gate even when the
				// gated exit exists — CampaignGraphService filters the gated edge when
				// the flag is unset, but the ungated edge survives → player escapes.
				const ungatedExits = rEdgesOut.filter(
					(e) => !e.conditions || !e.conditions.requires_flag,
				)
				expect(
					ungatedExits.length,
					`Gate node "${R.id}" has ${ungatedExits.length} ungated exit(s): [${ungatedExits.map((e) => e.id).join(', ')}]. Any ungated exit creates a bypass. All exits from a gate node must carry conditions.requires_flag.`,
				).toBe(0)
			}
		}
	})
})
