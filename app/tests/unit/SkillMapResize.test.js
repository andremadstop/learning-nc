/**
 * SkillMap.vue — resize feedback loop (#36 h).
 *
 * The bug: the ResizeObserver watched the container that held the SVG AND the
 * legend in normal flow. Each callback copied the container height into the
 * SVG, the container grew by the legend height, the observer fired again, and
 * forceCenter moved every node down by half that amount per frame. The nodes
 * "fell" off screen while the page grew without end.
 *
 * Layout itself cannot be reproduced in happy-dom, so these tests pin the
 * three structural guarantees that break the loop:
 *   1. the observed element holds the SVG and nothing else in flow,
 *   2. a callback with unchanged dimensions does not touch the simulation,
 *   3. a real resize moves centre, x bands and forceY together.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))
vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

const sim = {
	alpha: vi.fn(),
	restart: vi.fn(),
	stop: vi.fn(),
	force: vi.fn(),
}
sim.alpha.mockReturnValue(sim)
sim.restart.mockReturnValue(sim)

const svgSel = {
	attr: vi.fn(),
	on: vi.fn(),
}
svgSel.attr.mockReturnValue(svgSel)

vi.mock('../../src/utils/skillMapRenderer.js', () => ({
	createSkillMap: vi.fn(() => ({ svg: svgSel, g: {} })),
	createForceSimulation: vi.fn(() => sim),
	applyLayoutForces: vi.fn((s) => s),
	setupZoom: vi.fn(() => ({})),
	renderNodes: vi.fn(),
	renderLinks: vi.fn(),
	updateSimulation: vi.fn(),
}))

import axios from '@nextcloud/axios'
import * as renderer from '../../src/utils/skillMapRenderer.js'
import SkillMap from '../../src/components/SkillMap.vue'

// Size reported by the drawing area (the element the component measures).
let canvasSize = { w: 900, h: 500 }
let observers = []

class FakeResizeObserver {
	constructor(cb) {
		this.cb = cb
		this.targets = []
		this.disconnect = vi.fn()
		observers.push(this)
	}

	observe(el) { this.targets.push(el) }
	fire() { this.cb([]) }
}

function isMeasured(el) {
	// The fixed canvas reports the size; under the old code the container
	// was measured, so give it the same size to keep both versions comparable.
	return el.classList && (el.classList.contains('skill-map-canvas') || el.classList.contains('skill-map-container'))
}

const origW = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'clientWidth')
const origH = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'clientHeight')

async function mountMap() {
	axios.get.mockResolvedValue({
		data: {
			pools: [
				{ pool_id: 1, pool_name: 'Netze', course_id: 7, course_name: 'Kurs', error_rate: 40, trend: 'stable', leitner_stats: { 1: 6, 2: 4, total: 10 } },
				{ pool_id: 2, pool_name: 'DNS', course_id: 7, course_name: 'Kurs', error_rate: 10, trend: 'up', leitner_stats: { 1: 5, total: 5 } },
			],
			courses: [],
		},
	})
	const el = document.createElement('div')
	document.body.appendChild(el)
	const app = createApp(SkillMap)
	const vm = app.mount(el)
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
		await nextTick()
	}
	return { app, vm, el }
}

describe('SkillMap resize loop (#36 h)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		sim.alpha.mockReturnValue(sim)
		sim.restart.mockReturnValue(sim)
		svgSel.attr.mockReturnValue(svgSel)
		observers = []
		canvasSize = { w: 900, h: 500 }
		globalThis.ResizeObserver = FakeResizeObserver
		Object.defineProperty(HTMLElement.prototype, 'clientWidth', {
			configurable: true,
			get() { return isMeasured(this) ? canvasSize.w : 0 },
		})
		Object.defineProperty(HTMLElement.prototype, 'clientHeight', {
			configurable: true,
			get() { return isMeasured(this) ? canvasSize.h : 0 },
		})
	})

	afterEach(() => {
		if (origW) { Object.defineProperty(HTMLElement.prototype, 'clientWidth', origW) }
		if (origH) { Object.defineProperty(HTMLElement.prototype, 'clientHeight', origH) }
		delete globalThis.ResizeObserver
		document.body.innerHTML = ''
	})

	it('builds the graph with a positive height, centred vertically', async () => {
		const { app } = await mountMap()
		expect(renderer.createForceSimulation).toHaveBeenCalledTimes(1)
		const [, , width, height] = renderer.createForceSimulation.mock.calls[0]
		expect(width).toBe(900)
		expect(height).toBe(500)
		app.unmount()
	})

	it('observes an element that holds the SVG but not the legend', async () => {
		const { app } = await mountMap()
		expect(observers).toHaveLength(1)
		const target = observers[0].targets[0]
		expect(target.querySelector('svg')).not.toBeNull()
		// The legend in normal flow inside the observed element is what made it grow.
		expect(target.querySelector('.skill-map-legend')).toBeNull()
		app.unmount()
	})

	it('ignores observer callbacks with unchanged dimensions', async () => {
		const { app } = await mountMap()
		// observe() fires right away with the size the graph was built with
		observers[0].fire()
		observers[0].fire()
		expect(sim.restart).not.toHaveBeenCalled()
		expect(sim.alpha).not.toHaveBeenCalled()
		app.unmount()
	})

	it('a real resize re-sets centre, x bands and forceY once', async () => {
		const { app, vm } = await mountMap()
		canvasSize = { w: 600, h: 400 }
		observers[0].fire()
		observers[0].fire()
		expect(renderer.applyLayoutForces).toHaveBeenCalledTimes(1)
		const [s, nodes, width, height] = renderer.applyLayoutForces.mock.calls[0]
		expect(s).toBe(sim)
		expect(nodes).toBe(vm._graphData.nodes)
		expect(width).toBe(600)
		expect(height).toBe(400)
		expect(sim.restart).toHaveBeenCalledTimes(1)
		app.unmount()
	})

	it('a hidden canvas (0x0) keeps the last layout', async () => {
		const { app } = await mountMap()
		canvasSize = { w: 0, h: 0 }
		observers[0].fire()
		expect(renderer.applyLayoutForces).not.toHaveBeenCalled()
		expect(sim.restart).not.toHaveBeenCalled()
		app.unmount()
	})

	it('disconnects the observer and stops the simulation on unmount', async () => {
		const { app } = await mountMap()
		const obs = observers[0]
		app.unmount()
		expect(obs.disconnect).toHaveBeenCalledTimes(1)
		expect(sim.stop).toHaveBeenCalledTimes(1)
	})
})
