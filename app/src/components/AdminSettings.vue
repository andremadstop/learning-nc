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

      <div class="field-row">
        <label for="exam-attempt-limit">{{ t('learning', 'Exam attempts per 24h (per pool/user)') }}</label>
        <input
          id="exam-attempt-limit"
          v-model.number="form.examAttemptLimitPerDay"
          class="nc-input"
          type="number"
          min="1"
          max="50" />
      </div>

      <div class="field-row">
        <label for="exam-cooldown">{{ t('learning', 'Exam cooldown (minutes)') }}</label>
        <input
          id="exam-cooldown"
          v-model.number="form.examAttemptCooldownMinutes"
          class="nc-input"
          type="number"
          min="0"
          max="1440" />
      </div>

      <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
      <NcNoteCard v-if="saved" type="success">{{ t('learning', 'Settings saved') }}</NcNoteCard>

      <div class="actions">
        <NcButton type="primary" :disabled="saving" @click="save">
          {{ saving ? t('learning', 'Saving...') : t('learning', 'Save') }}
        </NcButton>
      </div>

      <div class="audit-section">
        <div class="audit-header">
          <h3>{{ t('learning', 'Recent Audit Events') }}</h3>
          <NcButton type="tertiary" @click="loadAudit">{{ t('learning', 'Refresh') }}</NcButton>
        </div>
        <div v-if="auditLoading" class="loading">
          <NcLoadingIcon :size="24" />
          <span>{{ t('learning', 'Loading...') }}</span>
        </div>
        <div v-else-if="auditEvents.length === 0" class="field-help">
          {{ t('learning', 'No audit events yet.') }}
        </div>
        <table v-else class="audit-table">
          <thead>
            <tr>
              <th>{{ t('learning', 'Time') }}</th>
              <th>{{ t('learning', 'Event') }}</th>
              <th>{{ t('learning', 'User') }}</th>
              <th>{{ t('learning', 'Session') }}</th>
              <th>{{ t('learning', 'Pool') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in auditEvents" :key="e.id">
              <td>{{ formatTime(e.created_at) }}</td>
              <td>{{ e.event_key }}</td>
              <td>{{ e.user_id || '-' }}</td>
              <td>{{ e.session_id || '-' }}</td>
              <td>{{ e.pool_id || '-' }}</td>
            </tr>
          </tbody>
        </table>
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
      auditLoading: false,
      auditEvents: [],
      form: {
        dailyChallengeEnabled: true,
        defaultLanguage: 'de',
        maxImportSizeMb: 2,
        gamificationEnabled: true,
        allowCourseInstructorFallback: false,
        examAttemptLimitPerDay: 5,
        examAttemptCooldownMinutes: 10,
      },
    }
  },
  mounted() {
    this.load()
    this.loadAudit()
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
        this.form.examAttemptLimitPerDay = Math.max(1, Math.min(50, Number(data.exam_attempt_limit_per_day || 5)))
        this.form.examAttemptCooldownMinutes = Math.max(0, Math.min(1440, Number(data.exam_attempt_cooldown_minutes || 10)))
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
          exam_attempt_limit_per_day: Math.max(1, Math.min(50, Number(this.form.examAttemptLimitPerDay || 5))),
          exam_attempt_cooldown_minutes: Math.max(0, Math.min(1440, Number(this.form.examAttemptCooldownMinutes || 10))),
        })
        this.saved = true
      } catch (e) {
        this.error = t('learning', 'Failed to save settings')
      } finally {
        this.saving = false
      }
    },
    async loadAudit() {
      this.auditLoading = true
      try {
        const res = await axios.get(generateUrl('/apps/learning/api/settings/admin/audit'), { params: { limit: 100, offset: 0 } })
        this.auditEvents = Array.isArray(res.data?.events) ? res.data.events : []
      } catch (e) {
        this.auditEvents = []
      } finally {
        this.auditLoading = false
      }
    },
    formatTime(ts) {
      if (!ts) return '-'
      try {
        return new Date(ts * 1000).toLocaleString()
      } catch {
        return String(ts)
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

.audit-section {
  margin-top: 20px;
}

.audit-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.audit-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9em;
}

.audit-table th,
.audit-table td {
  padding: 6px 8px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
}
</style>
