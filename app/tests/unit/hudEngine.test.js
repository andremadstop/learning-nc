import { describe, it, expect } from 'vitest';
import {
	computeVisibleItems,
	computeReputationBars,
	computeHudState,
	computeActProgress,
	computeScoreDelta,
	computeReputationDeltas,
} from '../../src/utils/hudEngine.js';

describe('computeVisibleItems', () => {
	it('returns visible subset and overflow count when items exceed max', () => {
		const result = computeVisibleItems(['a', 'b', 'c', 'd', 'e', 'f', 'g'], 6);
		expect(result.visible).toEqual(['a', 'b', 'c', 'd', 'e', 'f']);
		expect(result.overflow).toBe(1);
		expect(result.all).toEqual(['a', 'b', 'c', 'd', 'e', 'f', 'g']);
	});

	it('returns all items with zero overflow when within max', () => {
		const result = computeVisibleItems(['a', 'b', 'c'], 6);
		expect(result.visible).toEqual(['a', 'b', 'c']);
		expect(result.overflow).toBe(0);
	});

	it('handles empty items array', () => {
		const result = computeVisibleItems([], 6);
		expect(result.visible).toEqual([]);
		expect(result.overflow).toBe(0);
	});

	it('handles exactly max items', () => {
		const result = computeVisibleItems(['a', 'b', 'c', 'd', 'e', 'f'], 6);
		expect(result.visible).toEqual(['a', 'b', 'c', 'd', 'e', 'f']);
		expect(result.overflow).toBe(0);
	});

	it('clamps negative maxVisible to zero visible items', () => {
		const result = computeVisibleItems(['a', 'b'], -2);
		expect(result.visible).toEqual([]);
		expect(result.overflow).toBe(2);
	});

	it('returns an empty model for non-array input', () => {
		const result = computeVisibleItems(null, 3);
		expect(result.visible).toEqual([]);
		expect(result.overflow).toBe(0);
		expect(result.all).toEqual([]);
	});
});

describe('computeReputationBars', () => {
	it('returns bars with correct domain, value, color, label', () => {
		const bars = computeReputationBars({ security: 5, management: 2 });
		expect(bars).toHaveLength(3);

		const security = bars.find(b => b.domain === 'security');
		expect(security.value).toBe(5);
		expect(security.color).toBe('var(--lnc-cyan)');
		expect(security.label).toBe('Security');

		const management = bars.find(b => b.domain === 'management');
		expect(management.value).toBe(2);
		expect(management.color).toBe('var(--lnc-amber)');
		expect(management.label).toBe('Management');

		const team = bars.find(b => b.domain === 'team');
		expect(team.value).toBe(0);
		expect(team.color).toBe('var(--lnc-green)');
		expect(team.label).toBe('Team');
	});

	it('returns 3 bars with value 0 for empty reputation', () => {
		const bars = computeReputationBars({});
		expect(bars).toHaveLength(3);
		bars.forEach(bar => {
			expect(bar.value).toBe(0);
		});
	});

	it('preserves negative reputation values', () => {
		const bars = computeReputationBars({ security: -2, team: -1 });
		expect(bars.find(bar => bar.domain === 'security').value).toBe(-2);
		expect(bars.find(bar => bar.domain === 'team').value).toBe(-1);
	});
});

describe('computeHudState', () => {
	it('returns combined HUD state from stateBag and gameState', () => {
		const stateBag = {
			items: ['incident_report', 'server_logs'],
			reputation: { security: 5, management: 2, team: 3 },
		};
		const gameState = {
			score: 42,
			act_number: 2,
			character_class: 'security',
		};
		const result = computeHudState(stateBag, gameState);
		expect(result.items).toBeDefined();
		expect(result.items.all).toEqual(['incident_report', 'server_logs']);
		expect(result.reputation).toHaveLength(3);
		expect(result.score).toBe(42);
		expect(result.act).toBe(2);
		expect(result.role).toBe('security');
	});

	it('falls back to empty defaults for missing state input', () => {
		const result = computeHudState(null, null);
		expect(result.items).toEqual({ visible: [], overflow: 0, all: [] });
		expect(result.score).toBe(0);
		expect(result.act).toBe(1);
		expect(result.role).toBe('');
	});
});

describe('computeActProgress', () => {
	it('returns current and total act count from fullGraph nodes', () => {
		const fullGraph = {
			nodes: [
				{ id: 'start', act: 1 },
				{ id: 'investigate', act: 1 },
				{ id: 'server_room', act: 2 },
				{ id: 'finale', act: 3 },
			],
		};
		const result = computeActProgress(fullGraph, 2);
		expect(result.current).toBe(2);
		expect(result.total).toBe(3);
	});

	it('handles graph with single act', () => {
		const fullGraph = {
			nodes: [{ id: 'a', act: 1 }, { id: 'b', act: 1 }],
		};
		const result = computeActProgress(fullGraph, 1);
		expect(result.current).toBe(1);
		expect(result.total).toBe(1);
	});

	it('ignores invalid act metadata and falls back to current act', () => {
		const fullGraph = {
			nodes: [{ id: 'a', act: 'x' }, { id: 'b', act: null }, { id: 'c', act: 0 }],
		};
		const result = computeActProgress(fullGraph, 4);
		expect(result.current).toBe(4);
		expect(result.total).toBe(1);
		expect(result.actNames).toEqual(['Akt 4']);
	});
});

describe('computeScoreDelta', () => {
	it('returns positive delta with direction up', () => {
		const result = computeScoreDelta(42, 50);
		expect(result.delta).toBe(8);
		expect(result.direction).toBe('up');
	});

	it('returns negative delta with direction down', () => {
		const result = computeScoreDelta(50, 42);
		expect(result.delta).toBe(-8);
		expect(result.direction).toBe('down');
	});

	it('returns zero delta with direction none', () => {
		const result = computeScoreDelta(42, 42);
		expect(result.delta).toBe(0);
		expect(result.direction).toBe('none');
	});

	it('coerces invalid inputs to a zero delta', () => {
		const result = computeScoreDelta(undefined, '');
		expect(result.delta).toBe(0);
		expect(result.direction).toBe('none');
	});
});

describe('computeReputationDeltas', () => {
	it('returns per-domain deltas between previous and current', () => {
		const prev = { security: 3, management: 2, team: 5 };
		const current = { security: 5, management: 2, team: 3 };
		const deltas = computeReputationDeltas(prev, current);
		expect(deltas.security).toBe(2);
		expect(deltas.management).toBe(0);
		expect(deltas.team).toBe(-2);
	});

	it('handles missing previous values', () => {
		const prev = {};
		const current = { security: 5, management: 2, team: 3 };
		const deltas = computeReputationDeltas(prev, current);
		expect(deltas.security).toBe(5);
		expect(deltas.management).toBe(2);
		expect(deltas.team).toBe(3);
	});

	it('keeps unknown domains and negative deltas', () => {
		const deltas = computeReputationDeltas(
			{ security: 5, stealth: 3 },
			{ security: 2, stealth: 1 },
		);
		expect(deltas.security).toBe(-3);
		expect(deltas.stealth).toBe(-2);
	});
});
