<template>
  <div class="pbq-placement">
    <div class="pbq-diagram-wrapper">
      <!-- SVG topology mode: takes priority over image -->
      <NetworkTopologySvg
        v-if="topologyConfig"
        ref="topologySvg"
        :topology="topologyConfig"
        :disabled="disabled"
        @node-click="openPicker"
      />
      <!-- Image mode: existing behavior unchanged -->
      <img v-else-if="scenarioImage" :src="scenarioImage" class="pbq-diagram-img" :alt="t('learning', 'Network diagram')" />
      <!-- Fallback topology grid when no diagram image is available -->
      <div v-else class="pbq-topology-grid pbq-topology-grid--with-bg">
        <svg class="pbq-placement__fallback-bg" viewBox="0 0 400 300" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <!-- Router (Mitte) -->
          <rect x="175" y="130" width="50" height="35" rx="4" fill="none" stroke="var(--color-border-dark, #888)" stroke-width="2"/>
          <text x="200" y="152" text-anchor="middle" font-size="10" fill="var(--color-text-maxcontrast, #666)">Router</text>
          <!-- Switch links -->
          <rect x="60" y="220" width="50" height="30" rx="3" fill="none" stroke="var(--color-border-dark, #888)" stroke-width="1.5"/>
          <text x="85" y="239" text-anchor="middle" font-size="9" fill="var(--color-text-maxcontrast, #666)">SW1</text>
          <!-- Switch rechts -->
          <rect x="290" y="220" width="50" height="30" rx="3" fill="none" stroke="var(--color-border-dark, #888)" stroke-width="1.5"/>
          <text x="315" y="239" text-anchor="middle" font-size="9" fill="var(--color-text-maxcontrast, #666)">SW2</text>
          <!-- Verbindungen Router→Switches -->
          <line x1="200" y1="165" x2="85" y2="220" stroke="var(--color-border-dark, #888)" stroke-width="1.5"/>
          <line x1="200" y1="165" x2="315" y2="220" stroke="var(--color-border-dark, #888)" stroke-width="1.5"/>
          <!-- PCs -->
          <rect x="20" y="60" width="40" height="30" rx="3" fill="none" stroke="var(--color-border-dark, #888)" stroke-width="1"/>
          <text x="40" y="79" text-anchor="middle" font-size="9" fill="var(--color-text-maxcontrast, #666)">PC1</text>
          <rect x="100" y="60" width="40" height="30" rx="3" fill="none" stroke="var(--color-border-dark, #888)" stroke-width="1"/>
          <text x="120" y="79" text-anchor="middle" font-size="9" fill="var(--color-text-maxcontrast, #666)">PC2</text>
          <rect x="260" y="60" width="40" height="30" rx="3" fill="none" stroke="var(--color-border-dark, #888)" stroke-width="1"/>
          <text x="280" y="79" text-anchor="middle" font-size="9" fill="var(--color-text-maxcontrast, #666)">PC3</text>
          <rect x="340" y="60" width="40" height="30" rx="3" fill="none" stroke="var(--color-border-dark, #888)" stroke-width="1"/>
          <text x="360" y="79" text-anchor="middle" font-size="9" fill="var(--color-text-maxcontrast, #666)">PC4</text>
          <!-- Verbindungen Switches→PCs -->
          <line x1="85" y1="220" x2="40" y2="90" stroke="var(--color-border-dark, #888)" stroke-width="1"/>
          <line x1="85" y1="220" x2="120" y2="90" stroke="var(--color-border-dark, #888)" stroke-width="1"/>
          <line x1="315" y1="220" x2="280" y2="90" stroke="var(--color-border-dark, #888)" stroke-width="1"/>
          <line x1="315" y1="220" x2="360" y2="90" stroke="var(--color-border-dark, #888)" stroke-width="1"/>
        </svg>
        <div
          v-for="pos in config.positions"
          :key="pos.id"
          class="pbq-topology-node"
          :class="{ 'pbq-topology-node--assigned': !!value[pos.id] }"
          @click="!disabled && openPicker(pos.id)"
        >
          <div class="pbq-topology-icon">{{ value[pos.id] ? '✓' : '?' }}</div>
          <div class="pbq-topology-label">{{ pos.label }}</div>
          <div v-if="value[pos.id]" class="pbq-topology-assigned">{{ value[pos.id] }}</div>
        </div>
      </div>
      <!-- Hotspot overlays: only in image mode, not SVG topology mode -->
      <template v-if="scenarioImage && !topologyConfig">
      <div
        v-for="pos in config.positions"
        :key="'hs-' + pos.id"
        class="pbq-hotspot"
        :style="{ left: pos.x_pct + '%', top: pos.y_pct + '%' }"
        :class="{ 'pbq-hotspot--assigned': !!value[pos.id] }"
        :title="pos.label"
        @click="!disabled && openPicker(pos.id)"
      >
        <span v-if="!value[pos.id]">?</span>
        <span v-else class="pbq-hotspot-label">{{ value[pos.id].substring(0,4) }}</span>
      </div>
      </template>

      <!-- Inline picker overlay: only in SVG topology mode, positioned above clicked node -->
      <div
        v-if="activePosId && pickerPos && topologyConfig"
        class="pbq-inline-picker"
        :style="{ left: pickerPos.left + 'px', top: pickerPos.top + 'px' }"
        @click.stop
      >
        <p class="pbq-picker-title"><strong>{{ labelFor(activePosId) }}</strong></p>
        <button
          v-for="device in config.device_options"
          :key="device"
          class="pbq-device-btn"
          :class="{ 'pbq-device-btn--selected': value[activePosId] === device }"
          @click="assignDevice(activePosId, device)"
        >{{ device }}</button>
        <button class="pbq-device-btn pbq-device-btn--cancel" @click="closePicker">{{ t('learning', 'Cancel') }}</button>
      </div>
    </div>

    <!-- Below-diagram picker: only in non-SVG mode (image or grid) -->
    <div v-if="activePosId && !topologyConfig" class="pbq-device-picker">
      <p class="pbq-picker-title"><strong>{{ labelFor(activePosId) }}</strong></p>
      <button
        v-for="device in config.device_options"
        :key="device"
        class="pbq-device-btn"
        :class="{ 'pbq-device-btn--selected': value[activePosId] === device }"
        @click="assignDevice(activePosId, device)"
      >{{ device }}</button>
      <button class="pbq-device-btn pbq-device-btn--cancel" @click="closePicker">{{ t('learning', 'Cancel') }}</button>
    </div>

    <div class="pbq-placement-summary">
      <span v-for="pos in config.positions" :key="pos.id" class="pbq-summary-item">
        <span class="pbq-summary-label">{{ pos.label }}:</span>
        <span
          class="pbq-summary-value"
          :class="[
            !value[pos.id] ? 'pbq-unset' : '',
            disabled && pos.correct !== undefined && !matchesPlacement(pos.correct, value[pos.id]) ? 'pbq-summary-value--wrong' : '',
            disabled && pos.correct !== undefined && matchesPlacement(pos.correct, value[pos.id]) ? 'pbq-summary-value--correct' : '',
          ]"
        >{{ value[pos.id] || '—' }}</span>
      </span>
      <div v-if="disabled && scoringSummaryText" class="pbq-scoring-summary">{{ scoringSummaryText }}</div>
    </div>
  </div>
</template>

<script>
import NetworkTopologySvg from './NetworkTopologySvg.vue'
import { scoringSummary } from '../utils/pbqScoringMode.js'

function matchesPlacement(expected, actual) {
  if (Array.isArray(expected)) {
    return expected.includes(actual)
  }
  return actual === expected
}

export default {
  name: 'PbqPlacement',
  components: { NetworkTopologySvg },
  props: {
    config: { type: Object, required: true },
    value: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    scenarioImage: { type: String, default: null },
    topologyConfig: { type: Object, default: null },
  },
  data() {
    return {
      activePosId: null,
      pickerPos: null,
    }
  },
  computed: {
    scoringSummaryText() {
      const mode = this.config.scoring_mode || 'strict'
      return scoringSummary(this.config.positions || [], this.value, mode)
    },
  },
  mounted() {
    window.addEventListener('scroll', this.closePicker, { passive: true })
  },
  beforeDestroy() {
    window.removeEventListener('scroll', this.closePicker)
  },
  methods: {
    openPicker(posId) {
      this.activePosId = posId
      this.pickerPos = null
      if (!this.topologyConfig || !this.$refs.topologySvg) return
      this.$nextTick(() => {
        const screenPos = this.$refs.topologySvg.getNodeScreenPosition(posId)
        if (!screenPos) return
        const wrapper = this.$el.querySelector('.pbq-diagram-wrapper')
        if (!wrapper) return
        const wRect = wrapper.getBoundingClientRect()
        this.pickerPos = {
          left: screenPos.x - wRect.left,
          top: screenPos.y - wRect.top,
        }
      })
    },
    closePicker() {
      this.activePosId = null
      this.pickerPos = null
    },
    labelFor(posId) {
      const pos = (this.config.positions || []).find(p => p.id === posId)
      return pos ? pos.label : posId
    },
    assignDevice(posId, device) {
      this.$emit('update', posId, device)
      this.closePicker()
    },
    matchesPlacement,
  },
}
</script>

<style scoped>
.pbq-placement { position: relative; }
.pbq-diagram-wrapper { position: relative; display: inline-block; max-width: 100%; width: 100%; overflow: visible; }
.pbq-diagram-img { max-width: 100%; border-radius: 8px; border: 1px solid var(--color-border); }
.pbq-topology-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
  padding: 16px;
  background: var(--color-background-hover);
  border-radius: 12px;
  border: 1px solid var(--color-border);
  width: 100%;
  box-sizing: border-box;
  position: relative;
}
.pbq-placement__fallback-bg {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  opacity: 0.3;
  pointer-events: none;
}
.pbq-topology-node {
  display: flex; flex-direction: column; align-items: center;
  padding: 16px 12px; border-radius: 10px;
  border: 2px dashed var(--color-border);
  cursor: pointer; transition: border-color .15s, background .15s;
  background: var(--color-main-background);
  gap: 6px;
}
.pbq-topology-node:hover { border-color: var(--color-primary-element); background: var(--color-background-hover); }
.pbq-topology-node--assigned { border-style: solid; border-color: var(--color-success); }
.pbq-topology-icon { font-size: 22px; font-weight: 700; color: var(--color-text-maxcontrast); }
.pbq-topology-node--assigned .pbq-topology-icon { color: var(--color-success); }
.pbq-topology-label { font-size: 12px; font-weight: 600; text-align: center; color: var(--color-main-text); }
.pbq-topology-assigned { font-size: 11px; color: var(--color-success); font-weight: 500; text-align: center; }
.pbq-hotspot {
  position: absolute; transform: translate(-50%, -50%);
  width: 36px; height: 36px; border-radius: 50%;
  background: var(--color-warning); color: #fff; font-weight: 700; font-size: 18px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,.3);
  transition: background .2s;
}
.pbq-hotspot--assigned { background: var(--color-success); font-size: 11px; }
.pbq-hotspot-label { text-align: center; line-height: 1.1; }
.pbq-device-picker {
  margin-top: 16px; padding: 12px; background: var(--color-background-hover);
  border-radius: 8px; border: 1px solid var(--color-border);
}
.pbq-picker-title { margin-bottom: 10px; }
.pbq-device-btn {
  display: inline-block; margin: 4px; padding: 6px 14px;
  border: 1px solid var(--color-border); border-radius: 20px;
  background: var(--color-main-background); cursor: pointer; transition: background .15s;
}
.pbq-device-btn:hover { background: var(--color-background-hover); }
.pbq-device-btn--selected { background: var(--color-primary-element); color: #fff; border-color: var(--color-primary-element); }
.pbq-device-btn--cancel { color: var(--color-text-maxcontrast); }
.pbq-placement-summary { margin-top: 16px; display: flex; flex-wrap: wrap; gap: 8px; }
.pbq-summary-item { background: var(--color-background-hover); padding: 4px 10px; border-radius: 12px; font-size: 13px; }
.pbq-summary-label { font-weight: 500; margin-right: 4px; }
.pbq-unset { color: var(--color-text-maxcontrast); font-style: italic; }
.pbq-summary-value--correct { color: var(--color-success); font-weight: 600; }
.pbq-summary-value--wrong { color: var(--color-error); text-decoration: line-through; }
.pbq-scoring-summary {
  width: 100%;
  margin-top: 8px;
  font-weight: 600;
  font-size: 13px;
  color: var(--color-main-text);
}
.pbq-inline-picker {
  position: absolute;
  transform: translate(-50%, calc(-100% - 8px));
  background: var(--color-main-background);
  border: 1px solid var(--color-border-dark);
  border-radius: 8px;
  box-shadow: 0 4px 16px rgba(0,0,0,.18);
  padding: 10px 12px;
  z-index: 100;
  min-width: 160px;
  max-width: 240px;
}
</style>
