<template>
  <AiConsentDialog
    v-if="status"
    :recipient="status.recipient"
    :reason="status.reason"
    :previous-recipient-name="status.previous_recipient_name"
    :locale="locale"
    :error="error"
    :busy="busy"
    @accept="accept"
    @decline="decline" />
</template>

<script>
import { t, getLanguage } from '@nextcloud/l10n'
import AiConsentDialog from './AiConsentDialog.vue'
import { fetchConsentStatus, saveConsent, notifyConsentChanged } from '../utils/aiConsent.js'

/**
 * The only place a consent is written (spec 3.4). Mount it (v-if) when the user has to decide:
 * it loads GET /api/profile/telos/consent itself, shows AiConsentDialog for the recipient the
 * server reports, and stores the consent for exactly that recipient.
 *
 * Events:
 * - granted {written}  consent is valid now (written: false when it already was)
 * - declined           the user closed or declined the dialog
 * - unavailable {reason}  nothing to consent to: 'no_recipient' (AI switched off) or 'error'
 *                      (status not loadable - fail closed, no dialog)
 */
export default {
  name: 'AiConsentPrompt',
  components: { AiConsentDialog },
  props: {
    // 'dialog' | 'onboarding' (ConsentService::SOURCES)
    source: { type: String, default: 'dialog' },
    locale: { type: String, default: () => getLanguage() },
  },
  emits: ['granted', 'declined', 'unavailable'],
  data() {
    return { status: null, error: '', busy: false }
  },
  async mounted() {
    await this.load()
  },
  methods: {
    async load() {
      let data
      try {
        data = await fetchConsentStatus()
      } catch (e) {
        this.$emit('unavailable', { reason: 'error' })
        return
      }
      if (data && data.valid) {
        this.status = null
        this.$emit('granted', { written: false })
        return
      }
      if (!data || !data.recipient) {
        this.status = null
        this.$emit('unavailable', { reason: 'no_recipient' })
        return
      }
      this.status = data
    },
    async accept() {
      if (this.busy || !this.status || !this.status.recipient) return
      this.busy = true
      this.error = ''
      try {
        await saveConsent(this.status.recipient, this.source)
      } catch (e) {
        if (e && e.response && e.response.status === 409) {
          // The admin changed the recipient while the dialog was open: show the new one and ask again.
          await this.load()
          if (this.status) {
            this.error = t('learning', 'Der KI-Anbieter hat sich geändert. Bitte lies die Angaben noch einmal, bevor du zustimmst.')
          }
        } else {
          this.error = t('learning', 'Your consent could not be saved. Please try again.')
        }
        this.busy = false
        return
      }
      this.busy = false
      notifyConsentChanged(true)
      this.$emit('granted', { written: true })
    },
    decline() {
      this.error = ''
      this.$emit('declined')
    },
  },
}
</script>
