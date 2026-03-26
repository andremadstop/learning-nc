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
          <option value="ar">{{ t('learning', 'Arabic') }}</option>
        </select>
      </div>

      <div class="field-row">
        <label>{{ t('learning', 'Virtual assistant') }}</label>
        <NcCheckboxRadioSwitch
          :checked="form.virtuProfEnabled"
          type="switch"
          @update:checked="form.virtuProfEnabled = !!$event" />
      </div>

      <hr class="section-divider" />

      <h3>{{ t('learning', 'VirtuProf Settings') }}</h3>
      <p class="section-desc">{{ t('learning', 'Control whether VirtuProf reads answers aloud and whether push-to-talk is available in the chat bubble.') }}</p>

      <div class="field-row">
        <label>{{ t('learning', 'Speech output') }}</label>
        <NcCheckboxRadioSwitch
          :checked="form.virtuProfTtsEnabled"
          type="switch"
          @update:checked="form.virtuProfTtsEnabled = !!$event" />
      </div>

      <div class="field-row">
        <label>{{ t('learning', 'Voice input (push-to-talk)') }}</label>
        <NcCheckboxRadioSwitch
          :checked="form.virtuProfSttEnabled"
          type="switch"
          @update:checked="form.virtuProfSttEnabled = !!$event" />
      </div>

      <div class="field-row">
        <label for="virtuprof-voice-lang">{{ t('learning', 'Voice language') }}</label>
        <select id="virtuprof-voice-lang" v-model="form.virtuProfVoiceLang" class="nc-input">
          <option
            v-for="option in voiceLanguageOptions"
            :key="option.value"
            :value="option.value">
            {{ option.label }}
          </option>
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

      <hr class="section-divider" />

      <h3>{{ t('learning', 'Learning Profile') }}</h3>
      <p class="section-desc">{{ t('learning', 'This profile helps VirtuProf adapt explanations and lets instructors see only aggregated class-level patterns.') }}</p>

      <NcNoteCard type="info" class="privacy-info-card">
        {{ t('learning', 'Your profile is stored only on this DevCloud. Instructors see aggregated class statistics, not individual profiles. When you chat with VirtuProf, profile context is sent to Google Gemini during the chat only. You can delete your profile at any time.') }}
      </NcNoteCard>

      <div v-if="telosLoading" class="loading">
        <NcLoadingIcon :size="20" />
        <span>{{ t('learning', 'Loading...') }}</span>
      </div>

      <template v-else>
        <div class="profile-stats-grid">
          <div class="profile-stat-card">
            <span class="profile-stat-label">{{ t('learning', 'Level') }}</span>
            <strong class="profile-stat-value">{{ userState.xp?.level || 1 }}</strong>
          </div>
          <div class="profile-stat-card">
            <span class="profile-stat-label">{{ t('learning', 'XP') }}</span>
            <strong class="profile-stat-value">{{ userState.xp?.total_xp || 0 }}</strong>
          </div>
          <div class="profile-stat-card">
            <span class="profile-stat-label">{{ t('learning', 'Streak') }}</span>
            <strong class="profile-stat-value">{{ userState.streak?.current_streak || 0 }}</strong>
          </div>
          <div class="profile-stat-card">
            <span class="profile-stat-label">{{ t('learning', 'Badges') }}</span>
            <strong class="profile-stat-value">{{ (userState.badges || []).length }}</strong>
          </div>
          <div class="profile-stat-card">
            <span class="profile-stat-label">{{ t('learning', 'Mastered') }}</span>
            <strong class="profile-stat-value">{{ profileSummary.total_mastered || 0 }}</strong>
          </div>
          <div class="profile-stat-card">
            <span class="profile-stat-label">{{ t('learning', 'Accuracy') }}</span>
            <strong class="profile-stat-value">{{ profileSummary.overall_accuracy || 0 }}%</strong>
          </div>
        </div>

        <div class="profile-form-grid">
          <div class="field-row">
            <label for="telos-role">{{ t('learning', 'Current role / background') }}</label>
            <input id="telos-role" v-model="telosForm.telos.role" class="nc-input nc-input--wide" type="text">
          </div>

          <div class="field-row">
            <label for="telos-experience">{{ t('learning', 'Experience level') }}</label>
            <select id="telos-experience" v-model="telosForm.telos.experience_level" class="nc-input nc-input--wide">
              <option value="beginner">{{ t('learning', 'Beginner') }}</option>
              <option value="intermediate">{{ t('learning', 'Intermediate') }}</option>
              <option value="advanced">{{ t('learning', 'Advanced') }}</option>
            </select>
          </div>

          <div class="field-row">
            <label for="telos-target-cert">{{ t('learning', 'Target certification') }}</label>
            <input id="telos-target-cert" v-model="telosForm.telos.target_cert" class="nc-input nc-input--wide" type="text">
          </div>

          <div class="field-row">
            <label for="telos-target-date">{{ t('learning', 'Target date') }}</label>
            <input id="telos-target-date" v-model="telosForm.telos.target_date" class="nc-input nc-input--wide" type="date">
          </div>

          <div class="field-row">
            <label for="telos-hours">{{ t('learning', 'Hours per week') }}</label>
            <input id="telos-hours" v-model="telosForm.telos.hours_per_week" class="nc-input nc-input--wide" type="number" min="0" step="0.5">
          </div>

          <div class="field-row">
            <label for="telos-style">{{ t('learning', 'Learning style') }}</label>
            <select id="telos-style" v-model="telosForm.telos.learning_style" class="nc-input nc-input--wide">
              <option value="solo">{{ t('learning', 'Solo') }}</option>
              <option value="group">{{ t('learning', 'Group') }}</option>
              <option value="mixed">{{ t('learning', 'Mixed') }}</option>
            </select>
          </div>

          <div class="field-row field-row--full">
            <label for="telos-strengths">{{ t('learning', 'Strengths') }}</label>
            <textarea id="telos-strengths" v-model="telosForm.telos.strengths" class="nc-textarea nc-textarea--profile" rows="2" :placeholder="t('learning', 'Comma-separated topics you already know well')" />
          </div>

          <div class="field-row field-row--full">
            <label for="telos-weaknesses">{{ t('learning', 'Weaknesses') }}</label>
            <textarea id="telos-weaknesses" v-model="telosForm.telos.weaknesses" class="nc-textarea nc-textarea--profile" rows="2" :placeholder="t('learning', 'Comma-separated topics you still struggle with')" />
          </div>

          <div class="field-row field-row--full">
            <label for="telos-motivation">{{ t('learning', 'Motivation') }}</label>
            <textarea id="telos-motivation" v-model="telosForm.telos.motivation" class="nc-textarea nc-textarea--profile" rows="2" />
          </div>

          <div class="field-row field-row--full">
            <label for="telos-notes">{{ t('learning', 'Notes') }}</label>
            <textarea id="telos-notes" v-model="telosForm.telos.notes" class="nc-textarea nc-textarea--profile" rows="2" />
          </div>

          <div class="field-row field-row--full">
            <label for="telos-bio">{{ t('learning', 'Short bio') }}</label>
            <textarea id="telos-bio" v-model="telosForm.bio" class="nc-textarea nc-textarea--profile" rows="2" />
          </div>

          <div class="field-row field-row--full">
            <label for="telos-help-offer">{{ t('learning', 'I can help with...') }}</label>
            <textarea id="telos-help-offer" v-model="telosForm.help_offer" class="nc-textarea nc-textarea--profile" rows="2" :placeholder="t('learning', 'Comma-separated topics')" />
          </div>

          <div class="field-row field-row--full">
            <label for="telos-help-wanted">{{ t('learning', 'I need help with...') }}</label>
            <textarea id="telos-help-wanted" v-model="telosForm.help_wanted" class="nc-textarea nc-textarea--profile" rows="2" :placeholder="t('learning', 'Comma-separated topics')" />
          </div>

          <div class="field-row">
            <label for="telos-visibility">{{ t('learning', 'Visibility') }}</label>
            <select id="telos-visibility" v-model="telosForm.visibility" class="nc-input nc-input--wide">
              <option value="private">{{ t('learning', 'Private') }}</option>
              <option value="course">{{ t('learning', 'Course') }}</option>
              <option value="public">{{ t('learning', 'Public') }}</option>
            </select>
            <small class="field-hint">{{ visibilityHint }}</small>
          </div>
        </div>

        <div v-if="weakestTopics.length" class="weak-topics">
          <strong>{{ t('learning', 'Current weakest topics') }}</strong>
          <ul class="weak-topics-list">
            <li v-for="topic in weakestTopics.slice(0, 5)" :key="topic.pool_id">
              {{ topic.pool_name }} · {{ topic.error_rate }}%
            </li>
          </ul>
        </div>

        <NcNoteCard v-if="telosError" type="error">{{ telosError }}</NcNoteCard>
        <NcNoteCard v-if="telosSaved" type="success">{{ t('learning', 'Learning profile saved') }}</NcNoteCard>

        <div class="actions">
          <NcButton type="primary" :disabled="telosSaving" @click="saveTelosProfile">
            {{ telosSaving ? t('learning', 'Saving...') : t('learning', 'Save learning profile') }}
          </NcButton>
          <NcButton type="tertiary-on-primary" :disabled="telosSaving" @click="confirmResetProfile">
            {{ t('learning', 'Reset profile') }}
          </NcButton>
        </div>
      </template>

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
import {
  applyTelosToForm,
  buildTelosPayload,
  createTelosForm,
} from '../utils/telosProfile.js'

const VOICE_LANGUAGE_OPTIONS = [
  { value: 'de-DE', label: 'Deutsch' },
  { value: 'en-US', label: 'English' },
  { value: 'ru-RU', label: 'Russkii' },
  { value: 'ar-SA', label: 'al arabiyya' },
  { value: 'tr-TR', label: 'Turkce' },
  { value: 'fr-FR', label: 'Francais' },
  { value: 'es-ES', label: 'Espanol' },
  { value: 'zh-CN', label: 'Zhongwen' },
  { value: 'ja-JP', label: 'Nihongo' },
  { value: 'ko-KR', label: 'Hanguk-eo' },
  { value: 'pt-BR', label: 'Portugues (Brasil)' },
  { value: 'it-IT', label: 'Italiano' },
  { value: 'pl-PL', label: 'Polski' },
  { value: 'nl-NL', label: 'Nederlands' },
  { value: 'uk-UA', label: 'Ukrainska' },
]

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
        virtuProfTtsEnabled: false,
        virtuProfSttEnabled: false,
        virtuProfVoiceLang: 'de-DE',
        notificationsEnabled: true,
      },
      // Calendar token
      calTokenLoading: true,
      icsUrl: '',
      copyingUrl: false,
      icsUrlCopied: false,
      regenerating: false,
      telosLoading: true,
      telosSaving: false,
      telosError: '',
      telosSaved: false,
      telosForm: createTelosForm(),
      weakestTopics: [],
      profileSummary: {
        total_questions: 0,
        total_mastered: 0,
        overall_accuracy: 0,
      },
      userState: {
        xp: { total_xp: 0, level: 1 },
        streak: { current_streak: 0 },
        badges: [],
      },
    }
  },
  computed: {
    voiceLanguageOptions() {
      return VOICE_LANGUAGE_OPTIONS
    },
    visibilityHint() {
      const hints = {
        private: t('learning', 'Only VirtuProf uses your profile. Nobody else can see it.'),
        course: t('learning', 'Course participants can see your help topics and bio.'),
        public: t('learning', 'All DevCloud users can see your help topics and bio.'),
      }
      return hints[this.telosForm.visibility] || hints.private
    },
  },
  mounted() {
    this.load()
    this.loadCalendarToken()
    this.loadLearningProfile()
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
        const [settingsResponse, virtuProfResponse] = await Promise.all([
          axios.get(generateUrl('/apps/learning/api/settings/personal')),
          axios.get(generateUrl('/apps/learning/api/virtuprof/state')),
        ])
        const data = settingsResponse.data || {}
        const virtuProfData = virtuProfResponse.data || {}
        this.form.dailyChallengeEnabled = (data.daily_challenge || 'yes') === 'yes'
        this.form.uiLanguage = ['de', 'en', ''].includes(data.ui_language) ? data.ui_language : ''
        this.form.contentLanguage = ['de', 'en', 'ru', 'ar', ''].includes(data.content_language) ? data.content_language : ''
        this.form.virtuProfEnabled = (data.virtuprof_enabled || 'yes') !== 'no'
        this.form.virtuProfTtsEnabled = virtuProfData.tts_enabled === true
        this.form.virtuProfSttEnabled = virtuProfData.stt_enabled === true
        this.form.virtuProfVoiceLang = VOICE_LANGUAGE_OPTIONS.some(option => option.value === virtuProfData.voice_lang)
          ? virtuProfData.voice_lang
          : 'de-DE'
        this.form.notificationsEnabled = (data.notifications_enabled || 'yes') === 'yes'
      } catch (e) {
        this.error = t('learning', 'Failed to load settings')
      } finally {
        this.loading = false
      }
    },
    async loadLearningProfile() {
      this.telosLoading = true
      this.telosError = ''
      try {
        const [telosResponse, profileResponse, stateResponse] = await Promise.all([
          axios.get(generateUrl('/apps/learning/api/profile/telos')),
          axios.get(generateUrl('/apps/learning/api/profile')),
          axios.get(generateUrl('/apps/learning/api/v1/user/state')),
        ])
        this.telosForm = applyTelosToForm(telosResponse.data)
        this.weakestTopics = Array.isArray(profileResponse.data?.weakest_topics) ? profileResponse.data.weakest_topics : []
        this.profileSummary = profileResponse.data?.summary || this.profileSummary
        this.userState = stateResponse.data || this.userState
      } catch (e) {
        this.telosError = t('learning', 'Failed to load learning profile')
      } finally {
        this.telosLoading = false
      }
    },
    confirmResetProfile() {
      if (!window.confirm(t('learning', 'This will clear your learning profile. VirtuProf will no longer give personalized tips. Continue?'))) {
        return
      }
      this.resetProfile()
    },
    async resetProfile() {
      this.telosSaving = true
      this.telosError = ''
      this.telosSaved = false
      try {
        await axios.post(generateUrl('/apps/learning/api/profile/telos'), buildTelosPayload(createTelosForm()))
        this.telosForm = createTelosForm()
        this.telosSaved = true
        await this.loadLearningProfile()
      } catch (e) {
        this.telosError = e?.response?.data?.error || t('learning', 'Failed to reset profile')
      } finally {
        this.telosSaving = false
      }
    },
    async saveTelosProfile() {
      this.telosSaving = true
      this.telosError = ''
      this.telosSaved = false
      try {
        await axios.post(generateUrl('/apps/learning/api/profile/telos'), buildTelosPayload(this.telosForm))
        this.telosSaved = true
        await this.loadLearningProfile()
      } catch (e) {
        this.telosError = e?.response?.data?.error || t('learning', 'Failed to save learning profile')
      } finally {
        this.telosSaving = false
      }
    },
    async save() {
      this.saving = true
      this.error = ''
      this.saved = false
      try {
        await Promise.all([
          axios.put(generateUrl('/apps/learning/api/settings/personal'), {
            daily_challenge: this.form.dailyChallengeEnabled ? 'yes' : 'no',
            ui_language: ['de', 'en'].includes(this.form.uiLanguage) ? this.form.uiLanguage : '',
            content_language: ['de', 'en', 'ru', 'ar'].includes(this.form.contentLanguage) ? this.form.contentLanguage : '',
            virtuprof_enabled: this.form.virtuProfEnabled ? 'yes' : 'no',
            notifications_enabled: this.form.notificationsEnabled ? 'yes' : 'no',
          }),
          axios.put(generateUrl('/apps/learning/api/virtuprof/preferences'), {
            ttsEnabled: this.form.virtuProfTtsEnabled,
            sttEnabled: this.form.virtuProfSttEnabled,
            voiceLang: VOICE_LANGUAGE_OPTIONS.some(option => option.value === this.form.virtuProfVoiceLang)
              ? this.form.virtuProfVoiceLang
              : 'de-DE',
          }),
        ])
        this.saved = true
        this.$emit('content-language-changed', this.form.contentLanguage)
        this.$emit('virtuprof-enabled-changed', this.form.virtuProfEnabled)
        this.$root.$emit('virtuprof:voice-settings-changed', {
          ttsEnabled: this.form.virtuProfTtsEnabled,
          sttEnabled: this.form.virtuProfSttEnabled,
          voiceLang: this.form.virtuProfVoiceLang,
        })
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

.nc-input--wide {
  max-width: none;
  width: 100%;
}

.nc-textarea--profile {
  min-height: 72px;
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

.profile-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
}

.profile-stat-card {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 14px;
  background: var(--color-main-background);
}

.profile-stat-label {
  display: block;
  color: var(--color-text-maxcontrast);
  font-size: 0.85em;
  margin-bottom: 6px;
}

.profile-stat-value {
  font-size: 1.5em;
  line-height: 1.1;
}

.profile-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.field-row--full {
  grid-column: 1 / -1;
}

.weak-topics {
  display: grid;
  gap: 8px;
}

.weak-topics-list {
  margin: 0;
  padding-inline-start: 18px;
  color: var(--color-text-maxcontrast);
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

@media (max-width: 720px) {
  .profile-form-grid {
    grid-template-columns: 1fr;
  }

  .field-row--full {
    grid-column: auto;
  }
}

.field-hint {
  display: block;
  margin-top: 4px;
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  line-height: 1.4;
}

.privacy-info-card {
  margin-bottom: 16px;
}
</style>
