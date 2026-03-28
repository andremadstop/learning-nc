/**
 * skillMapEngine.js — Pure JS engine for Skill-Map graph data transformation.
 *
 * No DOM or D3 dependencies. All functions are pure and fully testable with Vitest.
 * Transforms API pool data into D3-compatible {nodes, links} structure.
 */

/**
 * Return a hex color based on error rate thresholds.
 * @param {number} errorRate - 0 to 100
 * @returns {string} hex color
 */
export function getNodeColor(errorRate) {
	if (errorRate > 60) {
		return '#e74c3c'; // red — high error
	}
	if (errorRate >= 30) {
		return '#f39c12'; // yellow — medium error
	}
	return '#27ae60'; // green — low error
}

/**
 * Return a proportional radius for the node circle.
 * Linear interpolation between 12px (min) and 40px (max).
 * @param {number} questionCount
 * @param {number} minQ - minimum question count across all pools
 * @param {number} maxQ - maximum question count across all pools
 * @returns {number} radius in pixels
 */
export function getNodeRadius(questionCount, minQ, maxQ) {
	const MIN_RADIUS = 12;
	const MAX_RADIUS = 40;

	if (maxQ <= minQ) {
		return MIN_RADIUS;
	}

	const clamped = Math.max(minQ, Math.min(maxQ, questionCount));
	const ratio = (clamped - minQ) / (maxQ - minQ);
	return Math.round(MIN_RADIUS + ratio * (MAX_RADIUS - MIN_RADIUS));
}

/**
 * Return a trend indicator with symbol and CSS class name.
 * @param {string} trend - 'improving' | 'declining' | 'stable'
 * @returns {{ symbol: string, className: string }}
 */
export function getTrendIndicator(trend) {
	if (trend === 'improving') {
		return { symbol: '\u25B2', className: 'trend-up' };
	}
	if (trend === 'declining') {
		return { symbol: '\u25BC', className: 'trend-down' };
	}
	return { symbol: '\u25CF', className: 'trend-stable' };
}

/**
 * Transform API pool data into D3-compatible graph structure.
 *
 * Each pool becomes a node. Links connect pools within the same course
 * in a chain topology (pool[0]-pool[1], pool[1]-pool[2], etc.)
 *
 * @param {Array<{pool_id: number, pool_name: string, error_rate: number, trend: string, leitner_stats: {total: number}, course_id: number, course_name: string}>} pools
 * @returns {{ nodes: Array, links: Array }}
 */
export function buildGraphData(pools) {
	if (!pools || pools.length === 0) {
		return { nodes: [], links: [] };
	}

	// Compute min/max question counts for radius scaling
	const counts = pools.map(function(p) { return p.leitner_stats.total; });
	const minQ = Math.min.apply(null, counts);
	const maxQ = Math.max.apply(null, counts);

	// Build nodes
	var nodes = pools.map(function(pool) {
		return {
			id: pool.pool_id,
			label: pool.pool_name,
			errorRate: pool.error_rate,
			trend: pool.trend,
			questionCount: pool.leitner_stats.total,
			courseId: pool.course_id,
			courseName: pool.course_name,
			color: getNodeColor(pool.error_rate),
			radius: getNodeRadius(pool.leitner_stats.total, minQ, maxQ),
		};
	});

	// Group pools by course for chain linking
	var courseGroups = {};
	pools.forEach(function(pool) {
		var cid = pool.course_id;
		if (!courseGroups[cid]) {
			courseGroups[cid] = [];
		}
		courseGroups[cid].push(pool.pool_id);
	});

	// Build chain links within each course cluster
	var links = [];
	Object.keys(courseGroups).forEach(function(courseId) {
		var group = courseGroups[courseId];
		for (var i = 0; i < group.length - 1; i++) {
			links.push({
				source: group[i],
				target: group[i + 1],
			});
		}
	});

	return { nodes: nodes, links: links };
}
