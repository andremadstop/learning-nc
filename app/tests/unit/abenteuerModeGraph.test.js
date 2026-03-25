import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock axios before importing the component
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: vi.fn(),
		get: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, str) => str,
	getLanguage: () => 'de',
}))

import axios from '@nextcloud/axios'
import AbenteuerMode from '../../src/components/AbenteuerMode.vue'

describe('onSimulatorComplete — stateBag Reaktivitaet', () => {
	let vm

	beforeEach(() => {
		vm = {
			isGraphMode: true,
			selectedCampaign: { id: 'test_graph_campaign' },
			stateBag: { flags: {}, items: [] },
			graphAvailableEdges: [{ id: 'e1' }],
			currentGraphNode: { id: 'n1' },
			currentGraphSimulator: null,
			phase: 'simulation',
		}

		axios.post.mockResolvedValue({
			data: {
				state: { stateBag: { flags: { host_isolated: true }, items: ['key_card'] } },
				node: { id: 'n2', type: 'scene' },
				available_edges: [],
				simulator: null,
			},
		})
	})

	it('setzt stateBag als neue Referenz nach graph-traverse', async () => {
		const oldBag = vm.stateBag
		await AbenteuerMode.methods.onSimulatorComplete.call(vm, true, 1.0, { correct: true })
		expect(vm.stateBag).not.toBe(oldBag) // neue Referenz
		expect(vm.stateBag.flags.host_isolated).toBe(true)
	})

	it('emittiert graph-traverse POST mit simulator_passed=true', async () => {
		await AbenteuerMode.methods.onSimulatorComplete.call(vm, true, 0.85, { correct: true })
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('graph-traverse'),
			expect.objectContaining({
				simulator_passed: true,
				simulator_score: 85,
			}),
		)
	})

	it('setzt phase=epilog wenn passed=false und keine Edges verfuegbar', async () => {
		vm.graphAvailableEdges = [] // keine Edges
		await AbenteuerMode.methods.onSimulatorComplete.call(vm, false, 0, {})
		expect(vm.phase).toBe('epilog')
	})
})
