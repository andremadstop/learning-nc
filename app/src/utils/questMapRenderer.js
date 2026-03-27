/**
 * questMapRenderer.js — D3 rendering module for the Quest-Map.
 *
 * Pure D3 rendering. No Vue dependencies. Returns plain objects/functions.
 * The caller (QuestMap.vue) stores D3 objects on `this._` instance properties,
 * NOT in Vue data().
 */
import { forceSimulation, forceLink, forceManyBody, forceCenter, forceCollide } from 'd3-force';
import { select } from 'd3-selection';
import { zoom, zoomIdentity } from 'd3-zoom';
import 'd3-transition'; // side-effect import for .transition() support

/** @type {number} Hexagon radius in pixels */
const HEX_RADIUS = 28;
const CURRENT_RADIUS = 32;
const NODE_LABEL_MIN_ZOOM = 0.65;
const EDGE_LABEL_MIN_ZOOM = 0.95;
const NODE_LABEL_BASE_SIZE = 12;
const EDGE_LABEL_BASE_SIZE = 10;

/** @type {Object<string, string>} Node type to emoji icon mapping */
const TYPE_ICONS = {
	simulator: '\uD83D\uDCBB',
	dialog: '\uD83D\uDCAC',
	boss: '\u2B50',
	bot_correction: '\uD83E\uDD16',
	ending: '\uD83C\uDFC1',
	default: '\uD83D\uDD35',
};

function clamp(value, min, max) {
	return Math.max(min, Math.min(max, value));
}

function getNodeState(nodeStates, nodeId) {
	return nodeStates.get(String(nodeId)) || 'locked';
}

function getNodeRadius(state) {
	return state === 'current' ? CURRENT_RADIUS : HEX_RADIUS;
}

function getEdgeKey(edge) {
	return edge.id || (edge.source.id || edge.source) + '-' + (edge.target.id || edge.target);
}

function getEdgeVisualState(edge, edgeStates, nodeStates) {
	const edgeState = edgeStates.get(String(edge.id));
	if (edgeState && edgeState.reachable) {
		return 'reachable';
	}

	const sourceState = getNodeState(nodeStates, edge.source.id || edge.source);
	const targetState = getNodeState(nodeStates, edge.target.id || edge.target);
	const sourceSeen = sourceState === 'current' || sourceState === 'visited';
	const targetSeen = targetState === 'current' || targetState === 'visited';
	return (sourceSeen || targetSeen) ? 'visited' : 'locked';
}

/**
 * Compute SVG polygon points string for a flat-top hexagon.
 * @param {number} cx - center x
 * @param {number} cy - center y
 * @param {number} r - radius
 * @returns {string} SVG polygon points attribute value
 */
function hexagonPoints(cx, cy, r) {
	const points = [];
	for (let i = 0; i < 6; i++) {
		const angle = (Math.PI / 3) * i;
		const px = cx + r * Math.cos(angle);
		const py = cy + r * Math.sin(angle);
		points.push(px.toFixed(2) + ',' + py.toFixed(2));
	}
	return points.join(' ');
}

/**
 * Set up the SVG with defs (glow filter, arrow markers) and zoom group.
 * @param {SVGElement} svgElement - the raw SVG DOM element
 * @param {number} width
 * @param {number} height
 * @returns {{ svg: object, g: object, defs: object }}
 */
export function createQuestMap(svgElement, width, height) {
	const svg = select(svgElement)
		.attr('width', width)
		.attr('height', height)
		.attr('viewBox', '0 0 ' + width + ' ' + height);

	// Clear any previous content
	svg.selectAll('*').remove();

	const defs = svg.append('defs');

	// Glow filter
	const glowFilter = defs.append('filter')
		.attr('id', 'quest-glow')
		.attr('x', '-50%')
		.attr('y', '-50%')
		.attr('width', '200%')
		.attr('height', '200%');
	glowFilter.append('feGaussianBlur')
		.attr('in', 'SourceGraphic')
		.attr('stdDeviation', '4')
		.attr('result', 'blur');
	const glowMerge = glowFilter.append('feMerge');
	glowMerge.append('feMergeNode').attr('in', 'blur');
	glowMerge.append('feMergeNode').attr('in', 'SourceGraphic');

	// Arrow marker for reachable edges
	defs.append('marker')
		.attr('id', 'quest-arrow')
		.attr('viewBox', '0 0 10 10')
		.attr('refX', 10)
		.attr('refY', 5)
		.attr('markerWidth', 6)
		.attr('markerHeight', 6)
		.attr('orient', 'auto-start-reverse')
		.append('path')
		.attr('d', 'M0,0 L10,5 L0,10 Z')
		.attr('fill', '#4488ff');

	// Arrow marker for locked edges
	defs.append('marker')
		.attr('id', 'quest-arrow-locked')
		.attr('viewBox', '0 0 10 10')
		.attr('refX', 10)
		.attr('refY', 5)
		.attr('markerWidth', 6)
		.attr('markerHeight', 6)
		.attr('orient', 'auto-start-reverse')
		.append('path')
		.attr('d', 'M0,0 L10,5 L0,10 Z')
		.attr('fill', '#444444');

	// Zoom group — all rendered content goes here
	const g = svg.append('g').attr('class', 'quest-map-zoom-group');

	return { svg, g, defs };
}

/**
 * Create a D3 force simulation for the graph layout.
 * @param {Array} nodes - graph nodes (will be mutated with x, y)
 * @param {Array} edges - graph edges with source/target ids
 * @param {number} width
 * @param {number} height
 * @returns {object} D3 forceSimulation instance
 */
export function createSimulation(nodes, edges, width, height) {
	return forceSimulation(nodes)
		.force('link', forceLink(edges)
			.id(function(d) { return d.id; })
			.distance(150)
			.strength(0.7))
		.force('charge', forceManyBody().strength(-300))
		.force('center', forceCenter(width / 2, height / 2))
		.force('collide', forceCollide(38))
		.alphaDecay(0.02);
}

/**
 * Set up zoom and pan on the SVG.
 * @param {object} svg - D3 selection of SVG
 * @param {object} g - D3 selection of zoom group
 * @param {number} width
 * @param {number} height
 * @returns {object} D3 zoom behavior
 */
export function setupZoom(svg, g, _width, _height) {
	const zoomBehavior = zoom()
		.touchable(function() { return true; })
		.scaleExtent([0.3, 3])
		.on('zoom', function(event) {
			g.attr('transform', event.transform);
			applyZoomPresentation(g, event.transform.k);
		});

	svg.style('touch-action', 'none');
	svg.call(zoomBehavior);
	applyZoomPresentation(g, 1);

	// Double-click on empty area resets view
	svg.on('dblclick.zoom', function() {
		svg.transition()
			.duration(500)
			.call(zoomBehavior.transform, zoomIdentity);
	});

	return zoomBehavior;
}

/**
 * Smooth transition to center the view on a specific node.
 * @param {object} svg - D3 selection of SVG
 * @param {object} zoomBehavior - D3 zoom behavior
 * @param {{ x: number, y: number }} node - node with x, y coordinates
 * @param {number} width
 * @param {number} height
 */
export function centerOnNode(svg, zoomBehavior, node, width, height) {
	if (!node || typeof node.x !== 'number' || typeof node.y !== 'number') {
		return;
	}
	const transform = zoomIdentity
		.translate(width / 2 - node.x, height / 2 - node.y)
		.scale(1);
	svg.transition()
		.duration(500)
		.call(zoomBehavior.transform, transform);
}

/**
 * Render hexagonal node groups in the zoom group.
 * @param {object} g - D3 selection of zoom group
 * @param {Array} nodes - graph nodes
 * @param {Map<string, string>} nodeStates - node id -> state string
 * @param {function} onNodeClick - callback(nodeId, state)
 */
export function renderNodes(g, nodes, nodeStates, onNodeClick) {
	// Remove old nodes
	g.selectAll('.quest-node').remove();

	const nodeGroups = g.selectAll('.quest-node')
		.data(nodes, function(d) { return d.id; })
		.enter()
		.append('g')
		.attr('class', function(d) {
			const state = getNodeState(nodeStates, d.id);
			return 'quest-node quest-node--' + state;
		})
		.on('click', function(event, d) {
			const state = nodeStates.get(String(d.id)) || 'locked';
			if (onNodeClick) {
				onNodeClick(String(d.id), state);
			}
		});

	nodeGroups.append('circle')
		.attr('class', 'quest-node__halo')
		.attr('r', function(d) {
			return getNodeRadius(getNodeState(nodeStates, d.id)) + 10;
		});

	// Hexagon polygon
	nodeGroups.append('polygon')
		.attr('points', function(d) {
			return hexagonPoints(0, 0, getNodeRadius(getNodeState(nodeStates, d.id)));
		})
		.attr('filter', function(d) {
			const state = getNodeState(nodeStates, d.id);
			return state === 'locked' ? null : 'url(#quest-glow)';
		});

	// Type icon (emoji) — centered above middle
	nodeGroups.append('text')
		.attr('class', 'quest-node__icon')
		.attr('x', 0)
		.attr('y', -4)
		.attr('text-anchor', 'middle')
		.attr('dominant-baseline', 'central')
		.attr('font-size', '14px')
		.text(function(d) {
			return TYPE_ICONS[d.type] || TYPE_ICONS.default;
		});

	// Title label — below hexagon
	nodeGroups.append('text')
		.attr('class', 'quest-node__label')
		.attr('x', 0)
		.attr('y', function(d) {
			return getNodeRadius(getNodeState(nodeStates, d.id)) + 14;
		})
		.attr('text-anchor', 'middle')
		.attr('fill', '#aaaaaa')
		.attr('font-size', '10px')
		.text(function(d) {
			const title = d.title || d.id;
			return title.length > 12 ? title.substring(0, 11) + '\u2026' : title;
		});

	nodeGroups.append('title')
		.text(function(d) {
			return d.title || d.id;
		});

	// Lock icon overlay for locked nodes
	nodeGroups.filter(function(d) {
		return nodeStates.get(String(d.id)) === 'locked';
	}).append('text')
		.attr('class', 'quest-node__lock')
		.attr('x', 0)
		.attr('y', 2)
		.attr('text-anchor', 'middle')
		.attr('dominant-baseline', 'central')
		.attr('font-size', '16px')
		.text('\uD83D\uDD12');
}

/**
 * Render edge lines with arrows and labels.
 * @param {object} g - D3 selection of zoom group
 * @param {Array} edges - graph edges with source/target, label
 * @param {Map<string, {reachable: boolean, conditionText: string}>} edgeStates
 * @param {Map<string, string>} nodeStates
 */
export function renderEdges(g, edges, edgeStates, nodeStates) {
	// Remove old edges
	g.selectAll('.quest-edge').remove();
	g.selectAll('.quest-edge-label').remove();

	// Edge lines
	g.selectAll('.quest-edge')
		.data(edges, getEdgeKey)
		.enter()
		.append('line')
		.attr('class', function(d) {
			return 'quest-edge quest-edge--' + getEdgeVisualState(d, edgeStates, nodeStates);
		})
		.attr('stroke', function(d) {
			const visualState = getEdgeVisualState(d, edgeStates, nodeStates);
			if (visualState === 'reachable') {
				return '#58a6ff';
			}
			if (visualState === 'visited') {
				return '#8b949e';
			}
			return '#4b5563';
		})
		.attr('stroke-width', function(d) {
			return getEdgeVisualState(d, edgeStates, nodeStates) === 'reachable' ? 2.4 : 2;
		})
		.attr('stroke-opacity', function(d) {
			const visualState = getEdgeVisualState(d, edgeStates, nodeStates);
			if (visualState === 'reachable') {
				return 0.95;
			}
			if (visualState === 'visited') {
				return 0.62;
			}
			return 0.35;
		})
		.attr('stroke-dasharray', function(d) {
			return getEdgeVisualState(d, edgeStates, nodeStates) === 'locked' ? '6,4' : null;
		})
		.attr('marker-end', function(d) {
			const st = edgeStates.get(String(d.id));
			return (st && st.reachable) ? 'url(#quest-arrow)' : null;
		})
		.each(function(d) {
			const st = edgeStates.get(String(d.id));
			if (st && !st.reachable && st.conditionText) {
				select(this).append('title').text(st.conditionText);
			}
		});

	// Edge labels
	g.selectAll('.quest-edge-label')
		.data(edges.filter(function(d) { return d.label; }), getEdgeKey)
		.enter()
		.append('text')
		.attr('class', 'quest-edge-label')
		.attr('text-anchor', 'middle')
		.attr('fill', '#aaaaaa')
		.attr('font-size', '10px')
		.attr('dy', -6)
		.text(function(d) { return d.label; });
}

/**
 * Update CSS classes on existing node groups without re-creating them.
 * @param {object} g - D3 selection of zoom group
 * @param {Map<string, string>} nodeStates - node id -> state string
 */
export function updateNodeStates(g, nodeStates) {
	g.selectAll('.quest-node')
		.attr('class', function(d) {
			const state = getNodeState(nodeStates, d.id);
			return 'quest-node quest-node--' + state;
		})
		.select('polygon')
		.attr('points', function(d) {
			return hexagonPoints(0, 0, getNodeRadius(getNodeState(nodeStates, d.id)));
		})
		.attr('filter', function(d) {
			const state = getNodeState(nodeStates, d.id);
			return state === 'locked' ? null : 'url(#quest-glow)';
		});

	g.selectAll('.quest-node__halo')
		.attr('r', function(d) {
			return getNodeRadius(getNodeState(nodeStates, d.id)) + 10;
		});

	g.selectAll('.quest-node__label')
		.attr('y', function(d) {
			return getNodeRadius(getNodeState(nodeStates, d.id)) + 14;
		});

	// Update lock icon visibility
	g.selectAll('.quest-node').each(function(d) {
		const group = select(this);
		const state = getNodeState(nodeStates, d.id);
		const existingLock = group.select('.quest-node__lock');

		if (state === 'locked' && existingLock.empty()) {
			group.append('text')
				.attr('class', 'quest-node__lock')
				.attr('x', 0)
				.attr('y', 2)
				.attr('text-anchor', 'middle')
				.attr('dominant-baseline', 'central')
				.attr('font-size', '16px')
				.text('\uD83D\uDD12');
		} else if (state !== 'locked' && !existingLock.empty()) {
			existingLock.remove();
		}
	});
}

export function applyZoomPresentation(g, scale) {
	const safeScale = Math.max(scale || 1, 0.3);
	const nodeLabelVisible = safeScale >= NODE_LABEL_MIN_ZOOM;
	const edgeLabelVisible = safeScale >= EDGE_LABEL_MIN_ZOOM;
	const nodeLabelSize = clamp(NODE_LABEL_BASE_SIZE / safeScale, 7, 18);
	const edgeLabelSize = clamp(EDGE_LABEL_BASE_SIZE / safeScale, 6, 14);
	const iconSize = clamp(14 / safeScale, 10, 18);
	const lockSize = clamp(16 / safeScale, 11, 20);

	g.selectAll('.quest-node__label')
		.attr('font-size', nodeLabelSize + 'px')
		.attr('opacity', nodeLabelVisible ? 1 : 0);

	g.selectAll('.quest-edge-label')
		.attr('font-size', edgeLabelSize + 'px')
		.attr('opacity', edgeLabelVisible ? 0.92 : 0);

	g.selectAll('.quest-node__icon')
		.attr('font-size', iconSize + 'px');

	g.selectAll('.quest-node__lock')
		.attr('font-size', lockSize + 'px');
}
