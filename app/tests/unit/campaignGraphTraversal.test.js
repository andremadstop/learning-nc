import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync } from 'fs'
import { join } from 'path'

const CAMPAIGNS_DIR = join(__dirname, '../../data/campaigns')

/**
 * Load all campaign JSON files (excluding schema).
 */
function loadCampaigns() {
	const files = readdirSync(CAMPAIGNS_DIR).filter(
		f => f.endsWith('.json') && f !== 'campaign-schema.json',
	)
	return files.map(file => {
		const raw = readFileSync(join(CAMPAIGNS_DIR, file), 'utf-8')
		const data = JSON.parse(raw)
		return { file, data }
	})
}

const campaigns = loadCampaigns()

describe('campaign graph traversal', () => {
	it('finds at least one campaign file', () => {
		expect(campaigns.length).toBeGreaterThan(0)
	})

	describe.each(campaigns)('$file', ({ file, data }) => {
		const isGraph = !!data.graph
		const isScenes = !!data.scenes

		it('uses either graph or scenes format', () => {
			expect(isGraph || isScenes).toBe(true)
		})

		it('has at least 1 scene or node', () => {
			if (isGraph) {
				expect(data.graph.nodes.length).toBeGreaterThanOrEqual(1)
			} else {
				expect(data.scenes.length).toBeGreaterThanOrEqual(1)
			}
		})

		if (isGraph) {
			it('has exactly one start node', () => {
				const startNodes = data.graph.nodes.filter(n => n.start === true)
				expect(startNodes.length).toBe(1)
			})

			it('has at least one ending node', () => {
				const endings = data.graph.nodes.filter(n => n.is_ending === true)
				expect(endings.length).toBeGreaterThanOrEqual(1)
			})

			it('all edge targets reference existing nodes', () => {
				const nodeIds = new Set(data.graph.nodes.map(n => n.id))
				const deadLinks = data.graph.edges.filter(e => !nodeIds.has(e.to))
				expect(deadLinks.map(e => `${e.id}: ${e.from} -> ${e.to}`)).toEqual([])
			})

			it('all edge sources reference existing nodes', () => {
				const nodeIds = new Set(data.graph.nodes.map(n => n.id))
				const deadSources = data.graph.edges.filter(e => !nodeIds.has(e.from))
				expect(deadSources.map(e => `${e.id}: ${e.from} -> ${e.to}`)).toEqual([])
			})

			it('ending nodes are reachable from start via BFS', () => {
				const startNode = data.graph.nodes.find(n => n.start === true)
				const adjacency = {}
				for (const edge of data.graph.edges) {
					if (!adjacency[edge.from]) adjacency[edge.from] = []
					adjacency[edge.from].push(edge.to)
				}

				const visited = new Set()
				const queue = [startNode.id]
				while (queue.length > 0) {
					const current = queue.shift()
					if (visited.has(current)) continue
					visited.add(current)
					for (const next of adjacency[current] || []) {
						if (!visited.has(next)) queue.push(next)
					}
				}

				const endings = data.graph.nodes.filter(n => n.is_ending === true)
				const reachableEndings = endings.filter(e => visited.has(e.id))
				expect(reachableEndings.length).toBeGreaterThanOrEqual(1)
			})
		}

		if (isScenes) {
			it('first scene exists and has an id', () => {
				expect(data.scenes[0]).toBeDefined()
				expect(data.scenes[0].id).toBeTruthy()
			})

			it('has at least one epilogue scene', () => {
				const epilogues = data.scenes.filter(
					s => s.is_epilog === true || s.is_ending === true,
				)
				expect(epilogues.length).toBeGreaterThanOrEqual(1)
			})

			it('all choice target scenes reference existing scene ids', () => {
				const sceneIds = new Set(data.scenes.map(s => s.id))
				const deadLinks = []

				for (const scene of data.scenes) {
					for (const choice of scene.choices || []) {
						const targets = [
							choice.success_scene,
							choice.partial_scene,
							choice.fail_scene,
							choice.next_scene,
						].filter(Boolean)

						for (const target of targets) {
							if (!sceneIds.has(target)) {
								deadLinks.push(
									`scene "${scene.id}" choice "${choice.id}": target "${target}" not found`,
								)
							}
						}
					}
				}

				expect(deadLinks).toEqual([])
			})

			it('at least one epilogue is reachable from the first scene', () => {
				const sceneMap = new Map(data.scenes.map(s => [s.id, s]))
				const epilogueIds = new Set(
					data.scenes
						.filter(s => s.is_epilog === true || s.is_ending === true)
						.map(s => s.id),
				)

				// BFS through choice targets
				const visited = new Set()
				const queue = [data.scenes[0].id]
				while (queue.length > 0) {
					const current = queue.shift()
					if (visited.has(current)) continue
					visited.add(current)

					const scene = sceneMap.get(current)
					if (!scene) continue

					for (const choice of scene.choices || []) {
						const targets = [
							choice.success_scene,
							choice.partial_scene,
							choice.fail_scene,
							choice.next_scene,
						].filter(Boolean)

						for (const target of targets) {
							if (!visited.has(target)) queue.push(target)
						}
					}
				}

				const reachableEpilogues = [...epilogueIds].filter(id => visited.has(id))
				expect(reachableEpilogues.length).toBeGreaterThanOrEqual(1)
			})
		}
	})
})
