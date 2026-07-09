import { describe, it, expect, vi } from 'vitest'

// translate wie im Projekt-Muster mocken (gibt den Rohstring zurück).
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, str) => str,
}))

import {
	makeStubScene,
	makeStubQuestion,
	buildPlacementSimulationConfig,
	buildSimQuestion,
	calcSimTotal,
} from '../../src/utils/simulationEngine.js'

describe('makeStubScene', () => {
	it('liefert eine valide Fallback-Szene mit 3 Choices', () => {
		const scene = makeStubScene()
		expect(scene.id).toBe('s1_ankunft')
		expect(scene.choices).toHaveLength(3)
		expect(scene.npc_dialog.speaker).toBe('dr_weber')
	})
})

describe('makeStubQuestion', () => {
	it('liefert eine Frage mit genau einer korrekten Antwort', () => {
		const q = makeStubQuestion()
		expect(q.id).toBe('stub_q1')
		expect(q.answers.filter(a => a.is_correct)).toHaveLength(1)
		expect(q.answers).toHaveLength(4)
	})
})

describe('buildPlacementSimulationConfig', () => {
	it('nutzt Fallback-Positionen wenn keine übergeben', () => {
		const cfg = buildPlacementSimulationConfig({})
		expect(cfg.positions).toHaveLength(3)
		expect(cfg.scoring_mode).toBe('strict')
		expect(cfg.device_options).toContain('Firewall')
	})

	it('übernimmt eigene Positionen und dedupliziert device_options', () => {
		const cfg = buildPlacementSimulationConfig({
			positions: [{ id: 'p1', correct: 'Router' }, { id: 'p2', correct: 'Router' }],
		})
		expect(cfg.positions).toHaveLength(2)
		expect(cfg.device_options).toEqual(['Router'])
	})

	it('respektiert scoring_mode-Override', () => {
		expect(buildPlacementSimulationConfig({ scoring_mode: 'lenient' }).scoring_mode).toBe('lenient')
	})
})

describe('buildSimQuestion', () => {
	it('cli-Typ erzeugt cli-Subtype', () => {
		const q = buildSimQuestion({ type: 'cli' })
		expect(q.pbq_subtype).toBe('cli')
		expect(q.id).toBe('sim_cli')
	})

	it('unbekannter Typ fällt auf diagnostic zurück', () => {
		const q = buildSimQuestion({ type: 'nonsense' })
		expect(q.pbq_subtype).toBe('diagnostic')
	})

	it('network_device_placement bindet die Placement-Config ein', () => {
		const q = buildSimQuestion({ type: 'network_device_placement' })
		expect(q.pbq_subtype).toBe('placement')
		expect(q.pbq_config.positions.length).toBeGreaterThan(0)
	})
})

describe('calcSimTotal', () => {
	it('switch_config zählt korrekte Ports', () => {
		const total = calcSimTotal({
			pbq_subtype: 'switch_config',
			pbq_config: { switches: [{ ports: [{ correct: 'trunk' }, { correct: '' }, { correct: 'access' }] }] },
		})
		expect(total).toBe(2)
	})

	it('placement zählt Positionen', () => {
		expect(calcSimTotal({ pbq_subtype: 'placement', pbq_config: { positions: [1, 2, 3] } })).toBe(3)
	})

	it('diagnostic zählt findings', () => {
		expect(calcSimTotal({ pbq_subtype: 'diagnostic', pbq_config: { findings: [1, 2] } })).toBe(2)
	})

	it('unbekannter Subtype → 1', () => {
		expect(calcSimTotal({ pbq_subtype: 'cli', pbq_config: {} })).toBe(1)
	})
})
