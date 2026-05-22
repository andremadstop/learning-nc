<template>
  <div class="pbq-renderer">
    <div v-if="instructions.length" class="pbq-instructions">
      <p class="pbq-instructions__title">{{ t('learning', 'Anweisungen') }}</p>
      <ul>
        <li v-for="instruction in instructions" :key="instruction">{{ instruction }}</li>
      </ul>
    </div>

    <PbqReferenceGallery
      v-if="referenceImages.length"
      :images="referenceImages"
    />

    <div v-else-if="scenarioImageUrl && subtype !== 'placement'" class="pbq-scenario-image-wrapper">
      <img :src="scenarioImageUrl" class="pbq-scenario-image" :alt="t('learning', 'Szenario')" />
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
    <PbqSwitchConfig
      v-else-if="subtype === 'switch_config'"
      :config="config"
      :value="localAnswer"
      :disabled="disabled"
      @update="onUpdate"
    />
    <PbqRoutingConfig
      v-else-if="subtype === 'routing_config'"
      :config="config"
      :value="localAnswer"
      :disabled="disabled"
      @update="onUpdate"
    />
    <PbqDiagnostic
      v-else-if="subtype === 'diagnostic'"
      :config="config"
      :value="localAnswer"
      :disabled="disabled"
      @update="onUpdate"
    />
    <PbqRanking
      v-else-if="subtype === 'ranking'"
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
import NcButton from '@nextcloud/vue/components/NcButton'
import { generateUrl } from '@nextcloud/router'
import PbqDropdown  from './PbqDropdown.vue'
import PbqPlacement from './PbqPlacement.vue'
import PbqCli       from './PbqCli.vue'
import PbqCable      from './PbqCable.vue'
import PbqMultiPanel from './PbqMultiPanel.vue'
import PbqSwitchConfig from './PbqSwitchConfig.vue'
import PbqRoutingConfig from './PbqRoutingConfig.vue'
import PbqDiagnostic from './PbqDiagnostic.vue'
import PbqRanking from './PbqRanking.vue'
import PbqReferenceGallery from './PbqReferenceGallery.vue'

export default {
  name: 'PbqRenderer',
  components: {
    NcButton,
    PbqDropdown,
    PbqPlacement,
    PbqCli,
    PbqCable,
    PbqMultiPanel,
    PbqSwitchConfig,
    PbqRoutingConfig,
    PbqDiagnostic,
    PbqRanking,
    PbqReferenceGallery,
  },
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
    subtype() {
      if (this.question.pbq_subtype) return this.question.pbq_subtype
      // Auto-detect subtype from config structure
      const cfg = this.config
      if (cfg.terminals && cfg.terminals.length > 0) return 'cli'
      if (cfg.items && cfg.items.length > 0 && cfg.items[0].correct_position !== undefined) return 'ranking'
      if (cfg.questions && cfg.questions.length > 0) return 'dropdown'
      if (cfg.devices || cfg.hotspots) return 'placement'
      if (cfg.pins) return 'cable'
      return ''
    },
    config()   { return this.question.pbq_config || {} },
    configImage() { return this.resolveAssetUrl(this.config.background_image || this.config.scenario_image || null) },
    topologyConfig() { return this.config.topology || null },
    instructions() { return this.config.instructions || [] },
    referenceImages() {
      return (this.config.reference_images || []).map(image => ({
        ...image,
        src: this.resolveAssetUrl(image.src),
      }))
    },
    scenarioImageUrl() {
      if (this.config.background_image || this.config.scenario_image) {
        return this.resolveAssetUrl(this.config.background_image || this.config.scenario_image)
      }
      if (this.question.image_path) return generateUrl('/apps/learning/api/questions/' + this.question.id + '/image')
      return null
    },
    totalCount() {
      const cfg = this.config
      const dropdownQuestions = (cfg.questions || []).filter(question => (question.scorable ?? true) === true).length
      switch (this.subtype) {
        case 'dropdown':  return dropdownQuestions
        case 'placement': return (cfg.positions || []).length
        case 'cli':       return (cfg.terminals || []).length
        case 'cable':     return (cfg.questions || []).length * 2
        case 'multi_panel': {
          const cliTerms = (cfg.cli && cfg.cli.terminals || []).length
          const placementPos = (cfg.placement && cfg.placement.positions || []).length
          const nestedDropdownQuestions = ((cfg.dropdown && cfg.dropdown.questions) || []).filter(question => (question.scorable ?? true) === true).length
          return cliTerms + placementPos + nestedDropdownQuestions
        }
        case 'switch_config':
          return (cfg.switches || []).reduce((sum, device) => sum + ((device.ports || []).filter(port => !!port.correct).length), 0)
        case 'routing_config':
          return (cfg.routers || []).length
        case 'diagnostic':
          return (cfg.findings || []).length
        case 'ranking':
          return (cfg.items || []).length
        default: return 0
      }
    },
    answeredCount() {
      if (this.subtype === 'multi_panel') {
        return Object.keys(this.localAnswer.cli || {}).length
          + Object.keys(this.localAnswer.placement || {}).length
          + Object.keys(this.localAnswer.dropdown || {}).length
      }
      return Object.keys(this.localAnswer).length
    },
  },
  methods: {
    resolveAssetUrl(url) {
      if (!url || typeof url !== 'string') {
        return url
      }
      if (url.startsWith('data:') || url.startsWith('http://') || url.startsWith('https://')) {
        return url
      }
      if (url.startsWith('/')) {
        return generateUrl(url)
      }
      return url
    },
    onUpdate(key, value) {
      this.localAnswer[String(key)] = value
      this.$emit('change', { ...this.localAnswer })
    },
  },
}
</script>

<style scoped>
.pbq-renderer { padding: 8px 0; }
.pbq-instructions {
  margin-bottom: 16px;
  padding: 14px 16px;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-background-hover);
}
.pbq-instructions__title {
  margin: 0 0 8px;
  font-weight: 700;
  color: var(--color-main-text);
}
.pbq-instructions ul {
  margin: 0;
  padding-left: 18px;
}
.pbq-instructions li + li {
  margin-top: 4px;
}
.pbq-scenario-image-wrapper { margin-bottom: 16px; }
.pbq-scenario-image { max-width: 100%; border-radius: 8px; border: 1px solid var(--color-border); }
.pbq-footer { display: flex; align-items: center; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--color-border); }
.pbq-progress { flex: 1; color: var(--color-text-maxcontrast); font-size: 13px; }
</style>
