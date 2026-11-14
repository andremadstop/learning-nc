import { describe, it, expect } from 'vitest'
import NetworkTopologySvg from '../../src/components/NetworkTopologySvg.vue'

// Calls the real computed/methods of NetworkTopologySvg.vue (audit #128: this file used to
// re-implement them, so a change in the component stayed green).
function svg(nodes) {
	const vm = { nodes }
	return {
		viewBox: () => NetworkTopologySvg.computed.viewBox.call(vm),
		nodeById: (id) => NetworkTopologySvg.methods.nodeById.call(vm, id),
	}
}

describe('NetworkTopologySvg', () => {
	it('viewBox adds 40px padding around node bounds', () => {
		const vm = svg([
			{ id: 'a', x: 100, y: 100 },
			{ id: 'b', x: 300, y: 200 },
		])
		// minX = 100 - 40 = 60, minY = 100 - 40 = 60
		// w = (300-100) + 80 = 280, h = (200-100) + 80 = 180
		expect(vm.viewBox()).toBe('60 60 280 180')
	})

	it('viewBox falls back to 0 0 400 300 without nodes', () => {
		expect(svg([]).viewBox()).toBe('0 0 400 300')
	})

	it('nodes computed reads topology.nodes and defaults to []', () => {
		expect(NetworkTopologySvg.computed.nodes.call({ topology: { nodes: [{ id: 'r1' }] } })).toEqual([{ id: 'r1' }])
		expect(NetworkTopologySvg.computed.nodes.call({ topology: {} })).toEqual([])
	})

	it('nodeById returns node by id', () => {
		const found = svg([
			{ id: 'r1', x: 100, y: 150, label: 'Router' },
			{ id: 'sw1', x: 300, y: 150, label: 'Switch' },
		]).nodeById('r1')
		expect(found.x).toBe(100)
		expect(found.y).toBe(150)
	})

	it('nodeById returns {x:0,y:0} fallback for unknown id', () => {
		expect(svg([{ id: 'r1', x: 100, y: 150 }]).nodeById('unknown-id')).toEqual({ x: 0, y: 0 })
	})
})
