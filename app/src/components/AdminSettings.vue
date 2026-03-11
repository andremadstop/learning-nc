<template>
  <div class="learning-settings-wrap">
    <h2>{{ t('learning', 'Admin Settings') }}</h2>

    <NcNoteCard type="info" class="settings-intro">
      {{ t('learning', 'These settings apply to all users of Learning.') }}
    </NcNoteCard>

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
        <label for="default-language">{{ t('learning', 'Default language') }}</label>
        <select id="default-language" v-model="form.defaultLanguage" class="nc-input">
          <option value="de">{{ t('learning', 'German (Deutsch)') }}</option>
          <option value="en">{{ t('learning', 'English') }}</option>
        </select>
      </div>

      <div class="field-row">
        <label for="max-import">{{ t('learning', 'Max import file size (MB)') }}</label>
        <input
          id="max-import"
          v-model.number="form.maxImportSizeMb"
          class="nc-input"
          type="number"
          min="1"
          max="10" />
      </div>

      <div class="field-row">
        <label>{{ t('learning', 'Gamification enabled') }}</label>
        <NcCheckboxRadioSwitch
          :checked="form.gamificationEnabled"
          type="switch"
          @update:checked="form.gamificationEnabled = !!$event" />
      </div>

      <div class="field-row">
        <label>{{ t('learning', 'Allow course-based instructor fallback') }}</label>
        <NcCheckboxRadioSwitch
          :checked="form.allowCourseInstructorFallback"
          type="switch"
          @update:checked="form.allowCourseInstructorFallback = !!$event" />
        <small class="field-help">
          {{ t('learning', 'If disabled (recommended), only members of the configured instructor group get global instructor privileges.') }}
        </small>
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
  name: 'AdminSettings',
  components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard },
  data() {
    return {
      loading: true,
      saving: false,
      error: '',
      saved: false,
      form: {
        dailyChallengeEnabled: true,
        defaultLanguage: 'de',
        maxImportSizeMb: 2,
        gamificationEnabled: true,
        allowCourseInstructorFallback: false,
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
        const response = await axios.get(generateUrl('/apps/learning/api/settings/admin'))
        const data = response.data || {}
        this.form.dailyChallengeEnabled = (data.daily_challenge_enabled || 'yes') === 'yes'
        this.form.defaultLanguage = data.default_language === 'en' ? 'en' : 'de'
        this.form.maxImportSizeMb = Math.max(1, Math.min(10, Number(data.max_import_size_mb || 2)))
        this.form.gamificationEnabled = (data.gamification_enabled || 'yes') === 'yes'
        this.form.allowCourseInstructorFallback = (data.allow_course_instructor_fallback || 'no') === 'yes'
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
        await axios.put(generateUrl('/apps/learning/api/settings/admin'), {
          daily_challenge_enabled: this.form.dailyChallengeEnabled ? 'yes' : 'no',
          default_language: this.form.defaultLanguage === 'en' ? 'en' : 'de',
          max_import_size_mb: Math.max(1, Math.min(10, Number(this.form.maxImportSizeMb || 2))),
          gamification_enabled: this.form.gamificationEnabled ? 'yes' : 'no',
          allow_course_instructor_fallback: this.form.allowCourseInstructorFallback ? 'yes' : 'no',
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

.settings-intro {
  margin-bottom: 16px;
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

.field-help {
  color: var(--color-text-maxcontrast);
  font-size: 0.85em;
}

.nc-input {
  max-width: 280px;
}

.actions {
  padding-top: 4px;
}
</style>
