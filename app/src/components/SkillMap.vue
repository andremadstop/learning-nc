<template>
	<div class="skill-map-wrapper">
		<div v-if="loading" class="skill-map-loading">
			<span class="icon-loading" />
			{{ t('learning', 'Skill-Map wird geladen...') }}
		</div>

		<div v-else-if="error" class="skill-map-error">
			{{ error }}
		</div>

		<div v-else-if="pools.length === 0" class="skill-map-empty">
			{{ t('learning', 'Lerne ein paar Karteikarten, dann erscheint hier deine Kompetenz-Karte!') }}
		</div>

		<div v-else class="skill-map-container" ref="container">
			<svg ref="skillMap" />

			<!-- Legend -->
			<div class="skill-map-legend">
				<span class="skill-map-legend__item">
					<span class="skill-map-legend__dot" style="background:#e74c3c" />
					{{ t('learning', 'Schwach') }}
				</span>
				<span class="skill-map-legend__item">
					<span class="skill-map-legend__dot" style="background:#f39c12" />
					{{ t('learning', 'In Arbeit') }}
				</span>
				<span class="skill-map-legend__item">
					<span class="skill-map-legend__dot" style="background:#27ae60" />
					{{ t('learning', 'Gemeistert') }}
				</span>
			</div>

			<!-- Detail sidebar -->
			<div
				class="skill-map-sidebar"
				:class="{ 'skill-map-sidebar--open': selectedNode !== null }"
			>
				<button
					class="skill-map-sidebar__close"
					:title="t('learning', 'Schliessen')"
					@click="closeSidebar"
				>&times;</button>

				<template v-if="selectedNode">
					<h3 class="skill-map-sidebar__title">{{ selectedNode.label }}</h3>
					<p class="skill-map-sidebar__course">{{ selectedNode.courseName }}</p>

					<div class="skill-map-sidebar__stat">
						<strong>{{ t('learning', 'Fehlerquote') }}:</strong>
						{{ selectedNode.errorRate }}%
						<span :class="trendClass">{{ trendSymbol }}</span>
					</div>

					<div class="skill-map-sidebar__stat">
						<strong>{{ t('learning', 'Fragen') }}:</strong>
						{{ selectedNode.questionCount }}
					</div>

					<div v-if="selectedNodeLeitner" class="skill-map-sidebar__leitner">
						<strong>{{ t('learning', 'Leitner-Boxen') }}:</strong>
						<div class="leitner-bars">
							<div
								v-for="(count, box) in selectedNodeLeitner"
								:key="box"
								class="leitner-bar"
							>
								<span class="leitner-bar__label">{{ box }}</span>
								<span class="leitner-bar__value">{{ count }}</span>
							</div>
						</div>
					</div>

					<button
						class="skill-map-sidebar__practice"
						@click="startPractice"
					>
						{{ t('learning', 'Jetzt ueben') }}
					</button>
				</template>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { buildGraphData, getTrendIndicator } from '../utils/skillMapEngine.js';
import {
	createSkillMap,
	createForceSimulation,
	setupZoom,
	renderNodes,
	renderLinks,
	updateSimulation,
} from '../utils/skillMapRenderer.js';

export default {
	name: 'SkillMap',

	data: function() {
		return {
			loading: true,
			error: null,
			selectedNode: null,
			pools: [],
			courses: [],
		};
	},

	computed: {
		trendSymbol: function() {
			if (!this.selectedNode) { return ''; }
			return getTrendIndicator(this.selectedNode.trend).symbol;
		},
		trendClass: function() {
			if (!this.selectedNode) { return ''; }
			return getTrendIndicator(this.selectedNode.trend).className;
		},
		selectedNodeLeitner: function() {
			if (!this.selectedNode) { return null; }
			var pool = this.pools.find(function(p) {
				return p.pool_id === this.selectedNode.id;
			}.bind(this));
			if (!pool || !pool.leitner_stats) { return null; }
			// Return box counts excluding 'total'
			var stats = {};
			var keys = Object.keys(pool.leitner_stats);
			for (var i = 0; i < keys.length; i++) {
				if (keys[i] !== 'total') {
					stats[keys[i]] = pool.leitner_stats[keys[i]];
				}
			}
			return Object.keys(stats).length > 0 ? stats : null;
		},
	},

	mounted: function() {
		// Non-reactive D3 references — CRITICAL: not in data()
		this._simulation = null;
		this._zoomBehavior = null;
		this._svg = null;
		this._g = null;
		this._graphData = null;
		this._resizeObserver = null;

		this._fetchData();
	},

	beforeDestroy: function() {
		if (this._simulation) {
			this._simulation.stop();
			this._simulation = null;
		}
		if (this._svg) {
			this._svg.on('.zoom', null);
		}
		if (this._resizeObserver) {
			this._resizeObserver.disconnect();
			this._resizeObserver = null;
		}
		this._zoomBehavior = null;
		this._svg = null;
		this._g = null;
		this._graphData = null;
	},

	methods: {
		t: t,

		_fetchData: async function() {
			try {
				var response = await axios.get(
					generateUrl('/apps/learning/api/profile/skill-map')
				);
				this.pools = response.data.pools || [];
				this.courses = response.data.courses || [];
				this.loading = false;

				if (this.pools.length > 0) {
					this.$nextTick(function() {
						this._initGraph();
					}.bind(this));
				}
			} catch (err) {
				this.error = t('learning', 'Skill-Map konnte nicht geladen werden.');
				this.loading = false;
			}
		},

		_initGraph: function() {
			var svgEl = this.$refs.skillMap;
			if (!svgEl) { return; }

			var container = this.$refs.container;
			var width = container.clientWidth || 800;
			var height = Math.max(container.clientHeight || 500, 400);

			this._graphData = buildGraphData(this.pools);

			var result = createSkillMap(svgEl, width, height);
			this._svg = result.svg;
			this._g = result.g;

			renderLinks(this._g, this._graphData.links);
			renderNodes(this._g, this._graphData.nodes, this.onNodeClick);

			this._simulation = createForceSimulation(
				this._graphData.nodes,
				this._graphData.links,
				width,
				height
			);

			this._zoomBehavior = setupZoom(this._svg, this._g, width, height);

			updateSimulation(this._simulation, this._g);

			// ResizeObserver for responsive re-render
			if (typeof ResizeObserver !== 'undefined') {
				this._resizeObserver = new ResizeObserver(this._onResize.bind(this));
				this._resizeObserver.observe(container);
			}
		},

		_onResize: function() {
			if (!this._graphData || !this.$refs.container || !this.$refs.skillMap) {
				return;
			}

			var container = this.$refs.container;
			var width = container.clientWidth || 800;
			var height = Math.max(container.clientHeight || 500, 400);

			// Update SVG dimensions
			this._svg
				.attr('width', width)
				.attr('height', height)
				.attr('viewBox', '0 0 ' + width + ' ' + height);

			// Re-center force simulation
			if (this._simulation) {
				this._simulation
					.force('center').x(width / 2).y(height / 2);
				this._simulation.alpha(0.3).restart();
			}
		},

		onNodeClick: function(node) {
			this.selectedNode = node;
		},

		closeSidebar: function() {
			this.selectedNode = null;
		},

		startPractice: function() {
			if (this.selectedNode) {
				this.$emit('openPool', this.selectedNode.id);
			}
		},
	},
};
</script>

<style scoped>
.skill-map-wrapper {
	width: 100%;
}

.skill-map-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 300px;
	gap: 0.5em;
	color: var(--color-text-maxcontrast, #888);
}

.skill-map-error {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 200px;
	color: #e74c3c;
}

.skill-map-legend {
	display: flex;
	gap: 1.2em;
	padding: 0.5em 1em;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #aaa);
}

.skill-map-legend__item {
	display: flex;
	align-items: center;
	gap: 0.3em;
}

.skill-map-legend__dot {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
}

.skill-map-sidebar__title {
	margin: 0.5em 0 0.2em;
	font-size: 1.2em;
}

.skill-map-sidebar__course {
	color: var(--color-text-maxcontrast, #aaa);
	font-size: 0.85em;
	margin: 0 0 1em;
}

.skill-map-sidebar__stat {
	margin-bottom: 0.8em;
}

.skill-map-sidebar__leitner {
	margin-bottom: 1em;
}

.leitner-bars {
	display: flex;
	gap: 0.8em;
	margin-top: 0.3em;
}

.leitner-bar {
	display: flex;
	flex-direction: column;
	align-items: center;
	font-size: 0.85em;
}

.leitner-bar__label {
	color: var(--color-text-maxcontrast, #aaa);
	font-size: 0.8em;
}

.leitner-bar__value {
	font-weight: bold;
}

.skill-map-sidebar__practice {
	display: block;
	width: 100%;
	padding: 0.7em 1em;
	margin-top: 1em;
	background: #27ae60;
	color: #fff;
	border: none;
	border-radius: 6px;
	font-size: 1em;
	cursor: pointer;
	transition: background 0.2s;
}

.skill-map-sidebar__practice:hover {
	background: #219a52;
}
</style>
