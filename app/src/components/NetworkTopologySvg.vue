<template>
  <svg
    ref="svg"
    :viewBox="viewBox"
    class="nts-svg"
    xmlns="http://www.w3.org/2000/svg"
  >
    <!-- Links rendered before nodes so they appear behind -->
    <line
      v-for="(link, index) in links"
      :key="link.from + '-' + link.to + '-' + index"
      :x1="nodeById(link.from).x"
      :y1="nodeById(link.from).y"
      :x2="nodeById(link.to).x"
      :y2="nodeById(link.to).y"
      class="nts-link"
    />

    <!-- Nodes -->
    <g
      v-for="node in nodes"
      :key="node.id"
      :transform="'translate(' + node.x + ',' + node.y + ')'"
      class="nts-node"
      :class="{ 'nts-node--interactive': !disabled }"
      @click="!disabled && $emit('node-click', node.id)"
    >
      <!-- Icon: use SVG paths if device type is known -->
      <g v-if="iconPaths(node.type).length" class="nts-icon">
        <path
          v-for="(d, i) in iconPaths(node.type)"
          :key="i"
          :d="d"
          class="nts-icon-path"
        />
      </g>
      <!-- Fallback: silent circle for unknown device types -->
      <circle v-else r="14" class="nts-icon-fallback" />

      <text y="22" text-anchor="middle" class="nts-label">{{ node.label }}</text>
    </g>
  </svg>
</template>

<script>
import { DEVICE_ICONS } from '../utils/networkTopologyIcons.js'

export default {
  name: 'NetworkTopologySvg',

  props: {
    topology: {
      type: Object,
      required: true,
    },
    disabled: {
      type: Boolean,
      default: false,
    },
  },

  emits: ['node-click'],

  computed: {
    nodes() {
      return this.topology.nodes || []
    },

    links() {
      return this.topology.links || []
    },

    viewBox() {
      const nodes = this.nodes
      if (!nodes.length) return '0 0 400 300'
      const pad = 40
      const xs = nodes.map(n => n.x)
      const ys = nodes.map(n => n.y)
      const minX = Math.min(...xs) - pad
      const minY = Math.min(...ys) - pad
      const w = Math.max(...xs) - Math.min(...xs) + pad * 2
      const h = Math.max(...ys) - Math.min(...ys) + pad * 2
      return `${minX} ${minY} ${w} ${h}`
    },
  },

  methods: {
    nodeById(id) {
      return this.nodes.find(n => n.id === id) || { x: 0, y: 0 }
    },

    iconPaths(type) {
      return DEVICE_ICONS[type] || []
    },

    /**
     * Returns the screen-space center of a node (for tooltip/popover positioning).
     * Returns null if the SVG ref is not mounted or CTM is unavailable.
     * @param {string} nodeId
     * @returns {{ x: number, y: number } | null}
     */
    getNodeScreenPosition(nodeId) {
      const svgEl = this.$refs.svg
      if (!svgEl) return null

      const node = this.nodeById(nodeId)
      const pt = svgEl.createSVGPoint()
      pt.x = node.x
      pt.y = node.y

      const ctm = svgEl.getScreenCTM()
      if (!ctm) return null

      const screenPt = pt.matrixTransform(ctm)
      return { x: screenPt.x, y: screenPt.y }
    },
  },
}
</script>

<style scoped>
.nts-svg {
  width: 100%;
  height: auto;
  display: block;
}

.nts-link {
  stroke: var(--color-border-dark, #999);
  stroke-width: 2;
  fill: none;
}

.nts-node {
  cursor: default;
}

.nts-node--interactive {
  cursor: pointer;
}

.nts-node--interactive:hover .nts-icon-path {
  stroke: var(--color-primary-element, #0082c9);
}

.nts-icon-path {
  fill: none;
  stroke: var(--color-main-text, #333);
  stroke-width: 1.5;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.nts-icon-fallback {
  fill: var(--color-background-hover, #f0f0f0);
  stroke: var(--color-border, #ccc);
  stroke-width: 1.5;
}

.nts-label {
  font-size: 10px;
  fill: var(--color-main-text, #333);
  font-family: inherit;
}
</style>
