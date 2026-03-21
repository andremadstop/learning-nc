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

      <!-- VirtuProf AI section -->
      <div class="ai-section">
        <h3>{{ t('learning', 'VirtuProf AI Assistant') }}</h3>

        <NcNoteCard type="info" class="dpa-hint">
          <span>
            {{ t('learning', 'When AI is enabled, user questions are sent to Google Gemini (an external AI service). Admins subject to GDPR/DSGVO must review and accept the') }}
            {{ ' ' }}
            <a
              href="https://cloud.google.com/terms/data-processing-addendum"
              target="_blank"
              rel="noopener noreferrer">{{ t('learning', 'Google Data Processing Addendum (DPA)') }}</a>{{ '.' }}
          </span>
        </NcNoteCard>

        <div class="field-row">
          <label>{{ t('learning', 'Enable AI chat (VirtuProf)') }}</label>
          <NcCheckboxRadioSwitch
            :checked="form.aiEnabled"
            type="switch"
            @update:checked="form.aiEnabled = !!$event" />
          <small class="field-help">
            {{ t('learning', 'When disabled, the AI chat input is hidden for all users. When enabled, each user must give consent before their first message is sent.') }}
          </small>
        </div>

        <div class="field-row">
          <label for="gemini-api-key">{{ t('learning', 'Gemini API Key') }}</label>
          <input
            id="gemini-api-key"
            v-model="form.geminiApiKey"
            class="nc-input"
            type="password"
            autocomplete="new-password"
            :placeholder="form.geminiApiKeySet ? t('learning', '(key saved — enter new value to replace)') : t('learning', 'Enter Gemini API Key')" />
          <small class="field-help">
            {{ t('learning', 'Get your API key from') }}
            <a
              href="https://aistudio.google.com/app/apikey"
              target="_blank"
              rel="noopener noreferrer">Google AI Studio</a>.
            {{ t('learning', 'Leave blank to keep the existing key.') }}
          </small>
        </div>
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

      <div class="ticket-section">
        <div class="audit-header">
          <h3>{{ t('learning', 'Support tickets') }}</h3>
          <NcButton type="tertiary" @click="loadSupportTickets">{{ t('learning', 'Refresh') }}</NcButton>
        </div>
        <p class="field-help ticket-filter-note">{{ t('learning', 'Zeigt nur technische Anfragen (Admin-Tickets)') }}</p>
        <div v-if="ticketLoading" class="loading">
          <NcLoadingIcon :size="24" />
          <span>{{ t('learning', 'Loading...') }}</span>
        </div>
        <NcNoteCard v-else-if="ticketError" type="error">{{ ticketError }}</NcNoteCard>
        <div v-else-if="supportTickets.length === 0" class="field-help">
          {{ t('learning', 'No support tickets yet.') }}
        </div>
        <div v-else class="ticket-list">
          <div v-for="ticket in supportTickets" :key="ticket.id" class="ticket-card">
            <div class="ticket-card-header">
              <div>
                <strong>{{ ticket.subject }}</strong>
                <div class="ticket-meta">
                  {{ ticket.user_id }} · {{ formatTime(ticket.updated_at || ticket.created_at) }}
                </div>
              </div>
              <span class="ticket-status" :class="'status-' + ticket.status">{{ ticket.status }}</span>
            </div>
            <div class="ticket-context">{{ formatTicketContext(ticket) }}</div>
            <p class="ticket-message">{{ ticket.message }}</p>
            <div v-if="ticket.answer_text" class="ticket-existing-answer">
              <strong>{{ t('learning', 'Current answer') }}:</strong> {{ ticket.answer_text }}
            </div>
            <label class="field-row">
              <span>{{ t('learning', 'Answer') }}</span>
              <textarea
                class="ticket-answer-input"
                rows="4"
                :value="ticketAnswers[ticket.id] || ''"
                :placeholder="t('learning', 'Write the admin reply here...')"
                @input="setTicketAnswer(ticket.id, $event.target.value)" />
            </label>
            <div class="ticket-actions">
              <NcButton
                type="primary"
                :disabled="answeringId === ticket.id"
                @click="submitTicketAnswer(ticket.id)">
                {{ answeringId === ticket.id ? t('learning', 'Saving...') : t('learning', 'Save answer') }}
              </NcButton>
            </div>
          </div>
        </div>
        <NcNoteCard v-if="ticketSuccess" type="success">{{ ticketSuccess }}</NcNoteCard>
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
      ticketLoading: false,
      ticketError: '',
      ticketSuccess: '',
      answeringId: null,
      supportTickets: [],
      ticketAnswers: {},
      form: {
        dailyChallengeEnabled: true,
        defaultLanguage: 'de',
        maxImportSizeMb: 2,
        gamificationEnabled: true,
        allowCourseInstructorFallback: false,
        examAttemptLimitPerDay: 5,
        examAttemptCooldownMinutes: 10,
        aiEnabled: false,
        geminiApiKey: '',
        geminiApiKeySet: false,
      },
    }
  },
  mounted() {
    this.load()
    this.loadAudit()
    this.loadSupportTickets()
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
        // PRIV-02 / PRIV-05: AI settings
        this.form.aiEnabled = (data.ai_enabled || 'no') === 'yes'
        this.form.geminiApiKeySet = data.gemini_api_key_set === true
        this.form.geminiApiKey = ''
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
          // PRIV-02 / PRIV-05: AI settings
          ai_enabled: this.form.aiEnabled ? 'yes' : 'no',
          ...(this.form.geminiApiKey.trim() !== '' ? { gemini_api_key: this.form.geminiApiKey.trim() } : {}),
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
    async loadSupportTickets() {
      this.ticketLoading = true
      this.ticketError = ''
      this.ticketSuccess = ''
      try {
        const res = await axios.get(generateUrl('/apps/learning/api/settings/admin/support-tickets'), { params: { limit: 100, target: 'admin' } })
        this.supportTickets = Array.isArray(res.data?.tickets) ? res.data.tickets : []
        const nextAnswers = {}
        this.supportTickets.forEach(ticket => {
          nextAnswers[ticket.id] = ticket.answer_text || ''
        })
        this.ticketAnswers = nextAnswers
      } catch (e) {
        this.supportTickets = []
        this.ticketError = t('learning', 'Failed to load support tickets')
      } finally {
        this.ticketLoading = false
      }
    },
    setTicketAnswer(ticketId, value) {
      this.$set(this.ticketAnswers, ticketId, value)
    },
    async submitTicketAnswer(ticketId) {
      this.answeringId = ticketId
      this.ticketError = ''
      this.ticketSuccess = ''
      try {
        await axios.post(generateUrl(`/apps/learning/api/settings/admin/support-tickets/${ticketId}/answer`), {
          answerText: this.ticketAnswers[ticketId] || '',
        })
        this.ticketSuccess = t('learning', 'Support ticket answer saved')
        await this.loadSupportTickets()
      } catch (e) {
        this.ticketError = e?.response?.data?.error || t('learning', 'Failed to save support ticket answer')
      } finally {
        this.answeringId = null
      }
    },
    formatTicketContext(ticket) {
      const parts = []
      const context = ticket.context || {}
      if (context.courseTitle) {
        parts.push(context.courseTitle)
      } else if (ticket.course_id) {
        parts.push(`${t('learning', 'Course')} #${ticket.course_id}`)
      }
      if (context.poolName) {
        parts.push(context.poolName)
      } else if (ticket.pool_id) {
        parts.push(`${t('learning', 'Pool')} #${ticket.pool_id}`)
      }
      if (context.area) {
        parts.push(context.area)
      }
      if (ticket.question_id) {
        parts.push(`${t('learning', 'Question')} #${ticket.question_id}`)
      }
      if (ticket.duel_code) {
        parts.push(`${t('learning', 'Duel')} ${ticket.duel_code}`)
      }
      return parts.join(' · ') || t('learning', 'No context available')
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

.ai-section {
  margin-top: 8px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}

.ai-section h3 {
  margin: 0 0 12px;
  font-size: 1em;
  font-weight: 700;
}

.dpa-hint {
  margin-bottom: 14px;
}

.dpa-hint a {
  color: var(--color-primary-element);
  text-decoration: underline;
}

.audit-section {
  margin-top: 20px;
}

.ticket-section {
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

.ticket-list {
  display: grid;
  gap: 12px;
}

.ticket-card {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 14px;
  background: var(--color-main-background);
  display: grid;
  gap: 10px;
}

.ticket-card-header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.ticket-meta,
.ticket-context {
  color: var(--color-text-maxcontrast);
  font-size: 0.85em;
}

.ticket-status {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-maxcontrast);
}

.ticket-status.status-answered {
  color: var(--color-success);
}

.ticket-message,
.ticket-existing-answer {
  margin: 0;
  line-height: 1.5;
}

.ticket-answer-input {
  width: 100%;
  box-sizing: border-box;
  min-height: 110px;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 10px 12px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  resize: vertical;
  font: inherit;
}

.ticket-actions {
  display: flex;
  justify-content: flex-end;
}
</style>
