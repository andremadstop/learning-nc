<template>
  <div class="pbq-renderer">
    <div v-if="scenarioImageUrl" class="pbq-scenario-image-wrapper">
      <img :src="scenarioImageUrl" class="pbq-scenario-image" alt="Scenario" />
    </div>

    <PbqDropdown
      v-if="subtype === 'dropdown'"
      :config="config"
      :value="localAnswer"
      :disabled="disabled"
      @update="onUpdate"
    />
    <PbqPlacement
      v-else-if="subtype === 'placement'"
      :config="config"
      :value="localAnswer"
      :disabled="disabled"
      :scenario-image="configImage"
      :topology-config="topologyConfig"
      @update="onUpdate"
    />
    <PbqCli
      v-else-if="subtype === 'cli'"
      :config="config"
      :value="localAnswer"
      :disabled="disabled"
      @update="onUpdate"
    />
    <PbqCable
      v-else-if="subtype === 'cable'"
      :config="config"
      :value="localAnswer"
      :disabled="disabled"
      @update="onUpdate"
    />
    <PbqMultiPanel
      v-else-if="subtype === 'multi_panel'"
      :config="config"
      :value="localAnswer"
      :disabled="disabled"
      @update="onUpdate"
    />

    <div class="pbq-footer">
      <span class="pbq-progress">{{ answeredCount }} / {{ totalCount }} beantwortet</span>
      <NcButton type="primary" :disabled="disabled || totalCount === 0" @click="$emit('submit', localAnswer)">
        PBQ abschicken
      </NcButton>
      <NcButton type="secondary" @click="$emit('skip')">Überspringen</NcButton>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { generateUrl } from '@nextcloud/router'
import PbqDropdown  from './PbqDropdown.vue'
import PbqPlacement from './PbqPlacement.vue'
import PbqCli       from './PbqCli.vue'
import PbqCable      from './PbqCable.vue'
import PbqMultiPanel from './PbqMultiPanel.vue'

export default {
  name: 'PbqRenderer',
  components: { NcButton, PbqDropdown, PbqPlacement, PbqCli, PbqCable, PbqMultiPanel },
  props: {
    question:     { type: Object, required: true },
    initialValue: { type: Object, default: null },
    disabled:     { type: Boolean, default: false },
  },
  data() {
    return {
      localAnswer: this.initialValue ? { ...this.initialValue } : {},
    }
  },
  computed: {
    subtype()  { return this.question.pbq_subtype || '' },
    config()   { return this.question.pbq_config || {} },
    configImage() { return this.config.scenario_image || null },
    topologyConfig() { return this.config.topology || null },
    scenarioImageUrl() {
      if (this.config.scenario_image) return this.config.scenario_image  // base64 data URI
      if (this.question.image_path) return generateUrl('/apps/learning/api/questions/' + this.question.id + '/image')
      return null
    },
    totalCount() {
      const cfg = this.config
      switch (this.subtype) {
        case 'dropdown':  return (cfg.questions || []).length
        case 'placement': return (cfg.positions || []).length
        case 'cli':       return (cfg.terminals || []).length
        case 'cable':     return (cfg.questions || []).length * 2
        case 'multi_panel': {
          const cliTerms = (cfg.cli && cfg.cli.terminals || []).length
          const placementPos = (cfg.placement && cfg.placement.positions || []).length
          return cliTerms + placementPos
        }
        default: return 0
      }
    },
    answeredCount() {
      if (this.subtype === 'multi_panel') {
        return Object.keys(this.localAnswer.cli || {}).length
          + Object.keys(this.localAnswer.placement || {}).length
      }
      return Object.keys(this.localAnswer).length
    },
  },
  methods: {
    onUpdate(key, value) {
      this.$set(this.localAnswer, String(key), value)
      this.$emit('change', { ...this.localAnswer })
    },
  },
}
</script>

<style scoped>
.pbq-renderer { padding: 8px 0; }
.pbq-scenario-image-wrapper { margin-bottom: 16px; }
.pbq-scenario-image { max-width: 100%; border-radius: 8px; border: 1px solid var(--color-border); }
.pbq-footer { display: flex; align-items: center; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--color-border); }
.pbq-progress { flex: 1; color: var(--color-text-maxcontrast); font-size: 13px; }
</style>
