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
        notificationsEnabled: true,
      },
    }
  },
  mounted() {
    this.load()
  },
  methods: {
    async load() {
      this.loading = true
      this.error = ''
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/settings/personal'))
        const data = response.data || {}
        this.form.dailyChallengeEnabled = (data.daily_challenge || 'yes') === 'yes'
        this.form.uiLanguage = ['de', 'en', ''].includes(data.ui_language) ? data.ui_language : ''
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
          notifications_enabled: this.form.notificationsEnabled ? 'yes' : 'no',
        })
        this.saved = true
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
</style>
