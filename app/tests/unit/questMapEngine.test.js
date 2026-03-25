import { describe, it, expect } from 'vitest';
import {
	computeNodeStates,
	deriveVisitedNodes,
	conditionToText,
	findEdgeForNavigation,
	computeEdgeStates,
} from '../../src/utils/questMapEngine.js';

// Minimal graph fixture matching test_graph_campaign.json structure
const graph = {
	nodes: [
		{ id: 'start', title: 'The Alert', type: 'dialog', act: 1, start: true },
		{ id: 'investigate_logs', title: 'Log Analysis', type: 'dialog', act: 1 },
		{ id: 'alert_team', title: 'Team Alert', type: 'dialog', act: 1 },
		{ id: 'isolate_host', title: 'Host Isolation', type: 'simulator', act: 2 },
		{ id: 'confront_blind', title: 'Blind Confrontation', type: 'bot_correction', act: 2 },
		{ id: 'write_report', title: 'Incident Report', type: 'dialog', act: 3, is_ending: true },
		{ id: 'escalate_incomplete', title: 'Incomplete Escalation', type: 'dialog', act: 3, is_ending: true },
	],
	edges: [
		{ id: 'e1', from: 'start', to: 'investigate_logs', label: 'Check logs', conditions: {} },
		{ id: 'e2', from: 'start', to: 'alert_team', label: 'Alert team', conditions: {} },
		{ id: 'e3', from: 'investigate_logs', to: 'isolate_host', label: 'Isolate', conditions: { requires_flag: 'has_evidence' } },
		{ id: 'e4', from: 'investigate_logs', to: 'alert_team', label: 'Alert with findings', conditions: {} },
		{ id: 'e5', from: 'alert_team', to: 'confront_blind', label: 'Try blind', conditions: {} },
		{ id: 'e6', from: 'alert_team', to: 'investigate_logs', label: 'Go back', conditions: {} },
		{ id: 'e7', from: 'isolate_host', to: 'write_report', label: 'Write report', conditions: { requires_flag: 'firewall_configured', requires_item: 'incident_report', min_reputation: { security: 3 } } },
		{ id: 'e8', from: 'confront_blind', to: 'escalate_incomplete', label: 'Escalate', conditions: {} },
		{ id: 'e9', from: 'confront_blind', to: 'investigate_logs', label: 'Go back', conditions: {} },
	],
};

describe('questMapEngine', () => {
	describe('computeNodeStates', () => {
		it('marks current node as current', () => {
			const stateBag = { _visited_nodes: ['start'] };
			const availableEdges = [{ to: 'investigate_logs' }, { to: 'alert_team' }];
			const states = computeNodeStates(graph, 'start', stateBag, availableEdges);
			expect(states.get('start')).toBe('current');
		});

		it('marks visited nodes as visited', () => {
			const stateBag = { _visited_nodes: ['start', 'investigate_logs'] };
			const availableEdges = [{ to: 'isolate_host' }, { to: 'alert_team' }];
			const states = computeNodeStates(graph, 'investigate_logs', stateBag, availableEdges);
			expect(states.get('start')).toBe('visited');
			expect(states.get('investigate_logs')).toBe('current');
		});

		it('marks reachable nodes from available edges', () => {
			const stateBag = { _visited_nodes: ['start'] };
			const availableEdges = [{ to: 'investigate_logs' }, { to: 'alert_team' }];
			const states = computeNodeStates(graph, 'start', stateBag, availableEdges);
			expect(states.get('investigate_logs')).toBe('reachable');
			expect(states.get('alert_team')).toBe('reachable');
		});

		it('marks all other nodes as locked', () => {
			const stateBag = { _visited_nodes: ['start'] };
			const availableEdges = [{ to: 'investigate_logs' }];
			const states = computeNodeStates(graph, 'start', stateBag, availableEdges);
			expect(states.get('isolate_host')).toBe('locked');
			expect(states.get('write_report')).toBe('locked');
			expect(states.get('escalate_incomplete')).toBe('locked');
		});

		it('current overrides visited status', () => {
			const stateBag = { _visited_nodes: ['start', 'investigate_logs', 'start'] };
			const availableEdges = [];
			const states = computeNodeStates(graph, 'start', stateBag, availableEdges);
			expect(states.get('start')).toBe('current');
		});

		it('handles empty graph', () => {
			const emptyGraph = { nodes: [], edges: [] };
			const states = computeNodeStates(emptyGraph, 'x', {}, []);
			expect(states.size).toBe(0);
		});

		it('handles no visited nodes in stateBag', () => {
			const stateBag = {};
			const availableEdges = [{ to: 'investigate_logs' }];
			const states = computeNodeStates(graph, 'start', stateBag, availableEdges);
			expect(states.get('start')).toBe('current');
			expect(states.get('investigate_logs')).toBe('reachable');
			expect(states.get('alert_team')).toBe('locked');
		});
	});

	describe('deriveVisitedNodes', () => {
		it('returns Set from _visited_nodes array', () => {
			const result = deriveVisitedNodes({ _visited_nodes: ['a', 'b', 'c'] });
			expect(result).toBeInstanceOf(Set);
			expect(result.size).toBe(3);
			expect(result.has('a')).toBe(true);
			expect(result.has('b')).toBe(true);
		});

		it('handles empty _visited_nodes', () => {
			expect(deriveVisitedNodes({ _visited_nodes: [] }).size).toBe(0);
		});

		it('handles missing _visited_nodes', () => {
			expect(deriveVisitedNodes({}).size).toBe(0);
		});

		it('handles null stateBag', () => {
			expect(deriveVisitedNodes(null).size).toBe(0);
		});

		it('deduplicates visited nodes', () => {
			const result = deriveVisitedNodes({ _visited_nodes: ['a', 'a', 'b'] });
			expect(result.size).toBe(2);
		});
	});

	describe('conditionToText', () => {
		it('maps requires_flag to German text', () => {
			const text = conditionToText({ requires_flag: 'server_logs_found' });
			expect(text).toBe('Braucht: Server Logs Found');
		});

		it('maps requires_item to German text', () => {
			const text = conditionToText({ requires_item: 'backup_tape' });
			expect(text).toBe('Item noetig: backup_tape');
		});

		it('maps min_reputation to German text', () => {
			const text = conditionToText({ min_reputation: { security: 3 } });
			expect(text).toBe('security Reputation >= 3');
		});

		it('joins multiple conditions with pipe', () => {
			const text = conditionToText({
				requires_flag: 'has_evidence',
				requires_item: 'incident_report',
				min_reputation: { security: 3 },
			});
			expect(text).toContain('Braucht: Has Evidence');
			expect(text).toContain('Item noetig: incident_report');
			expect(text).toContain('security Reputation >= 3');
			expect(text).toContain(' | ');
		});

		it('handles multiple reputation domains', () => {
			const text = conditionToText({ min_reputation: { security: 3, management: 2 } });
			expect(text).toContain('security Reputation >= 3');
			expect(text).toContain('management Reputation >= 2');
		});

		it('returns empty string for empty conditions', () => {
			expect(conditionToText({})).toBe('');
		});

		it('returns empty string for null conditions', () => {
			expect(conditionToText(null)).toBe('');
		});

		it('returns empty string for undefined conditions', () => {
			expect(conditionToText(undefined)).toBe('');
		});
	});

	describe('findEdgeForNavigation', () => {
		it('returns edge matching target node', () => {
			const edges = [
				{ id: 'e1', to: 'investigate_logs' },
				{ id: 'e2', to: 'alert_team' },
			];
			const result = findEdgeForNavigation(edges, 'alert_team');
			expect(result).toEqual({ id: 'e2', to: 'alert_team' });
		});

		it('returns null when no edge matches', () => {
			const edges = [{ id: 'e1', to: 'investigate_logs' }];
			expect(findEdgeForNavigation(edges, 'nonexistent')).toBeNull();
		});

		it('returns first match when multiple edges to same target', () => {
			const edges = [
				{ id: 'e1', to: 'alert_team', label: 'First' },
				{ id: 'e2', to: 'alert_team', label: 'Second' },
			];
			const result = findEdgeForNavigation(edges, 'alert_team');
			expect(result.id).toBe('e1');
		});

		it('returns null for empty edges array', () => {
			expect(findEdgeForNavigation([], 'any')).toBeNull();
		});
	});

	describe('computeEdgeStates', () => {
		it('marks reachable edges correctly', () => {
			const availableEdges = [
				{ id: 'e1', from: 'start', to: 'investigate_logs' },
				{ id: 'e2', from: 'start', to: 'alert_team' },
			];
			const states = computeEdgeStates(graph.edges, availableEdges, 'start');
			expect(states.get('e1').reachable).toBe(true);
			expect(states.get('e2').reachable).toBe(true);
		});

		it('marks non-reachable edges as not reachable', () => {
			const availableEdges = [{ id: 'e1', from: 'start', to: 'investigate_logs' }];
			const states = computeEdgeStates(graph.edges, availableEdges, 'start');
			expect(states.get('e3').reachable).toBe(false);
			expect(states.get('e7').reachable).toBe(false);
		});

		it('includes conditionText for edges with conditions', () => {
			const availableEdges = [];
			const states = computeEdgeStates(graph.edges, availableEdges, 'start');
			const e7 = states.get('e7');
			expect(e7.conditionText).toContain('Braucht: Firewall Configured');
			expect(e7.conditionText).toContain('Item noetig: incident_report');
		});

		it('returns empty conditionText for edges without conditions', () => {
			const availableEdges = [];
			const states = computeEdgeStates(graph.edges, availableEdges, 'start');
			expect(states.get('e1').conditionText).toBe('');
		});

		it('handles empty edges and available edges', () => {
			const states = computeEdgeStates([], [], 'start');
			expect(states.size).toBe(0);
		});
	});

	describe('edge cases', () => {
		it('keeps isolated visited nodes visible when a current node has no outgoing edges', () => {
			const isolatedGraph = {
				nodes: [
					{ id: 'solo' },
					{ id: 'archive' },
					{ id: 'vault' },
				],
				edges: [],
			};

			const states = computeNodeStates(isolatedGraph, 'solo', {
				_visited_nodes: ['solo', 'archive', 'vault'],
			}, []);

			expect(states.get('solo')).toBe('current');
			expect(states.get('archive')).toBe('visited');
			expect(states.get('vault')).toBe('visited');
		});

		it('keeps fully visited nodes visited when nothing is reachable anymore', () => {
			const states = computeNodeStates(graph, 'investigate_logs', {
				_visited_nodes: graph.nodes.map(node => node.id),
			}, []);

			expect(states.get('start')).toBe('visited');
			expect(states.get('alert_team')).toBe('visited');
			expect(states.get('investigate_logs')).toBe('current');
		});

		it('ignores invalid _visited_nodes payloads', () => {
			const result = deriveVisitedNodes({ _visited_nodes: 'start' });
			expect(result).toBeInstanceOf(Set);
			expect(result.size).toBe(0);
		});

		it('renders zero reputation requirements without dropping them', () => {
			const text = conditionToText({ min_reputation: { security: 0 } });
			expect(text).toBe('security Reputation >= 0');
		});

		it('does not mark unknown available edge ids as reachable', () => {
			const states = computeEdgeStates(graph.edges, [{ id: 'missing-edge' }], 'start');
			expect(states.get('e1').reachable).toBe(false);
			expect(states.get('e2').reachable).toBe(false);
		});
	});
});
