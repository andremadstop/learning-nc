<template>
  <div v-if="!granted" class="ai-consent-hint" role="status">
    <p class="ai-consent-hint__text">{{ message }}</p>
    <NcButton v-if="!unavailable" type="button" variant="secondary" @click="open = true">
      {{ t('learning', 'Einwilligung ansehen') }}
    </NcButton>
    <AiConsentPrompt
      v-if="open"
      source="dialog"
      @granted="onGranted"
      @declined="open = false"
      @unavailable="onUnavailable" />
  </div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import AiConsentPrompt from './AiConsentPrompt.vue'

/**
 * Shown by every AI entry point instead of the feature when the server refused for lack of a
 * valid consent (consent_required, or recipient_changed). It only leads to the dialog; storing
 * the consent stays with AiConsentPrompt. Emits `granted` so the entry point can try again.
 */
export default {
  name: 'AiConsentHint',
  components: { AiConsentPrompt, NcButton },
  emits: ['granted'],
  data() {
    return { open: false, granted: false, unavailable: false }
  },
  computed: {
    message() {
      return this.unavailable
        ? t('learning', 'Auf diesem Server ist keine KI eingerichtet.')
        : t('learning', 'KI-Funktionen sind aus, bis du der Datenweitergabe an den KI-Anbieter zustimmst.')
    },
  },
  methods: {
    t,
    onGranted() {
      this.open = false
      this.granted = true
      this.$emit('granted')
    },
    onUnavailable() {
      this.open = false
      this.unavailable = true
    },
  },
}
</script>

<style scoped>
.ai-consent-hint {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 12px;
  padding: 10px 12px;
  border-radius: var(--border-radius-large, 8px);
  background: var(--color-background-hover, #f5f5f5);
  color: var(--color-main-text);
}
.ai-consent-hint__text { margin: 0; flex: 1 1 220px; }
</style>
