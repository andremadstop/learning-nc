/**
 * skillMapRenderer.js — D3 rendering module for the Skill-Map.
 *
 * Pure D3 rendering. No Vue dependencies. Returns plain objects/functions.
 * The caller (SkillMap.vue) stores D3 objects on `this._` instance properties,
 * NOT in Vue data().
 *
 * Follows the proven questMapRenderer.js pattern.
 */
import { forceSimulation, forceLink, forceManyBody, forceCenter, forceCollide, forceX, forceY } from 'd3-force';
import { select } from 'd3-selection';
import { zoom, zoomIdentity } from 'd3-zoom';
import 'd3-transition'; // side-effect import for .transition() support
import { getTrendIndicator } from './skillMapEngine.js';

/**
 * Set up the SVG with viewBox and root zoom group.
 * @param {SVGElement} svgElement - the raw SVG DOM element
 * @param {number} width
 * @param {number} height
 * @returns {{ svg: object, g: object }}
 */
export function createSkillMap(svgElement, width, height) {
	var svg = select(svgElement)
		.attr('width', width)
		.attr('height', height)
		.attr('viewBox', '0 0 ' + width + ' ' + height);

	// Clear any previous content
	svg.selectAll('*').remove();

	var defs = svg.append('defs');

	// Drop-shadow filter for hover effect
	var dropShadow = defs.append('filter')
		.attr('id', 'skill-map-shadow')
		.attr('x', '-50%')
		.attr('y', '-50%')
		.attr('width', '200%')
		.attr('height', '200%');
	dropShadow.append('feDropShadow')
		.attr('dx', '0')
		.attr('dy', '2')
		.attr('stdDeviation', '3')
		.attr('flood-color', 'rgba(0,0,0,0.3)');

	// Zoom group — all rendered content goes here
	var g = svg.append('g').attr('class', 'skill-map-zoom-group');

	return { svg: svg, g: g };
}

/**
 * Create a D3 force simulation for the skill-map layout.
 * @param {Array} nodes - graph nodes (will be mutated with x, y)
 * @param {Array} links - graph links with source/target ids
 * @param {number} width
 * @param {number} height
 * @returns {object} D3 forceSimulation instance
 */
export function createForceSimulation(nodes, links, width, height) {
	// Assign course-based x-band positions for clustering
	var courseIds = [];
	nodes.forEach(function(n) {
		if (courseIds.indexOf(n.courseId) === -1 && n.courseId != null) {
			courseIds.push(n.courseId);
		}
	});

	var bandWidth = width / Math.max(courseIds.length, 1);

	return forceSimulation(nodes)
		.force('link', forceLink(links)
			.id(function(d) { return d.id; })
			.distance(80)
			.strength(0.6))
		.force('charge', forceManyBody()
			.strength(function(d) { return -d.radius * 8; }))
		.force('center', forceCenter(width / 2, height / 2))
		.force('collide', forceCollide()
			.radius(function(d) { return d.radius + 8; })
			.strength(0.8))
		.force('x', forceX()
			.x(function(d) {
				var idx = courseIds.indexOf(d.courseId);
				if (idx === -1) { return width / 2; }
				return bandWidth * (idx + 0.5);
			})
			.strength(0.15))
		.force('y', forceY()
			.y(height / 2)
			.strength(0.05))
		.alphaDecay(0.02);
}

/**
 * Set up zoom and pan on the SVG.
 * @param {object} svg - D3 selection of SVG
 * @param {object} g - D3 selection of zoom group
 * @param {number} _width
 * @param {number} _height
 * @returns {object} D3 zoom behavior
 */
export function setupZoom(svg, g, _width, _height) {
	var zoomBehavior = zoom()
		.touchable(function() { return true; })
		.scaleExtent([0.3, 3])
		.on('zoom', function(event) {
			g.attr('transform', event.transform);
		});

	svg.style('touch-action', 'none');
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
 * Render node groups (circle + label + trend indicator).
 * @param {object} g - D3 selection of zoom group
 * @param {Array} nodes - graph nodes with id, label, color, radius, trend, errorRate
 * @param {function} onNodeClick - callback(node)
 */
export function renderNodes(g, nodes, onNodeClick) {
	// Remove old nodes
	g.selectAll('.skill-map-node').remove();

	var nodeGroups = g.selectAll('.skill-map-node')
		.data(nodes, function(d) { return d.id; })
		.enter()
		.append('g')
		.attr('class', 'skill-map-node')
		.style('cursor', 'pointer')
		.on('click', function(_event, d) {
			if (onNodeClick) {
				onNodeClick(d);
			}
		});

	// Main circle
	nodeGroups.append('circle')
		.attr('r', function(d) { return d.radius; })
		.attr('fill', function(d) { return d.color; })
		.attr('stroke', '#ffffff')
		.attr('stroke-width', 2)
		.attr('class', 'skill-map-node__circle');

	// Label below circle
	nodeGroups.append('text')
		.attr('class', 'skill-map-label')
		.attr('x', 0)
		.attr('y', function(d) { return d.radius + 14; })
		.attr('text-anchor', 'middle')
		.attr('fill', '#cccccc')
		.attr('font-size', function(d) {
			return Math.max(9, Math.min(12, d.radius * 0.4)) + 'px';
		})
		.text(function(d) {
			var label = d.label || '';
			return label.length > 18 ? label.substring(0, 17) + '\u2026' : label;
		});

	// Trend indicator above-right of circle
	nodeGroups.append('text')
		.attr('class', function(d) {
			var indicator = getTrendIndicator(d.trend);
			return 'skill-map-trend ' + indicator.className;
		})
		.attr('x', function(d) { return d.radius * 0.7; })
		.attr('y', function(d) { return -d.radius * 0.7; })
		.attr('text-anchor', 'middle')
		.attr('font-size', '10px')
		.text(function(d) {
			return getTrendIndicator(d.trend).symbol;
		});

	// Tooltip
	nodeGroups.append('title')
		.text(function(d) {
			return d.label + ' (' + d.courseName + ') — Error: ' + d.errorRate + '%';
		});
}

/**
 * Render link lines connecting nodes within clusters.
 * @param {object} g - D3 selection of zoom group
 * @param {Array} links - graph links with source/target
 */
export function renderLinks(g, links) {
	// Remove old links
	g.selectAll('.skill-map-link').remove();

	g.selectAll('.skill-map-link')
		.data(links)
		.enter()
		.append('line')
		.attr('class', 'skill-map-link')
		.attr('stroke', '#cccccc')
		.attr('stroke-opacity', 0.4)
		.attr('stroke-width', 1.5);
}

/**
 * Wire simulation tick to update node/link positions.
 * @param {object} simulation - D3 forceSimulation instance
 * @param {object} g - D3 selection of zoom group
 */
export function updateSimulation(simulation, g) {
	simulation.on('tick', function() {
		g.selectAll('.skill-map-link')
			.attr('x1', function(d) { return d.source.x; })
			.attr('y1', function(d) { return d.source.y; })
			.attr('x2', function(d) { return d.target.x; })
			.attr('y2', function(d) { return d.target.y; });

		g.selectAll('.skill-map-node')
			.attr('transform', function(d) {
				return 'translate(' + d.x + ',' + d.y + ')';
			});
	});
}
