<template>
  <AccessibleDialog
    :name="text.title"
    :aria-describedby="bodyId"
    size="normal"
    @closing="decline">
    <div
      :id="bodyId"
      class="ai-consent"
      :dir="dir"
      :lang="lang"
      @keydown.esc.stop="decline">
      <p v-if="reasonText" class="ai-consent__reason" role="note">{{ reasonText }}</p>

      <!-- eslint-disable-next-line vue/no-v-html -- static JSON bundled with the app, no user input; placeholders are only in plain-text fields -->
      <div class="ai-consent__intro" v-html="text.intro_html"></div>

      <p v-if="recipient" class="ai-consent__provider"><strong>{{ providerSentence }}</strong></p>

      <h3 class="ai-consent__heading">{{ text.data_list_heading }}</h3>
      <!-- eslint-disable-next-line vue/no-v-html -- static JSON bundled with the app, no user input; placeholders are only in plain-text fields -->
      <div class="ai-consent__data" v-html="text.data_list_html"></div>
      <p>{{ text.identity_note }}</p>

      <p v-if="regionText">{{ regionText }}</p>
      <p v-if="recipient && recipient.intermediary">{{ fill(text.intermediary_paragraph) }}</p>
      <p>{{ fill(text.automation_paragraph) }}</p>
      <p>{{ text.retention_note }}</p>
      <p>{{ text.revocation_note }}</p>

      <p v-if="error" class="ai-consent__error" role="alert">{{ error }}</p>
    </div>
    <template #actions>
      <NcButton type="button" variant="tertiary" @click="decline">{{ text.decline_label }}</NcButton>
      <NcButton type="button" variant="primary" :disabled="busy || !recipient" @click="accept">{{ text.accept_label }}</NcButton>
    </template>
  </AccessibleDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import { getLanguage } from '@nextcloud/l10n'
import AccessibleDialog from './AccessibleDialog.vue'
import consent from '../../data/ai-consent.json'

const RTL = ['ar', 'he', 'fa', 'ur']
let idCounter = 0

function normalizeLang(locale) {
  const short = String(locale || '').toLowerCase().split(/[-_]/)[0]
  return consent.locales[short] ? short : 'en'
}

/**
 * The single consent dialog (spec 3.4). It only shows the consent text of the active language
 * for the recipient the server reported and emits `accept` / `decline`; storing the consent
 * (POST /api/profile/telos/consent with recipient.fingerprint) is the caller's job.
 *
 * Props mirror GET /api/profile/telos/consent: `recipient` ({name, region, processing_local,
 * intermediary}), `reason` ('never' | 'version' | 'recipient_changed' | ...), and
 * `previousRecipientName`.
 */
export default {
  name: 'AiConsentDialog',
  components: { AccessibleDialog, NcButton },
  props: {
    recipient: { type: Object, default: null },
    reason: { type: String, default: 'never' },
    previousRecipientName: { type: String, default: null },
    locale: { type: String, default: () => getLanguage() },
    error: { type: String, default: '' },
    busy: { type: Boolean, default: false },
  },
  emits: ['accept', 'decline'],
  data() {
    idCounter += 1
    return { bodyId: 'ai-consent-body-' + idCounter }
  },
  computed: {
    lang() { return normalizeLang(this.locale) },
    dir() { return RTL.includes(this.lang) ? 'rtl' : 'ltr' },
    text() { return consent.locales[this.lang] },
    reasonText() {
      const headers = this.text.reason_headers
      if (this.reason === 'version') return this.fill(headers.version)
      if (this.reason === 'recipient_changed') {
        return this.fill(this.previousRecipientName ? headers.recipient_changed : headers.recipient_changed_unknown)
      }
      return ''
    },
    providerSentence() { return this.fill(this.text.provider_sentence) },
    regionText() {
      if (!this.recipient) return ''
      const paragraphs = this.text.region_paragraphs
      const key = this.recipient.processing_local ? 'local' : this.recipient.region
      return this.fill(paragraphs[key] || paragraphs.unknown)
    },
  },
  methods: {
    fill(template) {
      return String(template)
        .replaceAll('{provider}', this.recipient ? this.recipient.name : '')
        .replaceAll('{previous}', this.previousRecipientName || '')
    },
    accept() {
      if (this.busy || !this.recipient) return
      this.$emit('accept')
    },
    decline() {
      this.$emit('decline')
    },
  },
}
</script>

<style scoped>
.ai-consent { line-height: 1.5; }
.ai-consent p { margin: 0 0 12px; }
.ai-consent__reason {
  padding: 8px 12px;
  border-radius: var(--border-radius-large, 8px);
  background: var(--color-primary-element-light, #e6f0f9);
  font-weight: 600;
}
.ai-consent__heading { margin: 16px 0 4px; font-size: 1em; }
.ai-consent__data :deep(ul) { margin: 0 0 12px; padding-inline-start: 20px; }
.ai-consent__error { color: var(--color-error-text, #b00020); font-weight: 600; }
</style>
