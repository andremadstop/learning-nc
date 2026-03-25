/**
 * questMapEngine.js — Pure JS engine for Quest-Map node/edge state computation.
 *
 * No DOM or D3 dependencies. All functions are pure and fully testable with Vitest.
 */

/**
 * Convert a flag name like "server_logs_found" to "Server Logs Found".
 * @param {string} flag
 * @returns {string}
 */
function formatFlagName(flag) {
	return flag
		.split('_')
		.map(w => w.charAt(0).toUpperCase() + w.slice(1))
		.join(' ');
}

/**
 * Compute the visual state of every node in the graph.
 *
 * @param {{ nodes: Array<{id: string}>, edges: Array }} graph
 * @param {string} currentNodeId
 * @param {object} stateBag - must contain _visited_nodes array
 * @param {Array<{to: string}>} availableEdges
 * @returns {Map<string, 'visited'|'current'|'reachable'|'locked'>}
 */
export function computeNodeStates(graph, currentNodeId, stateBag, availableEdges) {
	const states = new Map();
	const visited = deriveVisitedNodes(stateBag);
	const reachableSet = new Set(availableEdges.map(e => e.to));

	for (const node of graph.nodes) {
		const id = String(node.id);
		if (id === currentNodeId) {
			states.set(id, 'current');
		} else if (reachableSet.has(id)) {
			states.set(id, 'reachable');
		} else if (visited.has(id)) {
			states.set(id, 'visited');
		} else {
			states.set(id, 'locked');
		}
	}

	return states;
}

/**
 * Extract visited node IDs from stateBag._visited_nodes.
 *
 * @param {object|null|undefined} stateBag
 * @returns {Set<string>}
 */
export function deriveVisitedNodes(stateBag) {
	if (!stateBag || !Array.isArray(stateBag._visited_nodes)) {
		return new Set();
	}
	return new Set(stateBag._visited_nodes);
}

/**
 * Convert a conditions object to a German tooltip text string.
 *
 * @param {object|null|undefined} conditions
 * @returns {string}
 */
export function conditionToText(conditions) {
	if (!conditions || typeof conditions !== 'object') {
		return '';
	}

	const parts = [];

	if (conditions.requires_flag) {
		parts.push('Braucht: ' + formatFlagName(conditions.requires_flag));
	}

	if (conditions.requires_item) {
		parts.push('Item noetig: ' + conditions.requires_item);
	}

	if (conditions.min_reputation && typeof conditions.min_reputation === 'object') {
		for (const [domain, value] of Object.entries(conditions.min_reputation)) {
			parts.push(domain + ' Reputation >= ' + value);
		}
	}

	return parts.join(' | ');
}

/**
 * Find the edge that navigates to a specific target node.
 *
 * @param {Array<{to: string}>} availableEdges
 * @param {string} targetNodeId
 * @returns {object|null}
 */
export function findEdgeForNavigation(availableEdges, targetNodeId) {
	for (const edge of availableEdges) {
		if (edge.to === targetNodeId) {
			return edge;
		}
	}
	return null;
}

/**
 * Compute the visual state of every edge in the graph.
 *
 * @param {Array<{id: string, conditions?: object}>} edges - all graph edges
 * @param {Array<{id: string}>} availableEdges - currently reachable edges
 * @param {string} currentNodeId
 * @returns {Map<string, {reachable: boolean, conditionText: string}>}
 */
export function computeEdgeStates(edges, availableEdges, currentNodeId) {
	const states = new Map();
	const availableIds = new Set(availableEdges.map(e => e.id));

	for (const edge of edges) {
		const id = String(edge.id);
		states.set(id, {
			reachable: availableIds.has(id),
			conditionText: conditionToText(edge.conditions || {}),
		});
	}

	return states;
}
