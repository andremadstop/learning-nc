<template>
  <div class="pbq-cable">
    <!-- Cable tabs if multiple cables -->
    <div v-if="config.cables && config.cables.length > 1" class="pbq-cable-tabs">
      <button
        v-for="cable in config.cables"
        :key="cable.id"
        class="pbq-cable-tab"
        :class="{ 'pbq-cable-tab--active': activeCableId === cable.id }"
        @click="activeCableId = cable.id"
      >{{ cable.label }}</button>
    </div>

    <div v-if="currentCable" class="pbq-cable-content">
      <!-- SVG pin matrix -->
      <div class="pbq-cable-svg-wrapper">
        <svg :width="svgWidth" :height="svgHeight" class="pbq-cable-svg">
          <!-- Left connector label -->
          <text x="30" y="14" text-anchor="middle" class="pbq-connector-label">A</text>
          <!-- Right connector label -->
          <text :x="svgWidth - 30" y="14" text-anchor="middle" class="pbq-connector-label">B</text>

          <!-- Wires (drawn first, behind pins) -->
          <line
            v-for="([from, to], i) in currentCable.pins"
            :key="'w' + i"
            :x1="54" :y1="pinY(from)"
            :x2="svgWidth - 54" :y2="pinY(to)"
            :stroke="wireColor(i)"
            stroke-width="2"
            :class="{ 'pbq-wire--cross': from !== to }"
          />

          <!-- Left pins -->
          <g v-for="pin in 8" :key="'L' + pin">
            <circle :cx="30" :cy="pinY(pin)" r="10" class="pbq-pin pbq-pin--left" />
            <text :x="30" :y="pinY(pin) + 5" text-anchor="middle" class="pbq-pin-text">{{ pin }}</text>
            <text :x="54" :y="pinY(pin) + 5" text-anchor="start" class="pbq-pin-wire-label" :fill="wireColor(pin - 1)">&#x2500;&#x2500;</text>
          </g>

          <!-- Right pins -->
          <g v-for="pin in 8" :key="'R' + pin">
            <circle :cx="svgWidth - 30" :cy="pinY(pin)" r="10" class="pbq-pin pbq-pin--right" />
            <text :x="svgWidth - 30" :y="pinY(pin) + 5" text-anchor="middle" class="pbq-pin-text">{{ pin }}</text>
          </g>
        </svg>
      </div>

      <!-- Cable metrics if provided -->
      <div v-if="currentCable.metrics" class="pbq-cable-metrics">
        <table class="pbq-metrics-table">
          <tr v-for="(val, key) in currentCable.metrics" :key="key">
            <td class="pbq-metric-key">{{ key }}</td>
            <td class="pbq-metric-val">{{ val }}</td>
          </tr>
        </table>
      </div>

      <!-- Remediation panel -->
      <div v-if="questionForCable" class="pbq-remediation">
        <div class="pbq-remediation-row">
          <label>Problem:</label>
          <select
            :value="value[currentCable.id + '_problem'] || ''"
            :disabled="disabled"
            @change="$emit('update', currentCable.id + '_problem', $event.target.value)"
          >
            <option value="" disabled>— Select problem —</option>
            <option v-for="p in config.remediation_options.problems" :key="p" :value="p">{{ p }}</option>
          </select>
        </div>
        <div class="pbq-remediation-row">
          <label>Solution:</label>
          <select
            :value="value[currentCable.id + '_solution'] || ''"
            :disabled="disabled"
            @change="$emit('update', currentCable.id + '_solution', $event.target.value)"
          >
            <option value="" disabled>— Select solution —</option>
            <option v-for="s in config.remediation_options.solutions" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
const WIRE_COLORS = ['#e67e22','#ffffff','#27ae60','#3498db','#3498db','#27ae60','#8e44ad','#8e44ad']

export default {
  name: 'PbqCable',
  props: {
    config: { type: Object, required: true },
    value: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
  },
  data() {
    const firstCable = (this.config.cables || [])[0]
    return { activeCableId: firstCable ? firstCable.id : null }
  },
  computed: {
    svgWidth() { return 320 },
    svgHeight() { return 8 * 28 + 30 },
    currentCable() {
      return (this.config.cables || []).find(c => c.id === this.activeCableId) || null
    },
    questionForCable() {
      if (!this.currentCable) return null
      return (this.config.questions || []).find(q => q.cable_id === this.currentCable.id) || null
    },
  },
  methods: {
    pinY(pin) { return 24 + (pin - 1) * 28 },
    wireColor(idx) { return WIRE_COLORS[idx % WIRE_COLORS.length] },
  },
}
</script>

<style scoped>
.pbq-cable { margin-top: 16px; }
.pbq-cable-tabs { display: flex; gap: 8px; margin-bottom: 12px; }
.pbq-cable-tab { padding: 6px 16px; border: 1px solid var(--color-border); border-radius: 20px; cursor: pointer; background: var(--color-main-background); }
.pbq-cable-tab--active { background: var(--color-primary-element); color: #fff; border-color: var(--color-primary-element); }
.pbq-cable-svg-wrapper { overflow-x: auto; }
.pbq-cable-svg { display: block; }
.pbq-connector-label { font-size: 12px; font-weight: 700; fill: var(--color-text-maxcontrast); }
.pbq-pin { fill: var(--color-background-hover); stroke: var(--color-border); stroke-width: 1.5; }
.pbq-pin-text { font-size: 11px; font-weight: 600; fill: var(--color-main-text); }
.pbq-wire--cross { stroke-dasharray: none; }
.pbq-cable-metrics { margin: 12px 0; }
.pbq-metrics-table { border-collapse: collapse; font-size: 13px; }
.pbq-metrics-table td { padding: 3px 12px 3px 0; }
.pbq-metric-key { font-weight: 500; color: var(--color-text-maxcontrast); }
.pbq-remediation { margin-top: 16px; padding: 14px; background: var(--color-background-hover); border-radius: 8px; border: 1px solid var(--color-border); }
.pbq-remediation-row { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.pbq-remediation-row label { min-width: 80px; font-weight: 500; }
.pbq-remediation-row select { flex: 1; padding: 6px 10px; border: 1px solid var(--color-border); border-radius: 6px; background: var(--color-main-background); color: var(--color-main-text); }
</style>
