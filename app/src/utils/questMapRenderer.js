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

/** @type {Object<string, string>} Node type to emoji icon mapping */
const TYPE_ICONS = {
	simulator: '\uD83D\uDCBB',
	dialog: '\uD83D\uDCAC',
	boss: '\u2B50',
	bot_correction: '\uD83E\uDD16',
	ending: '\uD83C\uDFC1',
	default: '\uD83D\uDD35',
};

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
		.scaleExtent([0.3, 3])
		.on('zoom', function(event) {
			g.attr('transform', event.transform);
		});

	svg.call(zoomBehavior);

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
			const state = nodeStates.get(String(d.id)) || 'locked';
			return 'quest-node quest-node--' + state;
		})
		.on('click', function(event, d) {
			const state = nodeStates.get(String(d.id)) || 'locked';
			if (onNodeClick) {
				onNodeClick(String(d.id), state);
			}
		});

	// Hexagon polygon
	nodeGroups.append('polygon')
		.attr('points', function(_d) {
			return hexagonPoints(0, 0, HEX_RADIUS);
		})
		.attr('filter', function(d) {
			const state = nodeStates.get(String(d.id)) || 'locked';
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
		.attr('y', HEX_RADIUS + 14)
		.attr('text-anchor', 'middle')
		.attr('fill', '#aaaaaa')
		.attr('font-size', '10px')
		.text(function(d) {
			const title = d.title || d.id;
			return title.length > 12 ? title.substring(0, 11) + '\u2026' : title;
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
 */
export function renderEdges(g, edges, edgeStates) {
	// Remove old edges
	g.selectAll('.quest-edge').remove();
	g.selectAll('.quest-edge-label').remove();

	// Edge lines
	g.selectAll('.quest-edge')
		.data(edges, function(d) { return d.id || (d.source.id || d.source) + '-' + (d.target.id || d.target); })
		.enter()
		.append('line')
		.attr('class', 'quest-edge')
		.attr('stroke', function(d) {
			const st = edgeStates.get(String(d.id));
			return (st && st.reachable) ? '#4488ff' : '#444444';
		})
		.attr('stroke-width', 2)
		.attr('stroke-opacity', function(d) {
			const st = edgeStates.get(String(d.id));
			return (st && st.reachable) ? 0.6 : 0.3;
		})
		.attr('stroke-dasharray', function(d) {
			const st = edgeStates.get(String(d.id));
			return (st && st.reachable) ? null : '5,5';
		})
		.attr('marker-end', function(d) {
			const st = edgeStates.get(String(d.id));
			return (st && st.reachable) ? 'url(#quest-arrow)' : 'url(#quest-arrow-locked)';
		})
		.each(function(d) {
			const st = edgeStates.get(String(d.id));
			if (st && !st.reachable && st.conditionText) {
				select(this).append('title').text(st.conditionText);
			}
		});

	// Edge labels
	g.selectAll('.quest-edge-label')
		.data(edges.filter(function(d) { return d.label; }), function(d) { return d.id || (d.source.id || d.source) + '-' + (d.target.id || d.target); })
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
			const state = nodeStates.get(String(d.id)) || 'locked';
			return 'quest-node quest-node--' + state;
		})
		.select('polygon')
		.attr('filter', function(d) {
			const state = nodeStates.get(String(d.id)) || 'locked';
			return state === 'locked' ? null : 'url(#quest-glow)';
		});

	// Update lock icon visibility
	g.selectAll('.quest-node').each(function(d) {
		const group = select(this);
		const state = nodeStates.get(String(d.id)) || 'locked';
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
