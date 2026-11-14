/**
 * skillMapRenderer — size-dependent forces (#36 h).
 *
 * On resize only forceCenter used to move; the x bands and forceY kept the
 * dimensions of the first render. applyLayoutForces sets all three together.
 */
import { describe, it, expect } from 'vitest'
import { createForceSimulation, applyLayoutForces } from '../../src/utils/skillMapRenderer.js'

function nodes() {
	return [
		{ id: 1, courseId: 7, radius: 20 },
		{ id: 2, courseId: 7, radius: 20 },
		{ id: 3, courseId: 9, radius: 20 },
	]
}

describe('skillMapRenderer layout forces', () => {
	it('creates the simulation with centre, x bands and forceY for the given size', () => {
		const n = nodes()
		const sim = createForceSimulation(n, [], 800, 500)
		sim.stop()
		expect(sim.force('center').x()).toBe(400)
		expect(sim.force('center').y()).toBe(250)
		expect(sim.force('y').y()(n[0])).toBe(250)
		// two courses -> bands at 200 and 600
		expect(sim.force('x').x()(n[0])).toBe(200)
		expect(sim.force('x').x()(n[2])).toBe(600)
	})

	it('re-sets all size-dependent forces on resize', () => {
		const n = nodes()
		const sim = createForceSimulation(n, [], 800, 500)
		sim.stop()
		applyLayoutForces(sim, n, 400, 300)
		expect(sim.force('center').x()).toBe(200)
		expect(sim.force('center').y()).toBe(150)
		expect(sim.force('y').y()(n[0])).toBe(150)
		expect(sim.force('x').x()(n[0])).toBe(100)
		expect(sim.force('x').x()(n[2])).toBe(300)
		// the other forces survive
		expect(sim.force('link')).toBeTruthy()
		expect(sim.force('charge')).toBeTruthy()
		expect(sim.force('collide')).toBeTruthy()
	})
})
