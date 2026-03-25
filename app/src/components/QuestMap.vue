<template>
	<div>
		<div
			v-if="isOpen"
			class="quest-map-backdrop"
			@click="close" />
		<div
			class="quest-map-overlay"
			:class="{ 'quest-map-open': isOpen }">
			<button
				class="quest-map-close-btn"
				:title="t('learning', 'Schliessen')"
				@click="close">
				&times;
			</button>
			<svg ref="questMap" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n';
import {
	computeNodeStates,
	computeEdgeStates,
	findEdgeForNavigation,
} from '../utils/questMapEngine.js';
import {
	createQuestMap,
	createSimulation,
	setupZoom,
	centerOnNode,
	renderNodes,
	renderEdges,
	updateNodeStates,
} from '../utils/questMapRenderer.js';

export default {
	name: 'QuestMap',

	props: {
		graph: {
			type: Object,
			default: function() { return { nodes: [], edges: [] }; },
		},
		stateBag: {
			type: Object,
			default: function() { return {}; },
		},
		currentNodeId: {
			type: String,
			default: '',
		},
		availableEdges: {
			type: Array,
			default: function() { return []; },
		},
	},

	data: function() {
		return {
			isOpen: false,
		};
	},

	watch: {
		currentNodeId: function() {
			if (this._d3Initialized) {
				this._updateMap();
			}
		},
		stateBag: {
			deep: true,
			handler: function() {
				if (this._d3Initialized) {
					this._updateMap();
				}
			},
		},
	},

	mounted: function() {
		// Non-reactive D3 references — CRITICAL: not in data()
		this._simulation = null;
		this._zoomBehavior = null;
		this._svg = null;
		this._g = null;
		this._d3Initialized = false;
		this._nodeData = null;
		this._edgeData = null;
	},

	beforeDestroy: function() {
		if (this._simulation) {
			this._simulation.stop();
			this._simulation = null;
		}
		if (this._svg) {
			this._svg.on('.zoom', null);
		}
		this._zoomBehavior = null;
		this._svg = null;
		this._g = null;
		this._d3Initialized = false;
		this._nodeData = null;
		this._edgeData = null;
	},

	methods: {
		t: t,

		open: function() {
			this.isOpen = true;
			this.$nextTick(function() {
				if (!this._d3Initialized) {
					this._initD3();
				}
				this._updateMap();
			}.bind(this));
		},

		close: function() {
			this.isOpen = false;
			this.$emit('close');
		},

		_initD3: function() {
			var svgEl = this.$refs.questMap;
			if (!svgEl) {
				return;
			}

			var container = svgEl.parentElement;
			var width = container.clientWidth || 800;
			var height = container.clientHeight || 600;

			var result = createQuestMap(svgEl, width, height);
			this._svg = result.svg;
			this._g = result.g;

			// Deep copy nodes/edges to avoid mutating props
			this._nodeData = this.graph.nodes.map(function(n) {
				return Object.assign({}, n);
			});
			this._edgeData = this.graph.edges.map(function(e) {
				return Object.assign({}, e, {
					source: e.from || e.source,
					target: e.to || e.target,
				});
			});

			this._simulation = createSimulation(this._nodeData, this._edgeData, width, height);

			this._zoomBehavior = setupZoom(this._svg, this._g, width, height);

			var self = this;
			this._simulation.on('tick', function() {
				// Update node positions
				self._g.selectAll('.quest-node')
					.attr('transform', function(d) {
						return 'translate(' + d.x + ',' + d.y + ')';
					});

				// Update edge positions
				self._g.selectAll('.quest-edge')
					.attr('x1', function(d) { return d.source.x; })
					.attr('y1', function(d) { return d.source.y; })
					.attr('x2', function(d) { return d.target.x; })
					.attr('y2', function(d) { return d.target.y; });

				// Update edge label positions
				self._g.selectAll('.quest-edge-label')
					.attr('x', function(d) { return (d.source.x + d.target.x) / 2; })
					.attr('y', function(d) { return (d.source.y + d.target.y) / 2; });
			});

			// Center on current node when simulation settles
			this._simulation.on('end', function() {
				var currentNode = self._nodeData.find(function(n) {
					return String(n.id) === self.currentNodeId;
				});
				if (currentNode) {
					centerOnNode(self._svg, self._zoomBehavior, currentNode, width, height);
				}
			});

			this._d3Initialized = true;
		},

		_updateMap: function() {
			if (!this._g || !this._nodeData) {
				return;
			}

			var nodeStates = computeNodeStates(
				this.graph,
				this.currentNodeId,
				this.stateBag,
				this.availableEdges
			);

			var edgeStates = computeEdgeStates(
				this.graph.edges,
				this.availableEdges,
				this.currentNodeId
			);

			var self = this;
			renderEdges(this._g, this._edgeData, edgeStates);
			renderNodes(this._g, this._nodeData, nodeStates, function(nodeId, state) {
				self._handleNodeClick(nodeId, state);
			});
			updateNodeStates(this._g, nodeStates);
		},

		_handleNodeClick: function(nodeId, state) {
			if (state === 'reachable') {
				var edge = findEdgeForNavigation(this.availableEdges, nodeId);
				if (edge) {
					this.$emit('navigate', edge.id);
					this.close();
				}
			} else if (state === 'locked') {
				// Shake animation
				var nodeEl = this._g.selectAll('.quest-node')
					.filter(function(d) { return String(d.id) === nodeId; });
				nodeEl.classed('quest-node--shake', true);
				setTimeout(function() {
					nodeEl.classed('quest-node--shake', false);
				}, 400);
			} else if (state === 'visited') {
				var visitedEdge = findEdgeForNavigation(this.availableEdges, nodeId);
				if (visitedEdge) {
					this.$emit('navigate', visitedEdge.id);
					this.close();
				}
			}
		},
	},
};
</script>
