<template>
  <div class="learning-settings-wrap">
    <h2>{{ t('learning', 'Personal Settings') }}</h2>

    <div v-if="loading" class="loading">
      <NcLoadingIcon :size="32" />
      <span>{{ t('learning', 'Loading...') }}</span>
    </div>

    <div v-else class="settings-form">
      <div class="field-row">
        <label>{{ t('learning', 'Daily Challenge enabled') }}</label>
        <NcCheckboxRadioSwitch
          :checked="form.dailyChallengeEnabled"
          type="switch"
          @update:checked="form.dailyChallengeEnabled = !!$event" />
      </div>

      <div class="field-row">
        <label for="ui-language">{{ t('learning', 'UI Language') }}</label>
        <select id="ui-language" v-model="form.uiLanguage" class="nc-input">
          <option value="">{{ t('learning', 'System default') }}</option>
          <option value="de">{{ t('learning', 'German (Deutsch)') }}</option>
          <option value="en">{{ t('learning', 'English') }}</option>
        </select>
      </div>

      <div class="field-row">
        <label for="content-language">{{ t('learning', 'Content Language') }}</label>
        <select id="content-language" v-model="form.contentLanguage" class="nc-input">
          <option value="">{{ t('learning', 'Original content') }}</option>
          <option value="de">{{ t('learning', 'German (Deutsch)') }}</option>
          <option value="en">{{ t('learning', 'English') }}</option>
          <option value="ru">{{ t('learning', 'Russian') }}</option>
        </select>
      </div>

      <div class="field-row">
        <label>{{ t('learning', 'Virtual assistant') }}</label>
        <NcCheckboxRadioSwitch
          :checked="form.virtuProfEnabled"
          type="switch"
          @update:checked="form.virtuProfEnabled = !!$event" />
      </div>

      <div class="field-row">
        <label>{{ t('learning', 'Notifications enabled') }}</label>
        <NcCheckboxRadioSwitch
          :checked="form.notificationsEnabled"
          type="switch"
          @update:checked="form.notificationsEnabled = !!$event" />
      </div>

      <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
      <NcNoteCard v-if="saved" type="success">{{ t('learning', 'Settings saved') }}</NcNoteCard>

      <div class="actions">
        <NcButton type="primary" :disabled="saving" @click="save">
          {{ saving ? t('learning', 'Saving...') : t('learning', 'Save') }}
        </NcButton>
      </div>

      <hr class="section-divider" />

      <h3>{{ t('learning', 'Calendar Sync') }}</h3>
      <p class="section-desc">{{ t('learning', 'Subscribe to your due cards in any calendar app (Google Calendar, Thunderbird, iOS, etc.).') }}</p>

      <div v-if="calTokenLoading" class="loading">
        <NcLoadingIcon :size="20" />
        <span>{{ t('learning', 'Loading...') }}</span>
      </div>

      <template v-else>
        <div class="ics-url-row">
          <input
            ref="icsUrlInput"
            class="nc-input ics-url-input"
            type="text"
            readonly
            :value="icsUrl"
            @click="copyIcsUrl" />
          <NcButton type="secondary" :disabled="copyingUrl" @click="copyIcsUrl">
            {{ copyingUrl ? t('learning', 'Copied!') : t('learning', 'Copy URL') }}
          </NcButton>
        </div>
        <NcNoteCard v-if="icsUrlCopied" type="success">{{ t('learning', 'URL copied to clipboard') }}</NcNoteCard>
        <div class="regenerate-row">
          <NcButton type="tertiary" :disabled="regenerating" @click="regenerateToken">
            {{ regenerating ? t('learning', 'Regenerating...') : t('learning', 'Regenerate token') }}
          </NcButton>
          <span class="regenerate-hint">{{ t('learning', 'This will invalidate the current URL.') }}</span>
        </div>
      </template>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

export default {
  name: 'PersonalSettings',
  components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard },
  data() {
    return {
      loading: true,
      saving: false,
      error: '',
      saved: false,
      form: {
        dailyChallengeEnabled: true,
        uiLanguage: '',
        contentLanguage: '',
        virtuProfEnabled: true,
        notificationsEnabled: true,
      },
      // Calendar token
      calTokenLoading: true,
      icsUrl: '',
      copyingUrl: false,
      icsUrlCopied: false,
      regenerating: false,
    }
  },
  mounted() {
    this.load()
    this.loadCalendarToken()
  },
  methods: {
    async loadCalendarToken() {
      this.calTokenLoading = true
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/v1/user/calendar-token'))
        this.icsUrl = response.data.url || ''
      } catch (e) {
        // non-critical, ignore
      } finally {
        this.calTokenLoading = false
      }
    },
    async copyIcsUrl() {
      if (!this.icsUrl) return
      try {
        await navigator.clipboard.writeText(this.icsUrl)
        this.copyingUrl = true
        this.icsUrlCopied = true
        setTimeout(() => { this.copyingUrl = false; this.icsUrlCopied = false }, 2000)
      } catch (e) {
        // Fallback: select the input
        this.$refs.icsUrlInput?.select()
      }
    },
    async regenerateToken() {
      this.regenerating = true
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/v1/user/calendar-token/regenerate'))
        this.icsUrl = response.data.url || ''
        this.icsUrlCopied = false
      } catch (e) {
        // non-critical
      } finally {
        this.regenerating = false
      }
    },
    async load() {
      this.loading = true
      this.error = ''
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/settings/personal'))
        const data = response.data || {}
        this.form.dailyChallengeEnabled = (data.daily_challenge || 'yes') === 'yes'
        this.form.uiLanguage = ['de', 'en', ''].includes(data.ui_language) ? data.ui_language : ''
        this.form.contentLanguage = ['de', 'en', 'ru', ''].includes(data.content_language) ? data.content_language : ''
        this.form.virtuProfEnabled = (data.virtuprof_enabled || 'yes') !== 'no'
        this.form.notificationsEnabled = (data.notifications_enabled || 'yes') === 'yes'
      } catch (e) {
        this.error = t('learning', 'Failed to load settings')
      } finally {
        this.loading = false
      }
    },
    async save() {
      this.saving = true
      this.error = ''
      this.saved = false
      try {
        await axios.put(generateUrl('/apps/learning/api/settings/personal'), {
          daily_challenge: this.form.dailyChallengeEnabled ? 'yes' : 'no',
          ui_language: ['de', 'en'].includes(this.form.uiLanguage) ? this.form.uiLanguage : '',
          content_language: ['de', 'en', 'ru'].includes(this.form.contentLanguage) ? this.form.contentLanguage : '',
          virtuprof_enabled: this.form.virtuProfEnabled ? 'yes' : 'no',
          notifications_enabled: this.form.notificationsEnabled ? 'yes' : 'no',
        })
        this.saved = true
        this.$emit('content-language-changed', this.form.contentLanguage)
        this.$emit('virtuprof-enabled-changed', this.form.virtuProfEnabled)
      } catch (e) {
        this.error = t('learning', 'Failed to save settings')
      } finally {
        this.saving = false
      }
    },
  },
}
</script>

<style scoped>
.learning-settings-wrap {
  max-width: 760px;
  margin: 0;
  padding: 8px 0;
}

.loading {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--color-text-maxcontrast);
}

.settings-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.field-row {
  display: grid;
  gap: 8px;
}

.field-row label {
  font-weight: 600;
}

.nc-input {
  max-width: 280px;
}

.actions {
  padding-top: 4px;
}

.section-divider {
  border: none;
  border-top: 1px solid var(--color-border);
  margin: 20px 0 16px;
}

.section-desc {
  color: var(--color-text-maxcontrast);
  margin: 0 0 12px;
  font-size: 0.9em;
}

.ics-url-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.ics-url-input {
  flex: 1;
  min-width: 0;
  font-size: 0.85em;
  cursor: pointer;
}

.regenerate-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 8px;
}

.regenerate-hint {
  font-size: 0.85em;
  color: var(--color-text-maxcontrast);
}
</style>
