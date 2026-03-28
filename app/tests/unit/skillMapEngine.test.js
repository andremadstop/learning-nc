import { describe, it, expect } from 'vitest';
import {
	buildGraphData,
	getNodeColor,
	getNodeRadius,
	getTrendIndicator,
} from '../../src/utils/skillMapEngine.js';

describe('skillMapEngine', () => {
	describe('getNodeColor', () => {
		it('returns red for error rate > 60', () => {
			expect(getNodeColor(60.1)).toBe('#e74c3c');
			expect(getNodeColor(100)).toBe('#e74c3c');
			expect(getNodeColor(75)).toBe('#e74c3c');
		});

		it('returns yellow for error rate 30-60 (inclusive)', () => {
			expect(getNodeColor(30)).toBe('#f39c12');
			expect(getNodeColor(60)).toBe('#f39c12');
			expect(getNodeColor(45)).toBe('#f39c12');
		});

		it('returns green for error rate < 30', () => {
			expect(getNodeColor(0)).toBe('#27ae60');
			expect(getNodeColor(29.9)).toBe('#27ae60');
			expect(getNodeColor(15)).toBe('#27ae60');
		});
	});

	describe('getNodeRadius', () => {
		it('returns minimum radius for smallest question count', () => {
			expect(getNodeRadius(10, 10, 100)).toBe(12);
		});

		it('returns maximum radius for largest question count', () => {
			expect(getNodeRadius(100, 10, 100)).toBe(40);
		});

		it('returns interpolated radius for middle values', () => {
			const r = getNodeRadius(55, 10, 100);
			expect(r).toBeGreaterThan(12);
			expect(r).toBeLessThan(40);
			expect(r).toBeCloseTo(26, 0);
		});

		it('returns minimum radius when min equals max', () => {
			expect(getNodeRadius(50, 50, 50)).toBe(12);
		});

		it('clamps to min when count is below min', () => {
			expect(getNodeRadius(5, 10, 100)).toBe(12);
		});
	});

	describe('getTrendIndicator', () => {
		it('returns up arrow for improving', () => {
			const result = getTrendIndicator('improving');
			expect(result.symbol).toBe('\u25B2');
			expect(result.className).toBe('trend-up');
		});

		it('returns down arrow for declining', () => {
			const result = getTrendIndicator('declining');
			expect(result.symbol).toBe('\u25BC');
			expect(result.className).toBe('trend-down');
		});

		it('returns dot for stable', () => {
			const result = getTrendIndicator('stable');
			expect(result.symbol).toBe('\u25CF');
			expect(result.className).toBe('trend-stable');
		});

		it('returns stable for unknown trend', () => {
			const result = getTrendIndicator('unknown');
			expect(result.symbol).toBe('\u25CF');
			expect(result.className).toBe('trend-stable');
		});
	});

	describe('buildGraphData', () => {
		it('returns empty nodes and links for empty input', () => {
			const result = buildGraphData([]);
			expect(result.nodes).toEqual([]);
			expect(result.links).toEqual([]);
		});

		it('returns single node with no links for single pool', () => {
			const pools = [{
				pool_id: 1,
				pool_name: 'Networking Basics',
				error_rate: 25,
				trend: 'improving',
				leitner_stats: { total: 50 },
				course_id: 10,
				course_name: 'Network+',
			}];
			const result = buildGraphData(pools);
			expect(result.nodes).toHaveLength(1);
			expect(result.links).toHaveLength(0);

			const node = result.nodes[0];
			expect(node.id).toBe(1);
			expect(node.label).toBe('Networking Basics');
			expect(node.errorRate).toBe(25);
			expect(node.trend).toBe('improving');
			expect(node.questionCount).toBe(50);
			expect(node.courseId).toBe(10);
			expect(node.courseName).toBe('Network+');
			expect(node.color).toBe('#27ae60');
			expect(node.radius).toBeGreaterThanOrEqual(12);
		});

		it('creates chain links for pools in the same course', () => {
			const pools = [
				{ pool_id: 1, pool_name: 'A', error_rate: 10, trend: 'stable', leitner_stats: { total: 20 }, course_id: 10, course_name: 'Net+' },
				{ pool_id: 2, pool_name: 'B', error_rate: 40, trend: 'declining', leitner_stats: { total: 30 }, course_id: 10, course_name: 'Net+' },
				{ pool_id: 3, pool_name: 'C', error_rate: 70, trend: 'improving', leitner_stats: { total: 40 }, course_id: 10, course_name: 'Net+' },
			];
			const result = buildGraphData(pools);
			expect(result.nodes).toHaveLength(3);
			// Chain: 1-2, 2-3
			expect(result.links).toHaveLength(2);
			expect(result.links[0].source).toBe(1);
			expect(result.links[0].target).toBe(2);
			expect(result.links[1].source).toBe(2);
			expect(result.links[1].target).toBe(3);
		});

		it('creates separate chains for pools across different courses', () => {
			const pools = [
				{ pool_id: 1, pool_name: 'A', error_rate: 10, trend: 'stable', leitner_stats: { total: 20 }, course_id: 10, course_name: 'Net+' },
				{ pool_id: 2, pool_name: 'B', error_rate: 40, trend: 'stable', leitner_stats: { total: 30 }, course_id: 10, course_name: 'Net+' },
				{ pool_id: 3, pool_name: 'C', error_rate: 70, trend: 'stable', leitner_stats: { total: 25 }, course_id: 20, course_name: 'Sec+' },
				{ pool_id: 4, pool_name: 'D', error_rate: 50, trend: 'stable', leitner_stats: { total: 35 }, course_id: 20, course_name: 'Sec+' },
			];
			const result = buildGraphData(pools);
			expect(result.nodes).toHaveLength(4);
			// Chain within course 10: 1-2
			// Chain within course 20: 3-4
			expect(result.links).toHaveLength(2);

			const linkSources = result.links.map(l => l.source);
			const linkTargets = result.links.map(l => l.target);

			// No cross-course links
			expect(linkSources).not.toContain(2);
			expect(linkTargets).not.toContain(3);
		});

		it('does not create cross-course links', () => {
			const pools = [
				{ pool_id: 1, pool_name: 'A', error_rate: 10, trend: 'stable', leitner_stats: { total: 20 }, course_id: 10, course_name: 'Net+' },
				{ pool_id: 2, pool_name: 'B', error_rate: 40, trend: 'stable', leitner_stats: { total: 30 }, course_id: 20, course_name: 'Sec+' },
			];
			const result = buildGraphData(pools);
			expect(result.nodes).toHaveLength(2);
			expect(result.links).toHaveLength(0);
		});

		it('computes node radius based on min/max question count across all pools', () => {
			const pools = [
				{ pool_id: 1, pool_name: 'Small', error_rate: 10, trend: 'stable', leitner_stats: { total: 10 }, course_id: 10, course_name: 'Net+' },
				{ pool_id: 2, pool_name: 'Big', error_rate: 10, trend: 'stable', leitner_stats: { total: 100 }, course_id: 10, course_name: 'Net+' },
			];
			const result = buildGraphData(pools);
			expect(result.nodes[0].radius).toBe(12);
			expect(result.nodes[1].radius).toBe(40);
		});

		it('assigns correct colors based on error rates', () => {
			const pools = [
				{ pool_id: 1, pool_name: 'Good', error_rate: 15, trend: 'stable', leitner_stats: { total: 20 }, course_id: 10, course_name: 'X' },
				{ pool_id: 2, pool_name: 'Medium', error_rate: 45, trend: 'stable', leitner_stats: { total: 20 }, course_id: 10, course_name: 'X' },
				{ pool_id: 3, pool_name: 'Bad', error_rate: 80, trend: 'stable', leitner_stats: { total: 20 }, course_id: 10, course_name: 'X' },
			];
			const result = buildGraphData(pools);
			expect(result.nodes[0].color).toBe('#27ae60');
			expect(result.nodes[1].color).toBe('#f39c12');
			expect(result.nodes[2].color).toBe('#e74c3c');
		});
	});
});
