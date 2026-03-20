<template>
  <div class="pbq-multi-panel">
    <div class="pbq-panel pbq-panel--cli">
      <PbqCli
        :config="config.cli || {}"
        :value="value.cli || {}"
        :disabled="disabled"
        @update="onCliUpdate"
      />
    </div>
    <div class="pbq-panel pbq-panel--topology">
      <PbqPlacement
        :config="config.placement || {}"
        :value="value.placement || {}"
        :disabled="disabled"
        :topology-config="config.topology || null"
        @update="onPlacementUpdate"
      />
    </div>
  </div>
</template>

<script>
import PbqCli       from './PbqCli.vue'
import PbqPlacement from './PbqPlacement.vue'

export default {
  name: 'PbqMultiPanel',
  components: { PbqCli, PbqPlacement },
  props: {
    config:   { type: Object, required: true },
    value:    { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
  },
  methods: {
    onCliUpdate(termName, history) {
      const cliVal = { ...(this.value.cli || {}), [termName]: history }
      this.$emit('update', 'cli', cliVal)
    },
    onPlacementUpdate(posId, device) {
      const placementVal = { ...(this.value.placement || {}), [posId]: device }
      this.$emit('update', 'placement', placementVal)
    },
  },
}
</script>

<style scoped>
.pbq-multi-panel {
  display: flex;
  flex-direction: row;
  gap: 16px;
  align-items: flex-start;
  /* KEIN overflow: hidden — PbqPlacement's inline-picker nutzt position:absolute */
}
.pbq-panel {
  flex: 1;
  min-width: 0; /* verhindert horizontal overflow in flex-children */
}
@media (max-width: 768px) {
  .pbq-multi-panel {
    flex-direction: column;
  }
}
</style>
