<template>
  <div>
    <OnboardingIntro v-if="showOnboardingIntro" :role="userRole" @done="onIntroFinished" />
    <VirtuProfFullscreen
      v-if="enabled && fullscreenActive"
      :title="panelTitle"
      :subtitle="panelMetaText"
      :language="language"
      :step="currentBubbleStep"
      :chat-messages="chatMessages"
      :chat-loading="chatLoading"
      :ai-enabled="aiEnabled"
      :show-consent-dialog="showAiConsentDialog"
      :consent-data="consentData"
      :exam-blocked="isExamMode"
      :has-question-context="hasActiveQuestionContext"
      :current-context="currentContext"
      @close="handleFullscreenClose"
      @send="handleChatSend"
      @report-error="handleReportError"
      @consent-accept="handleConsentAccept"
      @consent-decline="handleConsentDecline" />
    <transition name="virtuprof-enter">
    <section
      v-if="enabled && !fullscreenActive"
      class="virtuprof-container"
      :class="{ minimized: isMinimized, 'is-open': showBubble }">
      <SkinRenderer
        v-if="!showBubble"
        :animation="currentAnimation"
        :emotion="currentEmotion"
        :has-message="visible && !isMinimized"
        :invite-count="duelInvites.incoming.length"
        :status-text="dockStatusText"
        :expanded="showBubble"
        @click="handleAvatarClick" />

      <NovaPanel
        v-else
        ref="virtuprofPanel"
        :title="panelTitle"
        :meta-text="panelMetaText"
        @minimize="isMinimized = true"
        @close="dismiss"
        @touchstart="panelTouchStart"
        @touchend="panelTouchEnd">
        <VirtuProfBubble
          :step="currentBubbleStep"
          :step-index="currentBubbleStepIndex"
          :total-steps="currentBubbleTotalSteps"
          :ticket-subject="ticketSubject"
          :ticket-draft="ticketDraft"
          :ticket-sending="ticketSending"
          :ticket-error="ticketError"
          :ticket-success="ticketSuccess"
          :tickets="myTickets"
          :language="language"
          :chat-messages="chatMessages"
          :chat-loading="chatLoading"
          :ai-enabled="aiEnabled"
          :show-consent-dialog="showAiConsentDialog"
          :consent-data="consentData"
          :exam-blocked="isExamMode"
          :has-question-context="hasActiveQuestionContext"
          :telos-form="telosForm"
          :telos-saving="telosSaving"
          :telos-error="telosError"
          :telos-saved="telosSaved"
          :tts-enabled="ttsEnabled"
          :stt-enabled="sttEnabled"
          :voice-lang="voiceLang"
          @next="nextStep"
          @dismiss="dismiss"
          @open-fullscreen="requestFullscreen"
          @action="handleAction"
          @update:ticketSubject="ticketSubject = $event"
          @update:ticketDraft="ticketDraft = $event"
          @chat-send="handleChatSend"
          @report-error="handleReportError"
          @consent-accept="handleConsentAccept"
          @consent-decline="handleConsentDecline" />
      </NovaPanel>
    </section>
  </transition>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import SkinRenderer from './SkinRenderer.vue'
import NovaPanel from './nova/NovaPanel.vue'
import VirtuProfBubble from './VirtuProfBubble.vue'
import VirtuProfFullscreen from './VirtuProfFullscreen.vue'
import { novaReactions } from '../utils/nova-reaction-engine.js'
import OnboardingIntro from './OnboardingIntro.vue'
import { FAQ_CATEGORIES, FAQS, SCRIPTS } from '../utils/virtuprof-scripts.js'
import {
  detectVirtuProfLanguage,
  translateVirtuProf,
} from '../utils/virtuprof-i18n.js'
import consentData from '../../data/ai-consent.json'
import {
  applyTelosToForm,
  buildTelosPayload,
  createTelosForm,
} from '../utils/telosProfile.js'
import { useOptionalVirtuProfStore } from '../stores/virtuProfStore.js'

const ONBOARDING_PRESETS = [
  {
    id: 'career_changer_comptia',
    label: 'Career changer doing CompTIA',
    icon: '🔄',
    telos: {
      role: 'Quereinsteiger',
      experience_level: 'beginner',
      target_cert: 'CompTIA Network+',
      learning_style: 'solo',
      hours_per_week: 10,
    },
    visibility: 'course',
  },
  {
    id: 'it_admin',
    label: 'IT Admin expanding skills',
    icon: '🖥️',
    telos: {
      role: 'IT-Administrator',
      experience_level: 'intermediate',
      target_cert: 'CompTIA Security+',
      learning_style: 'mixed',
      hours_per_week: 5,
    },
    visibility: 'course',
  },
  {
    id: 'student_general',
    label: 'Student — general learning',
    icon: '📚',
    telos: {
      role: 'Student',
      experience_level: 'beginner',
      target_cert: '',
      learning_style: 'group',
      hours_per_week: 8,
    },
    visibility: 'course',
  },
]

const JOURNEY_STEPS = [
  {
    id: 'background',
    title: 'Was ist dein Hintergrund?',
    explanation: 'Damit passe ich Erklärungen an dein Level an. Ein Quereinsteiger braucht andere Worte als ein Admin.',
    privacyHint: 'Your data is stored only on this DevCloud. Instructors see aggregated class statistics, not your individual profile.',
    fields: ['telos.role', 'telos.experience_level'],
  },
  {
    id: 'goal',
    title: 'Worauf arbeitest du hin?',
    explanation: 'Ich kann dich gezielt auf deine Prüfung vorbereiten und deinen Lernplan danach ausrichten.',
    privacyHint: 'Your certification goal helps VirtuProf tailor recommendations. You can change or delete it any time in Settings.',
    fields: ['telos.target_cert', 'telos.target_date'],
  },
  {
    id: 'strengths',
    title: 'Wo stehst du?',
    explanation: 'So weiß ich wo ich mehr erklären muss und wo ich direkt zum Punkt kommen kann.',
    privacyHint: 'Strengths and weaknesses are only used locally by VirtuProf. They are not shared with other users or instructors.',
    fields: ['telos.strengths', 'telos.weaknesses'],
  },
  {
    id: 'style',
    title: 'Wie lernst du am liebsten?',
    explanation: 'Das beeinflusst wie ich dir Inhalte vorschlage — allein durcharbeiten, mit anderen üben, oder gemischt.',
    privacyHint: 'When you chat with VirtuProf, your profile context is sent to Google Gemini — only during the chat, not stored permanently.',
    fields: ['telos.learning_style', 'telos.hours_per_week'],
  },
  {
    id: 'peers',
    title: 'Du lernst hier nicht allein',
    explanation: 'Andere Kursteilnehmer haben ähnliche Ziele. Wenn du magst, können sie sehen wobei du helfen kannst — und du siehst wer dir helfen kann.',
    privacyHint: 'With Course or Public visibility, other participants can see your help topics. With Private, nobody sees your profile. You can change this any time in Settings.',
    fields: ['help_offer', 'help_wanted', 'visibility'],
  },
  {
    id: 'extra',
    title: 'Noch was?',
    explanation: 'Alles was mir hilft dich besser zu unterstützen. Du kannst diesen Schritt auch überspringen.',
    privacyHint: 'These fields are completely optional and only visible to VirtuProf.',
    fields: ['telos.motivation', 'telos.notes'],
    optional: true,
  },
]

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
  name: 'VirtuProf',
  components: { SkinRenderer, NovaPanel, VirtuProfBubble, VirtuProfFullscreen, OnboardingIntro },
  props: {
    enabled: {
      type: Boolean,
      default: true,
    },
    userRole: {
      type: String,
      default: 'student',
    },
    fullscreenActive: {
      type: Boolean,
      default: false,
    },
  },
  data() {
    return {
      visible: false,
      isMinimized: false,
      panelTouchY: 0,
      currentScriptId: null,
      currentScript: null,
      currentScriptMeta: null,
      currentScriptContext: {},
      stepIndex: 0,
      currentAnimation: 'idle',
      currentEmotion: null,
      language: detectVirtuProfLanguage(),
      dismissedTriggers: [],
      queue: [],
      processing: false,
      pendingTimer: null,
      helpView: null,
      guideStep: null,
      activeFaqId: null,
      activeFaqCategoryId: null,
      ticketSubject: '',
      ticketDraft: '',
      ticketCategory: 'technical',
      ticketSending: false,
      ticketError: '',
      ticketSuccess: '',
      myTickets: [],
      duelInvites: {
        incoming: [],
        outgoing: [],
      },
      visitedGuideKeys: [],
      repeatGuideKeys: [],
      inviteError: '',
      inviteNotificationsInitialized: false,
      notifiedInviteIds: [],
      invitePollingInterval: null,
      activeInviteFilter: 'incoming',
      currentContext: {
        area: 'courses',
        courseTitle: '',
        poolName: '',
      },
      // Chat state
      chatMessages: [],
      chatLoading: false,
      chatAnimationTimer: null,
      // AI consent — versioned (Phase 102)
      aiConsentVersion: null,
      consentData,
      showAiConsentDialog: false,
      pendingChatMessage: null,
      // AI global enabled flag (PRIV-02)
      aiEnabled: false,
      telosOnboardingActive: false,
      telosForm: createTelosForm(),
      telosSaving: false,
      telosError: '',
      telosSaved: false,
      telosCompletionProfile: null,
      ttsEnabled: false,
      sttEnabled: false,
      voiceLang: '',
      onboardingReminderCount: 0,
      // Journey state (opt-in step-by-step onboarding)
      journeyStepIndex: 0,
      onboardingDeclined: false,
      telosProfileLoaded: false,
      showOnboardingIntro: false,
      selectedPresetId: null,
      // HINT: graduated hint tracking per question
      hintLevel: 0,
      lastHintQuestionId: null,
      // EXAM-01: VirtuProf chat lock during exam mode
      isExamMode: false,
      // GREET-01: User's first name for personalized greetings
      userFirstName: '',
    }
  },
  computed: {
    currentStep() {
      if (!this.currentScript || !Array.isArray(this.currentScript.steps)) {
        return null
      }
      return this.currentScript.steps[this.stepIndex] || null
    },
    isHelpOpen() {
      return this.helpView !== null
    },
    currentBubbleStep() {
      if (this.isHelpOpen) {
        return this.buildHelpStep()
      }
      return this.translateScriptStep(this.currentStep)
    },
    currentBubbleStepIndex() {
      return this.isHelpOpen ? 0 : this.stepIndex
    },
    currentBubbleTotalSteps() {
      return this.isHelpOpen ? 1 : (this.currentScript ? this.currentScript.steps.length : 1)
    },
    showBubble() {
      return Boolean(this.visible && this.currentBubbleStep && !this.isMinimized)
    },
    isBasicMode() {
      return this.telosProfileLoaded && !this.telosSaved && !this.telosOnboardingActive
    },
    journeyTotalSteps() {
      return JOURNEY_STEPS.length
    },
    dockStatusText() {
      if (this.currentBubbleStep?.title && (this.visible || this.isMinimized)) {
        return this.currentBubbleStep.title
      }
      if (this.duelInvites.incoming.length > 0) {
        return this.vt('{count} duel invite(s) waiting', { count: this.duelInvites.incoming.length })
      }
      if (this.isBasicMode) {
        return this.vt('Basic mode — set up learning profile for better help')
      }
      if (this.hasActiveQuestionContext) {
        return this.vt('Question context active')
      }
      if (this.currentContext?.poolName) {
        return this.currentContext.poolName
      }
      if (this.currentContext?.courseTitle) {
        return this.currentContext.courseTitle
      }
      if (this.telosSaved && this.telosForm.telos?.target_cert) {
        return this.vt('{cert} prep active', { cert: this.telosForm.telos.target_cert })
      }
      return this.vt('Open your learning assistant')
    },
    panelTitle() {
      if (this.currentBubbleStep?.title) {
        return this.currentBubbleStep.title
      }
      if (this.currentBubbleStep?.kind === 'telos-form') {
        return this.vt('Learning profile')
      }
      if (this.isHelpOpen) {
        return this.vt('Help & navigation')
      }
      return this.vt('VirtuProf')
    },
    panelMetaText() {
      if (this.hasActiveQuestionContext) {
        return this.vt('Question context active')
      }
      if (this.currentContext?.poolName) {
        return this.currentContext.poolName
      }
      if (this.currentContext?.courseTitle) {
        return this.currentContext.courseTitle
      }
      return this.vt('Help, hints and learning profile')
    },
    orderedFaqIds() {
      const ids = Object.keys(FAQS).filter(id => {
        if (!this.activeFaqCategoryId) {
          return true
        }
        return FAQS[id].category === this.activeFaqCategoryId
      })
      return ids.sort((left, right) => this.vt(FAQS[left].label).localeCompare(this.vt(FAQS[right].label)))
    },
    orderedFaqCategoryIds() {
      const ids = Object.keys(FAQ_CATEGORIES)
      const recommendedId = this.recommendedFaqCategoryId()
      if (!recommendedId || !FAQ_CATEGORIES[recommendedId]) {
        return ids
      }
      return [recommendedId, ...ids.filter(id => id !== recommendedId)]
    },
    hasCourseContext() {
      return !!(this.currentContext && this.currentContext.courseTitle)
    },
    hasActiveQuestionContext() {
      return !!(this.currentContext?.questionContext?.questionId)
    },
    categoryHint() {
      if (this.ticketCategory === 'course_content') {
        return this.vt('Your question will be sent to the course instructor.')
      }
      return this.vt('Your question will be sent to the admin.')
    },
  },
  watch: {
    enabled(value) {
      if (!value) {
        this.clearPresentation()
      }
    },
    showBubble(value) {
      if (value) {
        this.$nextTick(() => {
          if (typeof this.$refs.virtuprofPanel?.focus === 'function') {
            this.$refs.virtuprofPanel.focus({ preventScroll: true })
          } else if (this.$refs.virtuprofPanel?.$el?.focus) {
            this.$refs.virtuprofPanel.$el.focus({ preventScroll: true })
          }
        })
      }
    },
  },
  async mounted() {
    // Phase 102: Load AI consent version from backend
    try {
      const consentRes = await axios.get(generateUrl('/apps/learning/api/profile/telos/consent'))
      this.aiConsentVersion = consentRes.data.ai_consent_version || null
    } catch (e) {
      this.aiConsentVersion = null
    }
    // GREET-01: Extract first name from Nextcloud display name
    try {
      const user = getCurrentUser()
      if (user?.displayName) {
        this.userFirstName = String(user.displayName).split(/\s+/)[0] || ''
      }
    } catch (e) {
      this.userFirstName = ''
    }
    const virtuProfStore = useOptionalVirtuProfStore()
    if (virtuProfStore) {
      this._storeUnwatchers = [
        this.$watch(
          () => virtuProfStore.pendingTrigger,
          (pendingTrigger) => {
            if (!pendingTrigger) {
              return
            }
            this.enqueue(pendingTrigger.type, pendingTrigger.payload || {})
            virtuProfStore.consumeTrigger()
          },
          { immediate: true },
        ),
        this.$watch(
          () => virtuProfStore.context,
          (context) => {
            if (!context) {
              return
            }
            this.updateContext(context)
          },
          { immediate: true },
        ),
        this.$watch(
          () => virtuProfStore.examMode,
          (active) => {
            this.setExamMode(active)
          },
          { immediate: true },
        ),
        this.$watch(
          () => virtuProfStore.refreshDuelInvites,
          (value, oldValue) => {
            if (value === oldValue || value === 0) {
              return
            }
            this.handleInviteRefreshRequest()
          },
        ),
        this.$watch(
          () => virtuProfStore.explainQuestion,
          (payload) => {
            if (!payload) {
              return
            }
            this.handleExplainQuestion(payload)
            virtuProfStore.consumeExplain()
          },
          { immediate: true },
        ),
        this.$watch(
          () => virtuProfStore.guidePayload,
          (payload) => {
            if (!payload) {
              return
            }
            this.handleGuide(payload)
            virtuProfStore.consumeGuide()
          },
          { immediate: true },
        ),
        this.$watch(
          () => virtuProfStore.voiceSettingsVersion,
          (value, oldValue) => {
            if (value === oldValue || value === 0) {
              return
            }
            this.handleVoiceSettingsChanged(virtuProfStore.voiceSettingsPayload || {})
          },
        ),
      ]
    }
    await this.loadState()
    // MEM-01: Load persistent chat history so previous conversations are visible immediately
    if (this.aiEnabled) {
      await this.loadChatHistory()
    }
    await this.checkTelosOnboarding()
    await this.refreshDuelInvites(false)
    this.startInvitePolling()
    this.$emit('ready')
  },
  beforeUnmount() {
    if (Array.isArray(this._storeUnwatchers)) {
      this._storeUnwatchers.forEach((unwatch) => {
        if (typeof unwatch === 'function') {
          unwatch()
        }
      })
      this._storeUnwatchers = []
    }
    if (this.pendingTimer) {
      clearTimeout(this.pendingTimer)
      this.pendingTimer = null
    }
    if (this.chatAnimationTimer) {
      clearTimeout(this.chatAnimationTimer)
      this.chatAnimationTimer = null
    }
    this.stopInvitePolling()
  },
  methods: {
    async loadState() {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/virtuprof/state'))
        this.applyVirtuProfState(response.data || {})
      } catch (e) {
        this.dismissedTriggers = []
        this.language = detectVirtuProfLanguage()
        this.visitedGuideKeys = []
        this.aiEnabled = false
        this.ttsEnabled = false
        this.sttEnabled = false
        this.voiceLang = this.getBrowserVoiceLanguage()
        this.onboardingReminderCount = 0
      }
    },
    vt(key, params = {}) {
      return translateVirtuProf(this.language, key, params)
    },
    translateScriptStep(step) {
      if (!step) {
        return null
      }
      // GREET-01: If firstName was not resolved, strip leftover placeholder and fix punctuation
      const cleanUnresolved = (text) => text
        .replace(/,?\s*\{firstName\}/g, '')
        .replace(/\s{2,}/g, ' ')
        .trim()
      const ctx = this.currentScriptContext
      const needsCleanup = !ctx.firstName
      let title = step.title ? this.vt(step.title, ctx) : ''
      let text = step.text ? this.vt(step.text, ctx) : ''
      if (needsCleanup) {
        title = cleanUnresolved(title)
        text = cleanUnresolved(text)
      }
      return { ...step, title, text }
    },
    applyVirtuProfState(data = {}) {
      this.dismissedTriggers = Array.isArray(data.dismissed) ? data.dismissed : []
      this.language = detectVirtuProfLanguage()
      if (typeof data.enabled === 'boolean') {
        this.$emit('enabled-change', data.enabled)
      }
      this.visitedGuideKeys = Array.isArray(data.visited_tools) ? data.visited_tools : []
      this.aiEnabled = data.ai_enabled === true
      this.ttsEnabled = data.tts_enabled === true
      this.sttEnabled = data.stt_enabled === true
      this.voiceLang = VOICE_LANGUAGE_OPTIONS.some(option => option.value === data.voice_lang)
        ? data.voice_lang
        : this.getBrowserVoiceLanguage()
      this.onboardingReminderCount = Number.isFinite(Number(data.onboarding_reminder_count))
        ? Math.max(0, Math.min(3, Number(data.onboarding_reminder_count)))
        : 0
      if (data.onboarding_declined === true) {
        this.onboardingDeclined = true
      }
    },
    getBrowserVoiceLanguage() {
      if (typeof navigator === 'undefined') {
        return 'de-DE'
      }
      const browserLanguage = String(navigator.language || '').trim()
      const matchedOption = VOICE_LANGUAGE_OPTIONS.find((option) => option.value === browserLanguage)
      if (matchedOption) {
        return matchedOption.value
      }
      const baseLanguage = browserLanguage.slice(0, 2).toLowerCase()
      return VOICE_LANGUAGE_OPTIONS.find((option) => option.value.toLowerCase().startsWith(baseLanguage + '-'))?.value || 'de-DE'
    },
    async saveVirtuProfPreferences(payload) {
      const response = await axios.put(generateUrl('/apps/learning/api/virtuprof/preferences'), payload)
      this.applyVirtuProfState(response.data || {})
      return response.data || {}
    },
    resetTelosReminderCount() {
      this.onboardingReminderCount = 0
      this.saveVirtuProfPreferences({ onboardingReminderCount: 0 }).catch(() => {})
    },
    async checkTelosOnboarding() {
      if (this.userRole !== 'student' || !this.enabled) {
        return
      }

      try {
        const response = await axios.get(generateUrl('/apps/learning/api/profile/telos/status'))
        this.telosProfileLoaded = true
        if (response.data?.onboarding_completed) {
          this.telosSaved = true
          if (response.data?.telos) {
            this.telosForm = applyTelosToForm(response.data.telos)
          }
          return
        }
        if (response.data?.onboarding_declined) {
          this.onboardingDeclined = true
          return
        }
        this.showWelcomeStep()
      } catch (e) {
        this.telosProfileLoaded = true
      }
    },
    showWelcomeStep() {
      this.showOnboardingIntro = true
    },
    onIntroFinished() {
      this.showOnboardingIntro = false
      this.helpView = 'welcome'
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'wave'
    },
    startJourney() {
      this.telosOnboardingActive = true
      this.telosError = ''
      this.telosSaved = false
      this.journeyStepIndex = 0
      this.telosForm = createTelosForm()
      this.telosCompletionProfile = null
      this.selectedPresetId = null
      this.helpView = 'preset-select'
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'talk'
    },
    applyPreset(presetId) {
      const preset = ONBOARDING_PRESETS.find(p => p.id === presetId)
      if (!preset) return
      this.selectedPresetId = presetId
      const form = createTelosForm()
      Object.keys(preset.telos || {}).forEach(key => {
        form.telos[key] = preset.telos[key]
      })
      if (preset.visibility) {
        form.visibility = preset.visibility
      }
      this.telosForm = form
      this.helpView = 'telos-journey'
      this.journeyStepIndex = 0
    },
    startCustomJourney() {
      this.telosForm = createTelosForm()
      this.selectedPresetId = null
      this.helpView = 'telos-journey'
      this.journeyStepIndex = 0
    },
    declineOnboarding() {
      this.onboardingDeclined = true
      this.helpView = null
      this.visible = false
      this.currentAnimation = 'idle'
      this.telosOnboardingActive = false
      this.saveVirtuProfPreferences({ onboardingDeclined: true }).catch(() => {})
    },
    journeyNext() {
      if (this.journeyStepIndex < JOURNEY_STEPS.length - 1) {
        this.journeyStepIndex += 1
        this.currentAnimation = 'talk'
      }
    },
    journeyBack() {
      if (this.journeyStepIndex > 0) {
        this.journeyStepIndex -= 1
        this.currentAnimation = 'talk'
      }
    },
    openTelosForm() {
      this.telosOnboardingActive = true
      this.helpView = 'telos-form'
      this.chatMessages = []
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'talk'
    },
    updateTelosField(field, value) {
      if (!field) {
        return
      }
      const segments = String(field).split('.')
      if (segments.length === 1) {
        this.telosForm[segments[0]] = value
        return
      }

      let target = this.telosForm
      for (let index = 0; index < segments.length - 1; index += 1) {
        const key = segments[index]
        if (!target[key] || typeof target[key] !== 'object') {
          target[key] = {}
        }
        target = target[key]
      }
      target[segments[segments.length - 1]] = value
    },
    async submitTelosForm() {
      this.telosSaving = true
      this.telosError = ''
      this.telosSaved = false
      try {
        const payload = buildTelosPayload(this.telosForm)
        await axios.post(generateUrl('/apps/learning/api/profile/telos'), payload)
        this.telosSaved = true
        this.telosOnboardingActive = false
        this.telosCompletionProfile = payload
        this.helpView = 'telos-complete'
        this.applyReaction('milestone')
        this.resetTelosReminderCount()
      } catch (e) {
        this.telosError = e?.response?.data?.error || this.vt('Could not save your learning profile.')
      } finally {
        this.telosSaving = false
      }
    },
    async handleGuide(payload = {}) {
      const guideKey = String(payload?.key || '').trim()
      if (!guideKey || this.userRole !== 'student' || !this.enabled || this.telosOnboardingActive) {
        return
      }
      if (this.visible && this.helpView && this.helpView !== 'guide') {
        return
      }

      const isFirstVisit = !this.visitedGuideKeys.includes(guideKey)
      if (!isFirstVisit && this.repeatGuideKeys.includes(guideKey)) {
        return
      }

      if (isFirstVisit) {
        await this.persistGuideVisit(guideKey)
      } else {
        this.repeatGuideKeys.push(guideKey)
      }

      this.guideStep = {
        key: guideKey,
        title: payload.title || this.vt('Guide'),
        text: isFirstVisit ? (payload.text || '') : (payload.shortText || payload.text || ''),
      }
      this.helpView = 'guide'
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'talk'
    },
    async persistGuideVisit(guideKey) {
      if (!this.visitedGuideKeys.includes(guideKey)) {
        this.visitedGuideKeys.push(guideKey)
      }
      try {
        await axios.post(generateUrl('/apps/learning/api/virtuprof/guide-visit'), { guideKey })
      } catch (e) {
        // Ignore persistence errors.
      }
    },
    enqueue(triggerId, context = {}) {
      // Reaction hooks for quiz events
      if (triggerId === 'gameshow-answer-correct') {
        this.applyReaction('answer-correct', context)
      } else if (triggerId === 'gameshow-answer-wrong') {
        this.applyReaction('answer-wrong', context)
      }
      const script = SCRIPTS[triggerId]
      if (!script || !this.enabled) {
        return
      }
      if (!this.shouldShow(triggerId, script)) {
        return
      }
      const alreadyQueued = this.queue.some(item => item.id === triggerId)
      if (alreadyQueued || this.currentScriptId === triggerId) {
        return
      }

      // GREET-01: Auto-inject firstName so any script can use {firstName}
      const enrichedContext = this.userFirstName
        ? { firstName: this.userFirstName, ...context }
        : { ...context }
      this.queue.push({
        id: triggerId,
        script,
        context: enrichedContext,
        priority: script.priority || 0,
      })
      this.queue.sort((left, right) => right.priority - left.priority)

      if (!this.processing && !this.isHelpOpen) {
        this.processNext()
      }
    },
    setExamMode(active) {
      this.isExamMode = !!active
    },
    updateContext(context = {}) {
      this.currentContext = {
        ...this.currentContext,
        ...(context || {}),
      }
      // HINT-03: Reset hint counter when question changes
      const newQuestionId = this.currentContext?.questionContext?.questionId
        || this.currentContext?.questionId || null
      if (newQuestionId !== this.lastHintQuestionId) {
        this.hintLevel = 0
        this.lastHintQuestionId = newQuestionId
      }
    },
    shouldShow(triggerId, script) {
      if (script.condition === 'once') {
        return !this.dismissedTriggers.includes(triggerId)
      }
      if (script.condition === 'daily') {
        return !this.wasShownToday(triggerId)
      }
      return true
    },
    wasShownToday(triggerId) {
      try {
        return window.localStorage.getItem(this.storageKey(triggerId)) === this.todayStamp()
      } catch (e) {
        return false
      }
    },
    markShownToday(triggerId) {
      try {
        window.localStorage.setItem(this.storageKey(triggerId), this.todayStamp())
      } catch (e) {
        // Ignore storage failures.
      }
    },
    todayStamp() {
      return new Date().toISOString().slice(0, 10)
    },
    storageKey(triggerId) {
      const uid = (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function' && OC.getCurrentUser())
        ? (OC.getCurrentUser().uid || 'user')
        : 'user'
      return `learning:virtuprof:daily:${uid}:${triggerId}`
    },
    processNext() {
      if (!this.enabled || this.queue.length === 0 || this.isHelpOpen) {
        this.processing = false
        return
      }

      this.processing = true
      const nextItem = this.queue.shift()
      this.currentScriptId = nextItem.id
      this.currentScriptMeta = nextItem.script
      this.currentScript = JSON.parse(JSON.stringify(nextItem.script))
      this.currentScriptContext = nextItem.context || {}
      this.stepIndex = 0
      this.currentAnimation = this.currentScript.steps?.[0]?.animation || 'talk'

      if (this.pendingTimer) {
        clearTimeout(this.pendingTimer)
      }
      this.pendingTimer = setTimeout(() => {
        this.visible = true
        this.isMinimized = false
        this.pendingTimer = null
      }, nextItem.script.delay || 0)
    },
    nextStep() {
      if (!this.currentScript || !Array.isArray(this.currentScript.steps)) {
        this.finish()
        return
      }
      if (this.stepIndex < this.currentScript.steps.length - 1) {
        this.stepIndex += 1
        this.currentAnimation = this.currentScript.steps[this.stepIndex]?.animation || 'talk'
        return
      }
      this.dismiss()
    },
    panelTouchStart(e) {
      if (e.touches && e.touches.length === 1) {
        this.panelTouchY = e.touches[0].clientY
      }
    },
    panelTouchEnd(e) {
      if (!e.changedTouches || !e.changedTouches.length) return
      const dy = e.changedTouches[0].clientY - this.panelTouchY
      if (dy > 80) {
        this.isMinimized = true
      }
    },
    async dismiss() {
      if (this.isHelpOpen) {
        if (this.telosOnboardingActive) {
          this.declineOnboarding()
          return
        }
        this.closeHelp()
        return
      }
      await this.markHandled(this.currentScriptId, this.currentScriptMeta)
      this.finish()
    },
    async handleAction(action) {
      if (action?.type === 'open-help-home') {
        this.openHelpHome()
        return
      }
      if (action?.type === 'open-context-help') {
        this.openContextHelp()
        return
      }
      if (action?.type === 'open-faq-list') {
        this.openFaqList()
        return
      }
      if (action?.type === 'open-faq-category') {
        this.openFaqCategory(action.categoryId)
        return
      }
      if (action?.type === 'open-faq') {
        this.openFaq(action.faqId)
        return
      }
      if (action?.type === 'set-ticket-category') {
        this.ticketCategory = action.value || 'technical'
        return
      }
      if (action?.type === 'open-ticket-form') {
        this.openTicketForm()
        return
      }
      if (action?.type === 'open-ticket-list') {
        await this.openTicketList()
        return
      }
      if (action?.type === 'open-invite-list') {
        await this.openInviteList(action.filter || 'incoming')
        return
      }
      if (action?.type === 'submit-ticket') {
        await this.submitTicket()
        return
      }
      if (action?.type === 'refresh-invites') {
        await this.refreshDuelInvites(false)
        return
      }
      if (action?.type === 'accept-invite') {
        await this.acceptInvite(action.inviteId)
        return
      }
      if (action?.type === 'decline-invite') {
        await this.declineInvite(action.inviteId)
        return
      }
      if (action?.type === 'cancel-invite') {
        await this.cancelInvite(action.inviteId)
        return
      }
      if (action?.type === 'open-duel') {
        this.openDuel(action.courseId, action.duelCode)
        return
      }
      if (action?.type === 'close-help') {
        this.closeHelp()
        return
      }
      if (action?.type === 'start-app-tour') {
        this.telosOnboardingActive = false
        this.helpView = null
        this.openContextHelp()
        return
      }
      if (action?.type === 'update-telos-field') {
        this.updateTelosField(action.field, action.value)
        return
      }
      if (action?.type === 'submit-telos-form') {
        await this.submitTelosForm()
        return
      }
      if (action?.type === 'postpone-telos-onboarding') {
        this.declineOnboarding()
        return
      }
      if (action?.type === 'start-journey') {
        this.startJourney()
        return
      }
      if (action?.type === 'decline-onboarding') {
        this.declineOnboarding()
        return
      }
      if (action?.type === 'apply-preset') {
        this.applyPreset(action.presetId)
        return
      }
      if (action?.type === 'start-custom-journey') {
        this.startCustomJourney()
        return
      }
      if (action?.type === 'journey-next') {
        this.journeyNext()
        return
      }
      if (action?.type === 'journey-back') {
        this.journeyBack()
        return
      }
      if (action?.type === 'open-telos-form') {
        this.openTelosForm()
        return
      }
      // TRIG-02: Generate a weakness note for the current pool context
      if (action?.type === 'generate-note-for-context') {
        await this.generateNoteForContext()
        this.dismiss()
        return
      }
      // MEM-04: Clear persistent chat history
      if (action?.type === 'clear-chat-history') {
        await this.clearChatHistory()
        return
      }
      if (action?.next) {
        this.enqueue(action.next, action.context || {})
      }
      this.dismiss()
    },
    // TRIG-02: Call the Note Generator API for the current pool in context
    async generateNoteForContext() {
      const poolId = this.currentScriptContext?.poolId
      if (!poolId) {
        return
      }
      try {
        await axios.post(generateUrl('/apps/learning/api/notes/generate'), {
          pool_id: poolId,
          course_id: this.currentScriptContext?.courseId || null,
        })
      } catch (e) {
        // Best-effort — user can use manual button if this fails
      }
    },
    handleVoiceSettingsChanged(payload = {}) {
      if (typeof payload.ttsEnabled === 'boolean') {
        this.ttsEnabled = payload.ttsEnabled
      }
      if (typeof payload.sttEnabled === 'boolean') {
        this.sttEnabled = payload.sttEnabled
      }
      if (VOICE_LANGUAGE_OPTIONS.some(option => option.value === payload.voiceLang)) {
        this.voiceLang = payload.voiceLang
      }
    },
    async markHandled(triggerId, script) {
      if (!triggerId || !script) {
        return
      }
      if (script.condition === 'once') {
        if (!this.dismissedTriggers.includes(triggerId)) {
          this.dismissedTriggers.push(triggerId)
        }
        try {
          await axios.post(generateUrl('/apps/learning/api/virtuprof/dismiss'), { triggerId })
        } catch (e) {
          // Ignore backend persistence errors.
        }
      }
      if (script.condition === 'daily') {
        this.markShownToday(triggerId)
      }
    },
    finish() {
      this.visible = false
      this.currentScriptId = null
      this.currentScript = null
      this.currentScriptMeta = null
      this.currentScriptContext = {}
      this.stepIndex = 0
      this.currentAnimation = 'idle'
      setTimeout(() => {
        this.processing = false
        this.processNext()
      }, 300)
    },
    clearPresentation() {
      if (this.pendingTimer) {
        clearTimeout(this.pendingTimer)
        this.pendingTimer = null
      }
      if (this.chatAnimationTimer) {
        clearTimeout(this.chatAnimationTimer)
        this.chatAnimationTimer = null
      }
      this.queue = []
      this.processing = false
      this.visible = false
      this.isMinimized = false
      this.currentScriptId = null
      this.currentScript = null
      this.currentScriptMeta = null
      this.currentScriptContext = {}
      this.stepIndex = 0
      this.currentAnimation = 'idle'
      this.helpView = null
      this.guideStep = null
      this.repeatGuideKeys = []
      this.activeFaqId = null
      this.activeFaqCategoryId = null
      this.ticketSubject = ''
      this.ticketDraft = ''
      this.ticketCategory = 'technical'
      this.ticketSending = false
      this.ticketError = ''
      this.ticketSuccess = ''
      this.myTickets = []
      this.inviteError = ''
      this.activeInviteFilter = 'incoming'
      this.chatMessages = []
      this.chatLoading = false
      this.showAiConsentDialog = false
      this.pendingChatMessage = null
      this.telosOnboardingActive = false
      this.telosSaving = false
      this.telosError = ''
      this.telosSaved = false
      this.telosCompletionProfile = null
    },
    applyReaction(eventType, context = {}) {
      const reaction = novaReactions.react(eventType, context)
      if (!reaction) return
      this.currentAnimation = reaction.animation
      this.currentEmotion = reaction.emotion
      if (reaction.duration) {
        if (this._reactionTimer) clearTimeout(this._reactionTimer)
        this._reactionTimer = setTimeout(() => {
          this.currentAnimation = 'idle'
          this.currentEmotion = null
          this._reactionTimer = null
        }, reaction.duration)
      }
    },
    handleAvatarClick() {
      if (!this.enabled) {
        return
      }
      if (this.isMinimized) {
        this.isMinimized = false
        return
      }
      if (this.visible) {
        this.isMinimized = true
        return
      }
      this.openHelpHome()
    },
    requestFullscreen() {
      this.$emit('open-fullscreen')
    },
    handleFullscreenClose() {
      this.$emit('close-fullscreen')
    },
    openHelpHome() {
      this.resetTicketFeedback()
      this.helpView = 'home'
      this.activeFaqId = null
      this.activeFaqCategoryId = null
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'wave'
    },
    openContextHelp() {
      this.resetTicketFeedback()
      this.helpView = 'context'
      this.activeFaqId = null
      this.activeFaqCategoryId = null
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'talk'
    },
    openFaqList() {
      this.resetTicketFeedback()
      this.helpView = 'faq-list'
      this.activeFaqId = null
      this.activeFaqCategoryId = null
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'talk'
    },
    openFaqCategory(categoryId) {
      if (!FAQ_CATEGORIES[categoryId]) {
        return
      }
      this.resetTicketFeedback()
      this.helpView = 'faq-category'
      this.activeFaqId = null
      this.activeFaqCategoryId = categoryId
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'talk'
    },
    openFaq(faqId) {
      if (!FAQS[faqId]) {
        return
      }
      this.resetTicketFeedback()
      this.helpView = 'faq-answer'
      this.activeFaqId = faqId
      this.activeFaqCategoryId = FAQS[faqId].category || null
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'talk'
    },
    openTicketForm() {
      this.helpView = 'ticket-form'
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'talk'
      this.ticketError = ''
      this.ticketSuccess = ''
      if (!this.ticketSubject) {
        this.ticketSubject = this.defaultTicketSubject()
      }
      this.ticketCategory = this.hasCourseContext ? 'course_content' : 'technical'
    },
    async openTicketList() {
      this.helpView = 'ticket-list'
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'talk'
      this.ticketError = ''
      this.ticketSuccess = ''
      await this.loadMyTickets()
    },
    async openInviteList(filter = 'incoming') {
      this.resetTicketFeedback()
      this.helpView = 'invite-list'
      this.activeFaqId = null
      this.activeFaqCategoryId = null
      this.activeInviteFilter = filter
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'wave'
      this.inviteError = ''
      await this.refreshDuelInvites(false)
    },
    closeHelp() {
      if (this.telosOnboardingActive) {
        this.declineOnboarding()
        return
      }
      this.helpView = null
      this.guideStep = null
      this.activeFaqId = null
      this.activeFaqCategoryId = null
      this.resetTicketFeedback()
      if (!this.currentScriptId) {
        this.visible = false
        this.currentAnimation = 'idle'
      }
      if (this.queue.length > 0 && !this.processing) {
        this.processNext()
      }
    },
    buildHelpStep() {
      if (this.helpView === 'guide') {
        return this.buildGuideStep()
      }
      if (this.helpView === 'welcome') {
        return this.buildWelcomeStepContent()
      }
      if (this.helpView === 'preset-select') {
        return this.buildPresetSelectStep()
      }
      if (this.helpView === 'telos-journey') {
        return this.buildTelosJourneyStep()
      }
      if (this.helpView === 'telos-form') {
        return this.buildTelosFormStep()
      }
      if (this.helpView === 'telos-complete') {
        return this.buildTelosCompleteStep()
      }
      if (this.helpView === 'context') {
        return this.buildContextStep()
      }
      if (this.helpView === 'faq-list') {
        return this.buildFaqListStep()
      }
      if (this.helpView === 'faq-category') {
        return this.buildFaqCategoryStep()
      }
      if (this.helpView === 'faq-answer') {
        return this.buildFaqAnswerStep()
      }
      if (this.helpView === 'ticket-form') {
        return this.buildTicketFormStep()
      }
      if (this.helpView === 'ticket-list') {
        return this.buildTicketListStep()
      }
      if (this.helpView === 'invite-list') {
        return this.buildInviteListStep()
      }
      return this.buildHelpHomeStep()
    },
    buildGuideStep() {
      return {
        title: this.guideStep?.title || this.vt('Guide'),
        text: this.guideStep?.text || '',
        hideMoreOptions: true,
        showIntroInline: true,
        renderActionsInline: true,
        disableSuggestions: true,
        actions: [
          { label: this.vt('Ok, got it'), type: 'close-help' },
        ],
      }
    },
    buildWelcomeStepContent() {
      return {
        title: this.vt('Welcome to DevCloud!'),
        text: this.vt('I am VirtuProf — your personal learning assistant. I can explain topics, give tips when you get stuck, and help you find the right exercises.\n\nThe more I know about you, the better I can help. Want to set up your learning profile? It takes about 2 minutes.'),
        kind: 'welcome',
        hideMoreOptions: true,
        showIntroInline: true,
        renderActionsInline: true,
        disableSuggestions: true,
        actions: [
          { label: this.vt('Set up profile'), type: 'start-journey' },
          { label: this.vt('Skip for now'), type: 'decline-onboarding' },
        ],
      }
    },
    buildPresetSelectStep() {
      return {
        title: this.vt('Quick setup'),
        text: this.vt('Choose a preset that fits you, or set up a custom profile.'),
        kind: 'preset-select',
        presets: ONBOARDING_PRESETS.map(p => ({
          id: p.id,
          label: this.vt(p.label),
          icon: p.icon,
        })),
        hideMoreOptions: true,
        showIntroInline: true,
        renderActionsInline: true,
        disableSuggestions: true,
        actions: [],
      }
    },
    buildTelosJourneyStep() {
      const step = JOURNEY_STEPS[this.journeyStepIndex] || JOURNEY_STEPS[0]
      const isLast = this.journeyStepIndex >= JOURNEY_STEPS.length - 1
      const actions = []
      if (step.optional) {
        actions.push({ label: this.vt('Save profile'), type: 'submit-telos-form' })
        actions.push({ label: this.vt('Skip — that is enough'), type: 'submit-telos-form' })
      } else {
        actions.push({ label: isLast ? this.vt('Save profile') : this.vt('Next'), type: isLast ? 'submit-telos-form' : 'journey-next' })
        if (this.journeyStepIndex > 0) {
          actions.push({ label: this.vt('Back'), type: 'journey-back' })
        }
      }
      return {
        title: this.vt(step.title),
        text: this.vt(step.explanation),
        kind: 'telos-journey',
        journeyStepId: step.id,
        journeyStepIndex: this.journeyStepIndex,
        journeyTotalSteps: JOURNEY_STEPS.length,
        journeyFields: step.fields,
        privacyHint: step.privacyHint ? this.vt(step.privacyHint) : '',
        hideMoreOptions: true,
        showIntroInline: true,
        renderActionsInline: true,
        disableSuggestions: true,
        actions,
      }
    },
    buildTelosFormStep() {
      return {
        title: this.vt('Learning profile'),
        text: this.vt('Fill in the most important learning goals and self-assessment fields. This helps VirtuProf and gives instructors only aggregated class-level insight.'),
        kind: 'telos-form',
      }
    },
    formatTelosSummaryValue(value, fallback) {
      const normalized = Array.isArray(value)
        ? value.map(entry => String(entry || '').trim()).filter(Boolean)
        : String(value || '').trim()
      if (Array.isArray(normalized)) {
        return normalized.length > 0 ? normalized.join(', ') : fallback
      }
      return normalized || fallback
    },
    formatTelosHours(value) {
      const hours = Number(value)
      if (!Number.isFinite(hours) || hours <= 0) {
        return this.vt('Flexible')
      }
      const display = Number.isInteger(hours) ? String(hours) : hours.toFixed(1).replace(/\.0$/, '')
      return `${display}h/Woche`
    },
    buildTelosCompleteStep() {
      const profile = this.telosCompletionProfile || buildTelosPayload(this.telosForm)
      const telos = profile?.telos || {}
      const targetCert = this.formatTelosSummaryValue(telos.target_cert, this.vt('Open goal'))
      const targetDate = this.formatTelosSummaryValue(telos.target_date, this.vt('without fixed date'))
      const role = this.formatTelosSummaryValue(telos.role, this.vt('Learner'))
      const strengths = this.formatTelosSummaryValue(telos.strengths, this.vt('still building up'))
      const weaknesses = this.formatTelosSummaryValue(telos.weaknesses, this.vt('no focus topic yet'))
      const learningStyle = this.formatTelosSummaryValue(telos.learning_style, this.vt('Mixed'))
      return {
        title: this.vt('Profile saved'),
        text: this.vt('Your learning profile is set up.') + `\n\n${role}\n` + this.vt('Goal') + `: ${targetCert} ` + this.vt('by') + ` ${targetDate}\n` + this.vt('Strong') + `: ${strengths}\n` + this.vt('Practice') + `: ${weaknesses}\n${this.formatTelosHours(telos.hours_per_week)}\n${learningStyle}\n\n` + this.vt('My tips are now tailored to you. Want a tour of the app, or start learning right away?'),
        hideMoreOptions: true,
        showIntroInline: true,
        renderActionsInline: true,
        disableSuggestions: true,
        actions: [
          { label: this.vt('App-Tour'), type: 'start-app-tour' },
          { label: this.vt('Direkt lernen'), type: 'close-help' },
        ],
      }
    },
    buildHelpHomeStep() {
      const incomingCount = this.duelInvites.incoming.length
      const outgoingCount = this.duelInvites.outgoing.length
      const inviteActions = []
      if (incomingCount > 0) {
        inviteActions.push({
          label: this.vt('Incoming duel invites ({n})', { n: incomingCount }),
          type: 'open-invite-list',
          filter: 'incoming',
        })
      }
      if (outgoingCount > 0) {
        inviteActions.push({
          label: this.vt('Outgoing duel invites ({n})', { n: outgoingCount }),
          type: 'open-invite-list',
          filter: 'outgoing',
        })
      }
      const profileAction = this.isBasicMode
        ? [{ label: this.vt('Set up learning profile'), type: 'start-journey' }]
        : []
      return {
        title: this.vt('VirtuProf'),
        text: this.isBasicMode
          ? this.vt('I can help you, but without a learning profile my tips are more generic. Set up your profile for personalized support.')
          : this.vt('I stay in the corner now. Open me any time for short explanations or quick FAQs.'),
        actions: [
          ...profileAction,
          ...inviteActions,
          { label: this.vt('What can I do here?'), type: 'open-context-help' },
          { label: this.vt('Top questions for this area'), type: 'open-faq-category', categoryId: this.recommendedFaqCategoryId() },
          { label: this.vt('Browse all topics'), type: 'open-faq-list' },
          { label: this.vt('Send a question'), type: 'open-ticket-form' },
          { label: this.vt('My requests'), type: 'open-ticket-list' },
          { label: this.vt('Close'), type: 'close-help' },
        ],
      }
    },
    buildFaqListStep() {
      return {
        title: this.vt('FAQs'),
        text: this.vt('Choose a topic first. Then I will show the most common questions inside it.'),
        actionLayout: 'stacked',
        actions: [
          ...this.orderedFaqCategoryIds.map(categoryId => ({
            label: this.vt(FAQ_CATEGORIES[categoryId].label),
            type: 'open-faq-category',
            categoryId,
          })),
          { label: this.vt('Back'), type: 'open-help-home' },
        ],
      }
    },
    buildFaqCategoryStep() {
      const category = FAQ_CATEGORIES[this.activeFaqCategoryId]
      if (!category) {
        return this.buildFaqListStep()
      }
      return {
        title: this.vt(category.title),
        text: this.vt(category.description),
        actionLayout: 'stacked',
        actions: [
          ...this.orderedFaqIds.map(faqId => ({
            label: this.vt(FAQS[faqId].label),
            type: 'open-faq',
            faqId,
          })),
          { label: this.vt('All topics'), type: 'open-faq-list' },
          { label: this.vt('Back'), type: 'open-help-home' },
        ],
      }
    },
    buildFaqAnswerStep() {
      const faq = FAQS[this.activeFaqId]
      if (!faq) {
        return this.buildFaqListStep()
      }
      return {
        title: this.vt(faq.title),
        text: this.vt(faq.text),
        actions: [
          { label: this.vt('Ask about this'), type: 'open-ticket-form' },
          { label: this.vt('More in this topic'), type: 'open-faq-category', categoryId: faq.category },
          { label: this.vt('All topics'), type: 'open-faq-list' },
          { label: this.vt('Back'), type: 'open-help-home' },
        ],
      }
    },
    buildContextStep() {
      const entry = this.contextHelpEntry()
      return {
        title: entry.title,
        text: entry.text,
        actions: [
          { label: this.vt('Top questions for this area'), type: 'open-faq-category', categoryId: this.recommendedFaqCategoryId() },
          { label: this.vt('Browse all topics'), type: 'open-faq-list' },
          { label: this.vt('Send a question'), type: 'open-ticket-form' },
          { label: this.vt('Back'), type: 'open-help-home' },
        ],
      }
    },
    buildTicketFormStep() {
      return {
        kind: 'ticket-form',
        title: this.vt('Send a question'),
        text: this.vt('Your message will be stored as a support ticket with context, so an admin can answer it manually later.'),
        placeholder: this.vt('Describe your question or problem...'),
        ticketCategory: this.ticketCategory,
        hasCourseContext: this.hasCourseContext,
        categoryHint: this.categoryHint,
        actions: [
          { label: this.ticketSending ? this.vt('Sending...') : this.vt('Send ticket'), type: 'submit-ticket' },
          { label: this.vt('My requests'), type: 'open-ticket-list' },
          { label: this.vt('Back'), type: 'open-help-home' },
        ],
      }
    },
    buildTicketListStep() {
      return {
        kind: 'ticket-list',
        title: this.vt('My support tickets'),
        text: this.vt('Open tickets wait for an admin reply. Answered tickets keep the latest response here.'),
        actions: [
          { label: this.vt('New ticket'), type: 'open-ticket-form' },
          { label: this.vt('Refresh'), type: 'open-ticket-list' },
          { label: this.vt('Back'), type: 'open-help-home' },
        ],
      }
    },
    buildInviteListStep() {
      return {
        kind: 'invite-list',
        title: this.vt('Duel invites'),
        text: this.inviteError || this.vt('Open invites stay here until they are declined, canceled or the duel has been played.'),
        inviteGroups: this.buildInviteGroups(),
        actions: [
          { label: this.vt('Incoming duel invites ({n})', { n: this.duelInvites.incoming.length }), type: 'open-invite-list', filter: 'incoming' },
          { label: this.vt('Outgoing duel invites ({n})', { n: this.duelInvites.outgoing.length }), type: 'open-invite-list', filter: 'outgoing' },
          { label: this.vt('Refresh'), type: 'refresh-invites' },
          { label: this.vt('Back'), type: 'open-help-home' },
        ],
      }
    },
    buildInviteGroups() {
      const groups = []
      const includeIncoming = this.activeInviteFilter === 'incoming' || this.activeInviteFilter === 'all'
      const includeOutgoing = this.activeInviteFilter === 'outgoing' || this.activeInviteFilter === 'all'

      if (includeIncoming) {
        groups.push({
          id: 'incoming',
          title: this.vt('Incoming duel invites'),
          invites: this.duelInvites.incoming.map(invite => this.mapInviteCard(invite)),
        })
      }
      if (includeOutgoing) {
        groups.push({
          id: 'outgoing',
          title: this.vt('Outgoing duel invites'),
          invites: this.duelInvites.outgoing.map(invite => this.mapInviteCard(invite)),
        })
      }
      return groups.filter(group => group.invites.length > 0)
    },
    mapInviteCard(invite) {
      const isIncoming = invite.direction === 'incoming'
      const partnerUid = isIncoming ? invite.inviter_uid : invite.invitee_uid
      const itemActions = []

      if (invite.can_accept) {
        itemActions.push({ label: this.vt('Accept'), type: 'accept-invite', inviteId: invite.id })
      }
      if (invite.can_decline) {
        itemActions.push({ label: this.vt('Decline'), type: 'decline-invite', inviteId: invite.id })
      }
      if (invite.can_cancel) {
        itemActions.push({ label: this.vt('Cancel invite'), type: 'cancel-invite', inviteId: invite.id })
      }
      if (invite.can_open_duel) {
        itemActions.push({
          label: this.vt('Open duel'),
          type: 'open-duel',
          courseId: invite.course_id,
          duelCode: invite.duel_code,
        })
      }

      return {
        id: invite.id,
        direction: isIncoming ? 'incoming' : 'outgoing',
        status: invite.status,
        statusLabel: this.vt(this.inviteStatusLabel(invite)),
        title: isIncoming
          ? this.vt('Challenge from {user}', { user: partnerUid })
          : this.vt('Challenge to {user}', { user: partnerUid }),
        subtitle: this.vt('Pool: {pool}', { pool: invite.pool_name || ('#' + invite.pool_id) }),
        updatedAt: invite.updated_at || invite.created_at,
        message: invite.can_open_duel
          ? this.vt('This duel is ready. Open it from here and play it inside the course duel tab.')
          : isIncoming
            ? this.vt('A classmate challenged you to a duel. Accept it to jump straight into the duel lobby.')
            : this.vt('Your duel invite is waiting for a response from the opponent.'),
        itemActions,
      }
    },
    inviteStatusLabel(invite) {
      if (invite.can_open_duel) {
        return 'Ready'
      }
      return String(invite.status || 'open')
        .charAt(0).toUpperCase() + String(invite.status || 'open').slice(1)
    },
    handleInviteRefreshRequest() {
      this.refreshDuelInvites(false)
    },
    startInvitePolling() {
      if (this.invitePollingInterval) {
        return
      }
      this.invitePollingInterval = setInterval(() => {
        this.refreshDuelInvites(true)
      }, 15000)
    },
    stopInvitePolling() {
      if (this.invitePollingInterval) {
        clearInterval(this.invitePollingInterval)
        this.invitePollingInterval = null
      }
    },
    async refreshDuelInvites(triggerPopup = false) {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/duel-invites'))
        const incoming = Array.isArray(response.data?.incoming) ? response.data.incoming : []
        const outgoing = Array.isArray(response.data?.outgoing) ? response.data.outgoing : []
        this.duelInvites = { incoming, outgoing }
        this.inviteError = ''

        const openIncomingIds = incoming
          .filter(invite => invite.status === 'open')
          .map(invite => invite.id)

        if (!this.inviteNotificationsInitialized) {
          this.notifiedInviteIds = [...openIncomingIds]
          this.inviteNotificationsInitialized = true
          return
        }

        const newInviteIds = openIncomingIds.filter(id => !this.notifiedInviteIds.includes(id))
        if (newInviteIds.length > 0) {
          this.notifiedInviteIds = [...new Set([...this.notifiedInviteIds, ...newInviteIds])]
          if (triggerPopup && !this.isHelpOpen && (!this.visible || this.isMinimized)) {
            await this.openInviteList('incoming')
          }
        }
      } catch (e) {
        this.duelInvites = { incoming: [], outgoing: [] }
        this.inviteError = this.vt('Failed to load duel invites')
      }
    },
    async acceptInvite(inviteId) {
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/duel-invites/' + inviteId + '/accept'))
        await this.refreshDuelInvites(false)
        const invite = response.data?.invite || response.data
        this.openDuel(invite.course_id, invite.duel_code)
      } catch (e) {
        this.inviteError = e?.response?.data?.error || this.vt('Failed to accept duel invite')
      }
    },
    async declineInvite(inviteId) {
      try {
        await axios.post(generateUrl('/apps/learning/api/duel-invites/' + inviteId + '/decline'))
        await this.refreshDuelInvites(false)
      } catch (e) {
        this.inviteError = e?.response?.data?.error || this.vt('Failed to decline duel invite')
      }
    },
    async cancelInvite(inviteId) {
      try {
        await axios.post(generateUrl('/apps/learning/api/duel-invites/' + inviteId + '/cancel'))
        await this.refreshDuelInvites(false)
      } catch (e) {
        this.inviteError = e?.response?.data?.error || this.vt('Failed to cancel duel invite')
      }
    },
    openDuel(courseId, duelCode) {
      this.closeHelp()
      this.$emit('open-duel', { courseId, duelCode })
    },
    // MEM-01: Load persistent chat history from the server on mount.
    async loadChatHistory() {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/virtu-prof/chat-history'))
        const messages = response.data?.messages
        if (Array.isArray(messages) && messages.length > 0) {
          // Filter out 'summary' role entries (internal compression artefacts) for display
          this.chatMessages = messages
            .filter(m => m.role === 'user' || m.role === 'assistant')
            .map(m => ({ role: m.role, text: m.text || m.message || '' }))
        }
      } catch (e) {
        // Non-critical — silently ignore (fresh session if endpoint fails)
      }
    },

    // MEM-04: Delete all persistent chat memory for this user.
    async clearChatHistory() {
      try {
        await axios.delete(generateUrl('/apps/learning/api/virtu-prof/chat-history'))
        this.chatMessages = []
      } catch (e) {
        // Silently ignore — local messages already cleared above if needed
        this.chatMessages = []
      }
    },
    async handleReportError() {
      const ctx = this.currentContext?.questionContext
      if (!ctx?.questionId) {
        return
      }
      try {
        await axios.post(generateUrl('/apps/learning/api/support-tickets'), {
          subject: 'Fehlermeldung: Frage #' + ctx.questionId,
          message: 'Ein Nutzer hat diese Frage als fehlerhaft gemeldet.',
          category: 'question_error',
          questionId: ctx.questionId,
          poolId: ctx.poolId || null,
          courseId: ctx.courseId || null,
        })
        this.chatMessages.push({
          role: 'assistant',
          content: 'Danke für die Meldung! Dein Dozent wurde benachrichtigt und wird die Frage prüfen.',
        })
      } catch {
        this.chatMessages.push({
          role: 'assistant',
          content: 'Die Fehlermeldung konnte leider nicht gesendet werden. Bitte versuche es später erneut.',
        })
      }
    },

    isHintRequest(message) {
      const lower = message.toLowerCase().trim()
      const hintKeywords = ['tipp', 'hint', 'hilfe', 'help me', 'einen tipp', 'give me a hint', 'gib mir einen tipp']
      return hintKeywords.some(kw => lower === kw || lower.startsWith(kw + ' ') || lower.endsWith(' ' + kw))
    },
    isMetaQuestion(message) {
      const lower = message.toLowerCase().trim()
      // Short confused messages (< 5 words, ends with ?)
      if (lower.endsWith('?') && lower.split(/\s+/).length <= 5) {
        return true
      }
      const metaPatterns = [
        'was meinst du', 'wie meinst du', 'verstehe ich nicht', 'versteh ich nicht',
        'was bedeutet', 'erklaer', 'erklär', 'was heisst', 'was heißt',
        'kannst du das', 'was soll das', 'hä', 'huh', 'what do you mean',
        'i don\'t understand', 'what?', 'explain', 'come again',
        'nochmal bitte', 'bitte nochmal', 'wiederhole', 'repeat',
      ]
      return metaPatterns.some(p => lower.includes(p))
    },
    async handleChatSend(message) {
      if (!message || this.chatLoading) {
        return
      }

      // Phase 102: show consent dialog if version mismatch or never consented
      if (this.aiConsentVersion !== this.consentData.version) {
        this.pendingChatMessage = message
        this.showAiConsentDialog = true
        // Ensure bubble is open so the dialog is visible
        if (!this.visible || this.isMinimized) {
          if (!this.helpView) {
            this.helpView = 'home'
          }
          this.visible = true
          this.isMinimized = false
        }
        return
      }

      // Ensure bubble is open and visible
      if (!this.visible || this.isMinimized) {
        if (!this.helpView) {
          this.helpView = 'home'
        }
        this.visible = true
        this.isMinimized = false
      }

      // Push user message
      this.chatMessages.push({ role: 'user', text: message })

      // Trim to max 20 messages
      if (this.chatMessages.length > 20) {
        this.chatMessages = this.chatMessages.slice(this.chatMessages.length - 20)
      }

      // Start loading + thinking reaction
      this.chatLoading = true
      this.applyReaction('thinking')

      // Build context payload from currentContext
      const payload = { message }
      if (this.currentContext?.poolId) {
        payload.poolId = this.currentContext.poolId
      }
      if (this.currentContext?.courseId) {
        payload.courseId = this.currentContext.courseId
      }
      if (this.currentContext?.questionId) {
        payload.questionId = this.currentContext.questionId
      }
      if (this.currentContext?.questionContext) {
        payload.questionContext = this.currentContext.questionContext
      }

      // HINT: detect hint requests and increment level
      if (this.isHintRequest(message) && this.currentContext?.questionContext) {
        this.hintLevel = Math.min(this.hintLevel + 1, 3)
        payload.hintLevel = this.hintLevel
      }

      try {
        const response = await axios.post(generateUrl('/apps/learning/api/virtu-prof/chat'), payload)
        const answer = response.data?.answer
        const action = response.data?.action
        const filePath = response.data?.path || null
        const ragSources = response.data?.rag_sources || null
        const msg = {
          role: 'assistant',
          text: answer || this.vt('Sorry, no answer available.'),
          speakable: true,
        }
        if (action === 'file_created' && filePath) {
          msg.filePath = filePath
        }
        if (ragSources && ragSources.length > 0) {
          msg.sources = ragSources
        }
        this.chatMessages.push(msg)
      } catch (e) {
        const status = e?.response?.status
        let errorText
        if (status === 400) {
          errorText = this.vt('Your message could not be processed. Please try a shorter question.')
        } else if (status === 503) {
          errorText = this.vt('AI is currently disabled. Please contact your admin.')
        } else if (status === 429) {
          errorText = this.vt('Too many requests. Please wait a moment before asking again.')
        } else {
          errorText = this.vt('Something went wrong. Please try again later.')
        }
        this.chatMessages.push({ role: 'assistant', text: errorText, speakable: true })
      } finally {
        // Trim again after assistant reply
        if (this.chatMessages.length > 20) {
          this.chatMessages = this.chatMessages.slice(this.chatMessages.length - 20)
        }
        this.chatLoading = false
        this.applyReaction('chat-message')
      }
    },
    // Phase 102: User accepted the versioned AI consent dialog
    async handleConsentAccept() {
      try {
        await axios.post(generateUrl('/apps/learning/api/profile/telos/consent'), {
          version: this.consentData.version,
        })
        this.aiConsentVersion = this.consentData.version
        // Clean up legacy localStorage consent
        try {
          window.localStorage.removeItem('learning:ai_chat_consent')
        } catch (e) { /* ignore */ }
      } catch (e) {
        // Consent save failed — still allow this session
        this.aiConsentVersion = this.consentData.version
      }
      this.showAiConsentDialog = false
      const pending = this.pendingChatMessage
      this.pendingChatMessage = null
      if (pending) {
        this.handleChatSend(pending)
      }
    },

    // Phase 102: User declined the AI consent dialog
    handleConsentDecline() {
      this.showAiConsentDialog = false
      this.pendingChatMessage = null
    },

    handleExplainQuestion(payload = {}) {
      const questionText = payload.questionText || ''
      const correctAnswer = payload.correctAnswer || ''
      if (!questionText) {
        return
      }

      // Update context with question's pool/course if provided
      if (payload.poolId) {
        this.currentContext = { ...this.currentContext, poolId: payload.poolId }
      }
      if (payload.courseId) {
        this.currentContext = { ...this.currentContext, courseId: payload.courseId }
      }
      if (payload.questionId) {
        this.currentContext = { ...this.currentContext, questionId: payload.questionId }
      }

      const message = correctAnswer
        ? this.vt('Explain this question: {question} — correct answer: {answer}', { question: questionText, answer: correctAnswer })
        : this.vt('Explain this question: {question}', { question: questionText })

      this.handleChatSend(message)
    },
    recommendedFaqCategoryId() {
      const area = String(this.currentContext?.area || '')
      if (area.includes('leitner')) {
        return 'leitner'
      }
      if (area.includes('exam')) {
        return 'exam'
      }
      if (area.includes('duel') || area.includes('arena') || area.includes('gameshow') || area.includes('sprint') || area.includes('elimination')) {
        return 'arena'
      }
      if (area.includes('league')) {
        return 'league'
      }
      if (area.includes('progress') || area.includes('leaderboard')) {
        return 'progress'
      }
      if (area.includes('settings')) {
        return 'settings'
      }
      if (area.includes('required') || area.includes('pool-select') || area.includes('courses')) {
        return 'gettingStarted'
      }
      return 'training'
    },
    contextHelpEntry() {
      const area = String(this.currentContext?.area || 'courses')
      const poolName = this.currentContext?.poolName || ''
      const courseTitle = this.currentContext?.courseTitle || ''

      if (area === 'courses') {
        return {
          title: this.vt('Courses'),
          text: this.vt('Choose a course to open its learning modes, leaderboard, league and duels. Each course can have its own assigned pools and rules.'),
        }
      }
      if (area === 'course-training-pool-select') {
        return {
          title: this.vt('Training in {course}', { course: courseTitle || this.vt('this course') }),
          text: this.vt('Pick a pool to start Training. You will get direct feedback after each answer, and required enforced pools may lock the optional ones until you finish them once.'),
        }
      }
      if (area === 'course-leitner-pool-select') {
        return {
          title: this.vt('Leitner in {course}', { course: courseTitle || this.vt('this course') }),
          text: this.vt('Pick a pool to review cards with spaced repetition. The system will show difficult cards more often and mastered cards less often.'),
        }
      }
      if (area === 'course-exam-pool-select') {
        return {
          title: this.vt('Exam in {course}', { course: courseTitle || this.vt('this course') }),
          text: this.vt('Pick a pool to simulate an exam. You will finish the whole session first and only then see the full review.'),
        }
      }
      if (area === 'course-training-active') {
        return {
          title: this.vt('Training: {pool}', { pool: poolName || this.vt('selected pool') }),
          text: this.vt('You are inside an active training pool. Start the session to answer mixed questions with direct feedback after every answer.'),
        }
      }
      if (area === 'course-leitner-active') {
        return {
          title: this.vt('Leitner: {pool}', { pool: poolName || this.vt('selected pool') }),
          text: this.vt('You are inside an active Leitner pool. Review the due cards first; new or difficult questions will come back sooner than mastered ones.'),
        }
      }
      if (area === 'course-exam-active') {
        return {
          title: this.vt('Exam: {pool}', { pool: poolName || this.vt('selected pool') }),
          text: this.vt('You are inside an exam pool. Work through the whole run first; explanations and correct answers are shown at the end.'),
        }
      }
      if (area === 'course-my-progress') {
        return {
          title: this.vt('My Progress'),
          text: this.vt('This area shows your own progress in the course, including mastery and answered questions. Use it to see where you still have gaps.'),
        }
      }
      if (area === 'course-leaderboard') {
        return {
          title: this.vt('Leaderboard'),
          text: this.vt('The leaderboard compares learners in the same course. XP, mastery and other activity indicators show who is currently ahead.'),
        }
      }
      if (area === 'course-league') {
        return {
          title: this.vt('Liga'),
          text: this.vt('The league is course-specific. Challenge classmates, collect points and watch the standings change after each finished duel.'),
        }
      }
      if (area === 'course-duel' || area === 'course-arena' || area === 'arena' || area === 'pool-arena') {
        return {
          title: this.vt('Arena'),
          text: this.vt('Hier kannst du Duelle annehmen oder starten sowie Sprint- und Elimination-Runden beitreten. Die Arena bietet drei Modi: Duell (1 gegen 1), Sprint (2–5 Spieler) und Elimination (2–5 Spieler, 3 Leben).'),
        }
      }
      if (area === 'course-arena-sprint' || area === 'arena-sprint') {
        return {
          title: this.vt('Sprint'),
          text: this.vt('Im Sprint treten 2 bis 5 Spieler gleichzeitig an. Schnellste richtige Antwort gewinnt die meisten Punkte. Nach jeder der 15 Fragen siehst du die aktuelle Live-Rangliste.'),
        }
      }
      if (area === 'course-arena-elimination' || area === 'arena-elimination') {
        return {
          title: this.vt('Elimination'),
          text: this.vt('Starte mit 3 Leben. Jede falsche Antwort kostet ein Leben. Bei 2 verbleibenden Spielern beginnt der Sudden Death — wer zuerst falsch antwortet, scheidet aus.'),
        }
      }
      if (area === 'pool-training') {
        return {
          title: this.vt('Training'),
          text: this.vt('You are viewing a regular pool in Training mode. This is the fastest way to practice the pool with immediate feedback.'),
        }
      }
      if (area === 'pool-leitner') {
        return {
          title: this.vt('Leitner'),
          text: this.vt('You are viewing a regular pool in Leitner mode. Use it to keep difficult questions coming back until they stick.'),
        }
      }
      if (area === 'pool-exam') {
        return {
          title: this.vt('Exam'),
          text: this.vt('You are viewing a regular pool in exam mode. Treat it like a test run without instant correction.'),
        }
      }
      return {
        title: this.vt('This area'),
        text: this.vt('You can keep learning here, and I can explain the most important modes and rules whenever you need a quick reminder.'),
      }
    },
    defaultTicketSubject() {
      const faq = this.activeFaqId && FAQS[this.activeFaqId] ? FAQS[this.activeFaqId] : null
      if (faq?.title) {
        return this.vt(faq.title)
      }
      const parts = []
      if (this.currentContext?.courseTitle) {
        parts.push(this.currentContext.courseTitle)
      }
      if (this.currentContext?.poolName) {
        parts.push(this.currentContext.poolName)
      }
      const area = String(this.currentContext?.area || '').trim()
      if (area) {
        parts.push(area)
      }
      return parts.join(' | ').slice(0, 255)
    },
    resetTicketFeedback() {
      this.ticketError = ''
      this.ticketSuccess = ''
    },
    async loadMyTickets() {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/support-tickets'), {
          params: { limit: 20 },
        })
        this.myTickets = Array.isArray(response.data?.tickets) ? response.data.tickets : []
      } catch (e) {
        this.myTickets = []
        this.ticketError = this.vt('Failed to load support tickets')
      }
    },
    async submitTicket() {
      if (this.ticketSending) {
        return
      }
      this.ticketSending = true
      this.ticketError = ''
      this.ticketSuccess = ''
      try {
        await axios.post(generateUrl('/apps/learning/api/support-tickets'), {
          subject: this.ticketSubject || this.defaultTicketSubject(),
          message: this.ticketDraft,
          category: this.ticketCategory,
          context: {
            ...this.currentContext,
            faqId: this.activeFaqId,
            faqCategoryId: this.activeFaqCategoryId,
            helpView: this.helpView,
            title: this.currentBubbleStep?.title || '',
          },
        })
        this.ticketDraft = ''
        this.ticketSubject = this.defaultTicketSubject()
        this.ticketCategory = this.hasCourseContext ? 'course_content' : 'technical'
        this.ticketSuccess = this.vt('Support ticket sent')
        await this.loadMyTickets()
      } catch (e) {
        this.ticketError = e?.response?.data?.error || this.vt('Failed to send support ticket')
      } finally {
        this.ticketSending = false
      }
    },
  },
}
</script>

<style scoped>
.virtuprof-container {
  width: 100%;
  display: grid;
}

.virtuprof-rail {
  appearance: none;
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 8px 12px 16px;
  border: 1px solid var(--color-border);
  border-radius: 22px;
  background:
    linear-gradient(145deg, color-mix(in srgb, var(--color-primary-element) 10%, var(--color-main-background)), var(--color-main-background));
  color: inherit;
  cursor: pointer;
  text-align: left;
  font: inherit;
  transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.virtuprof-rail :deep(.virtuprof-avatar) {
  width: 48px;
  height: 63px;
}

.virtuprof-rail:hover {
  transform: translateY(-1px);
  border-color: color-mix(in srgb, var(--color-primary-element) 45%, var(--color-border));
  box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
}

.virtuprof-rail.has-invite {
  border-color: color-mix(in srgb, var(--color-primary-element) 60%, var(--color-border));
}

.virtuprof-rail-copy,
.virtuprof-panel-copy {
  min-width: 0;
  flex: 1;
  display: grid;
  gap: 4px;
}

.virtuprof-rail-kicker,
.virtuprof-panel-kicker {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-text-maxcontrast);
}

.virtuprof-rail-title,
.virtuprof-panel-title {
  min-width: 0;
  font-size: 15px;
  font-weight: 700;
  color: var(--color-main-text);
  overflow-wrap: anywhere;
}

.virtuprof-rail-status,
.virtuprof-panel-status {
  min-width: 0;
  font-size: 12px;
  line-height: 1.45;
  color: var(--color-text-maxcontrast);
  overflow-wrap: anywhere;
}

.virtuprof-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-height: calc(100vh - 80px);
}

.virtuprof-panel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-border);
}

.virtuprof-panel-toggle {
  appearance: none;
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  background: transparent;
  color: var(--color-text-maxcontrast);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font: inherit;
  transition: border-color 0.18s ease, color 0.18s ease, background 0.18s ease;
}

.virtuprof-panel-toggle:hover {
  border-color: var(--color-primary-element);
  color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
}

.virtuprof-panel :deep(.virtuprof-bubble) {
  flex: 1;
  min-height: 0;
}

.virtuprof-rail,
.virtuprof-panel {
  animation: virtuprof-fade-in 0.22s ease both;
}

@keyframes virtuprof-fade-in {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}

.virtuprof-enter-enter-active,
.virtuprof-enter-leave-active {
  transition: opacity 0.2s ease;
}

.virtuprof-enter-enter-from,
.virtuprof-enter-leave-to {
  opacity: 0;
}

@media (max-width: 768px) {
  .virtuprof-rail {
    padding: 14px 16px;
    border-radius: 16px;
    min-height: 64px;
  }

  .virtuprof-rail :deep(.virtuprof-avatar) {
    width: 40px;
    height: 52px;
  }

  .virtuprof-panel {
    max-height: calc(100vh - 120px);
  }

  .virtuprof-panel-header {
    padding: 10px 12px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .virtuprof-rail,
  .virtuprof-panel {
    animation: none;
  }
}
</style>
