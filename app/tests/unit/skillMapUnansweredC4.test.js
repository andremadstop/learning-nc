/**
 * Skill-Map, fresh-eyes C4 (persona run with Gamification, AI and Leitner off).
 *
 * - A pool the learner has not answered yet came with the server's neutral 50 % error rate
 *   and was painted orange ("sometimes wrong"). The pool rows carry `answered` (C3, #36);
 *   such a pool is grey now. Answered pools keep their colour.
 * - The collide force only knew the dot, so the labels of two small neighbouring dots
 *   ("Question media de…" and "Fire safety") were printed over each other.
 */
import { describe, it, expect } from 'vitest'
import { buildGraphData } from '../../src/utils/skillMapEngine.js'
import { createForceSimulation } from '../../src/utils/skillMapRenderer.js'

function pool(id, extra = {}) {
	return {
		pool_id: id,
		pool_name: 'Pool ' + id,
		error_rate: 50,
		trend: 'stable',
		leitner_stats: { total: 1 },
		course_id: 1,
		course_name: 'Annual safety training',
		...extra,
	}
}

describe('Skill-Map without answers (fresh-eyes C4)', () => {
	it('paints a pool without answers grey, not orange', () => {
		const { nodes } = buildGraphData([pool(1, { answered: false })])
		expect(nodes[0].color).toBe('#8a8a8a')
		expect(nodes[0].answered).toBe(false)
	})

	it('keeps the colour of answered pools and of rows without the flag', () => {
		const { nodes } = buildGraphData([
			pool(1, { answered: true, error_rate: 50 }),
			pool(2, { error_rate: 80 }),
			pool(3, { answered: true, error_rate: 10 }),
		])
		expect(nodes.map((n) => n.color)).toEqual(['#f39c12', '#e74c3c', '#27ae60'])
		expect(nodes.every((n) => n.answered === true)).toBe(true)
	})

	it('keeps small dots at least a label apart', () => {
		const n = [
			{ id: 1, courseId: 1, radius: 12 },
			{ id: 2, courseId: 1, radius: 12 },
		]
		const sim = createForceSimulation(n, [], 800, 500)
		sim.stop()
		const radius = sim.force('collide').radius()
		// two labels of up to 18 characters at 9–12px need about 110px between the centres
		expect(radius(n[0]) * 2).toBeGreaterThanOrEqual(110)
		// big dots keep their own radius
		expect(radius({ id: 3, courseId: 1, radius: 60 })).toBe(68)
	})
})

describe('Skill-Map guide (fresh-eyes C4)', () => {
	it('the VirtuProf guide of the Skill-Map names the grey dots', async () => {
		const { readFileSync } = await import('fs')
		const { resolve } = await import('path')
		const app = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')
		const start = app.indexOf("'view:skillmap': {")
		const guide = app.slice(start, app.indexOf('},', start))
		expect(guide).toContain('grau noch keine Antworten')
	})
})
