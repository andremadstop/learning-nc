<template>
  <div class="pbq-placement">
    <div class="pbq-diagram-wrapper">
      <img v-if="scenarioImage" :src="scenarioImage" class="pbq-diagram-img" alt="Network diagram" />
      <div
        v-for="pos in config.positions"
        :key="pos.id"
        class="pbq-hotspot"
        :style="{ left: pos.x_pct + '%', top: pos.y_pct + '%' }"
        :class="{ 'pbq-hotspot--assigned': !!value[pos.id] }"
        :title="pos.label"
        @click="!disabled && openPicker(pos.id)"
      >
        <span v-if="!value[pos.id]">?</span>
        <span v-else class="pbq-hotspot-label">{{ value[pos.id].substring(0,4) }}</span>
      </div>
    </div>

    <div v-if="activePosId" class="pbq-device-picker">
      <p class="pbq-picker-title"><strong>{{ labelFor(activePosId) }}</strong></p>
      <button
        v-for="device in config.device_options"
        :key="device"
        class="pbq-device-btn"
        :class="{ 'pbq-device-btn--selected': value[activePosId] === device }"
        @click="assignDevice(activePosId, device)"
      >{{ device }}</button>
      <button class="pbq-device-btn pbq-device-btn--cancel" @click="activePosId = null">Cancel</button>
    </div>

    <div class="pbq-placement-summary">
      <span v-for="pos in config.positions" :key="pos.id" class="pbq-summary-item">
        <span class="pbq-summary-label">{{ pos.label }}:</span>
        <span class="pbq-summary-value" :class="value[pos.id] ? '' : 'pbq-unset'">
          {{ value[pos.id] || '—' }}
        </span>
      </span>
    </div>
  </div>
</template>

<script>
export default {
  name: 'PbqPlacement',
  props: {
    config: { type: Object, required: true },
    value: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    scenarioImage: { type: String, default: null },
  },
  data() { return { activePosId: null } },
  methods: {
    openPicker(posId) { this.activePosId = posId },
    labelFor(posId) {
      const pos = (this.config.positions || []).find(p => p.id === posId)
      return pos ? pos.label : posId
    },
    assignDevice(posId, device) {
      this.$emit('update', posId, device)
      this.activePosId = null
    },
  },
}
</script>

<style scoped>
.pbq-placement { position: relative; }
.pbq-diagram-wrapper { position: relative; display: inline-block; max-width: 100%; }
.pbq-diagram-img { max-width: 100%; border-radius: 8px; border: 1px solid var(--color-border); }
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
</style>
