<template>
  <transition name="virtuprof-enter">
    <div
      v-if="enabled"
      class="virtuprof-container"
      :class="{ minimized: isMinimized }">
      <VirtuProfBubble
        v-if="visible && currentBubbleStep && !isMinimized"
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
        @action="handleAction"
        @language-change="setLanguage"
        @update:ticketSubject="ticketSubject = $event"
        @update:ticketDraft="ticketDraft = $event"
        @chat-send="handleChatSend"
        @report-error="handleReportError"
        @consent-accept="handleConsentAccept"
        @consent-decline="handleConsentDecline" />
      <VirtuProfAvatar
        :animation="currentAnimation"
        :has-message="visible && !isMinimized"
        :invite-count="duelInvites.incoming.length"
        @click="handleAvatarClick" />
    </div>
  </transition>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import VirtuProfAvatar from './VirtuProfAvatar.vue'
import VirtuProfBubble from './VirtuProfBubble.vue'
import { FAQ_CATEGORIES, FAQS, SCRIPTS } from '../utils/virtuprof-scripts.js'
import {
  detectVirtuProfLanguage,
  normalizeVirtuProfLanguage,
  persistVirtuProfLanguagePreference,
  translateVirtuProf,
} from '../utils/virtuprof-i18n.js'
import {
  applyTelosToForm,
  buildTelosPayload,
  createTelosForm,
} from '../utils/telosProfile.js'

const TELOS_ONBOARDING_INTRO = `Hey! Schön dass du da bist. 👋

Ich bin VirtuProf — dein persönlicher Lernassistent hier in der DevCloud.

Ich kann dir Themen erklären, Tipps geben wenn du nicht weiterkommst, und dir helfen die richtigen Übungen zu finden.

Je besser ich dich kenne, desto besser kann ich dir helfen. Dafür würde ich dir gern ein paar Fragen stellen — ganz entspannt, kein Test. Dauert 2-3 Minuten.

Du kannst auch jederzeit "Später" sagen.`

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
  components: { VirtuProfAvatar, VirtuProfBubble },
  props: {
    enabled: {
      type: Boolean,
      default: true,
    },
    userRole: {
      type: String,
      default: 'student',
    },
  },
  data() {
    return {
      visible: false,
      isMinimized: false,
      currentScriptId: null,
      currentScript: null,
      currentScriptMeta: null,
      currentScriptContext: {},
      stepIndex: 0,
      currentAnimation: 'idle',
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
      // AI consent (PRIV-01)
      aiChatConsent: false,
      showAiConsentDialog: false,
      pendingChatMessage: null,
      // AI global enabled flag (PRIV-02)
      aiEnabled: false,
      telosOnboardingActive: false,
      telosQuestions: [],
      telosAnswers: [],
      telosQuestionIndex: 0,
      telosForm: createTelosForm(),
      telosSaving: false,
      telosError: '',
      telosSaved: false,
      telosFormFallbackMode: false,
      telosCompletionProfile: null,
      ttsEnabled: false,
      sttEnabled: false,
      voiceLang: '',
      onboardingReminderCount: 0,
      pendingOnboardingAction: null,
      // HINT: graduated hint tracking per question
      hintLevel: 0,
      lastHintQuestionId: null,
      // EXAM-01: VirtuProf chat lock during exam mode
      isExamMode: false,
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
  },
  async mounted() {
    // PRIV-01: Read persisted AI chat consent from localStorage
    try {
      this.aiChatConsent = window.localStorage.getItem('learning:ai_chat_consent') === 'accepted'
    } catch (e) {
      this.aiChatConsent = false
    }
    this.$root.$on('virtuprof:trigger', this.enqueue)
    this.$root.$on('virtuprof:context', this.updateContext)
    this.$root.$on('virtuprof:exam-mode', this.setExamMode)
    this.$root.$on('virtuprof:refresh-duel-invites', this.handleInviteRefreshRequest)
    this.$root.$on('virtuprof:explain-question', this.handleExplainQuestion)
    this.$root.$on('virtuprof:guide', this.handleGuide)
    this.$root.$on('virtuprof:voice-settings-changed', this.handleVoiceSettingsChanged)
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
  beforeDestroy() {
    this.$root.$off('virtuprof:trigger', this.enqueue)
    this.$root.$off('virtuprof:context', this.updateContext)
    this.$root.$off('virtuprof:exam-mode', this.setExamMode)
    this.$root.$off('virtuprof:refresh-duel-invites', this.handleInviteRefreshRequest)
    this.$root.$off('virtuprof:explain-question', this.handleExplainQuestion)
    this.$root.$off('virtuprof:guide', this.handleGuide)
    this.$root.$off('virtuprof:voice-settings-changed', this.handleVoiceSettingsChanged)
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
      return {
        ...step,
        title: step.title ? this.vt(step.title, this.currentScriptContext) : '',
        text: step.text ? this.vt(step.text, this.currentScriptContext) : '',
      }
    },
    applyVirtuProfState(data = {}) {
      this.dismissedTriggers = Array.isArray(data.dismissed) ? data.dismissed : []
      this.language = normalizeVirtuProfLanguage(data.language) || detectVirtuProfLanguage()
      persistVirtuProfLanguagePreference(this.language)
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
    incrementTelosReminderCount() {
      const nextValue = Math.min(this.onboardingReminderCount + 1, 3)
      this.onboardingReminderCount = nextValue
      this.saveVirtuProfPreferences({ onboardingReminderCount: nextValue }).catch(() => {})
      return nextValue
    },
    resetTelosReminderCount() {
      this.onboardingReminderCount = 0
      this.saveVirtuProfPreferences({ onboardingReminderCount: 0 }).catch(() => {})
    },
    buildTelosIntroMessages() {
      const firstQuestion = this.telosQuestions[0]?.text || this.vt('What do you do professionally or in your training right now?')
      return [
        {
          role: 'assistant',
          text: TELOS_ONBOARDING_INTRO,
          speakable: true,
        },
        {
          role: 'assistant',
          text: firstQuestion,
          speakable: true,
        },
      ]
    },
    async loadTelosQuestions() {
      if (this.telosQuestions.length > 0) {
        return this.telosQuestions
      }
      const response = await axios.get(generateUrl('/apps/learning/api/profile/telos/questions'))
      const questions = response.data?.questions || {}
      this.telosQuestions = Object.entries(questions).map(([key, text]) => ({
        key,
        text: String(text || '').trim(),
      })).filter((item) => item.text)
      return this.telosQuestions
    },
    async checkTelosOnboarding() {
      if (this.userRole !== 'student' || !this.enabled) {
        return
      }

      try {
        const response = await axios.get(generateUrl('/apps/learning/api/profile/telos/status'))
        if (response.data?.onboarding_completed) {
          return
        }
        if (this.onboardingReminderCount >= 3) {
          await this.startTelosOnboarding(true)
          return
        }
        await this.startTelosOnboarding()
      } catch (e) {
        // Ignore missing telos onboarding state.
      }
    },
    async startTelosOnboarding(forceForm = false) {
      if (this.userRole !== 'student') {
        return
      }

      this.telosOnboardingActive = true
      this.telosError = ''
      this.telosSaved = false
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'wave'
      this.guideStep = null
      this.telosFormFallbackMode = !!forceForm
      this.telosCompletionProfile = null
      this.pendingOnboardingAction = null

      if (!forceForm && this.aiEnabled) {
        const questions = await this.loadTelosQuestions()
        if (questions.length > 0) {
          this.helpView = 'telos-onboarding'
          this.telosAnswers = []
          this.telosQuestionIndex = 0
          this.chatMessages = this.buildTelosIntroMessages()
          return
        }
      }

      this.openTelosForm(true)
    },
    openTelosForm(forceFallback = false) {
      this.telosOnboardingActive = true
      this.telosFormFallbackMode = forceFallback || this.onboardingReminderCount >= 3
      this.helpView = 'telos-form'
      this.chatMessages = []
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'talk'
      this.pendingOnboardingAction = null
    },
    postponeTelosOnboarding() {
      this.incrementTelosReminderCount()
      this.telosOnboardingActive = false
      this.helpView = null
      this.guideStep = null
      this.telosError = ''
      this.telosAnswers = []
      this.telosQuestionIndex = 0
      this.telosFormFallbackMode = false
      this.telosCompletionProfile = null
      this.pendingOnboardingAction = null
      this.chatMessages = []
      if (!this.currentScriptId) {
        this.visible = false
        this.currentAnimation = 'idle'
      }
    },
    updateTelosField(field, value) {
      if (!field) {
        return
      }
      const segments = String(field).split('.')
      if (segments.length === 1) {
        this.$set(this.telosForm, segments[0], value)
        return
      }

      let target = this.telosForm
      for (let index = 0; index < segments.length - 1; index += 1) {
        const key = segments[index]
        if (!target[key] || typeof target[key] !== 'object') {
          this.$set(target, key, {})
        }
        target = target[key]
      }
      this.$set(target, segments[segments.length - 1], value)
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
        this.resetTelosReminderCount()
      } catch (e) {
        this.telosError = e?.response?.data?.error || this.vt('Could not save your learning profile.')
      } finally {
        this.telosSaving = false
      }
    },
    buildTelosConversation() {
      return this.telosAnswers
        .map((entry) => `Q: ${entry.question}\nA: ${entry.answer}`)
        .join('\n\n')
    },
    async requestTelosInterviewTurn(nextQuestionNumber) {
      if (!this.aiChatConsent) {
        this.pendingOnboardingAction = {
          type: 'interview-turn',
          nextQuestionNumber,
        }
        this.showAiConsentDialog = true
        return false
      }

      this.chatLoading = true
      this.currentAnimation = 'talk'
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/virtuprof/interview-turn'), {
          history: this.telosAnswers,
          nextQuestionNumber,
        })
        this.telosQuestionIndex = Math.max(0, nextQuestionNumber - 1)
        this.chatMessages.push({
          role: 'assistant',
          text: response.data?.answer || this.telosQuestions[this.telosQuestionIndex]?.text || '',
          speakable: true,
        })
        return true
      } catch (e) {
        this.telosError = e?.response?.data?.error || this.vt('I could not continue the interview right now. You can still fill the short form below.')
        this.openTelosForm(true)
        return false
      } finally {
        this.chatLoading = false
      }
    },
    async submitTelosInterview() {
      if (!this.aiChatConsent) {
        this.pendingOnboardingAction = { type: 'submit-telos' }
        this.showAiConsentDialog = true
        return
      }

      this.chatLoading = true
      this.currentAnimation = 'talk'
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/profile/telos/interview'), {
          conversation: this.buildTelosConversation(),
        })
        if (response.data?.saved) {
          this.telosForm = applyTelosToForm(response.data?.telos || {})
          this.telosSaved = true
          this.telosOnboardingActive = false
          this.telosCompletionProfile = response.data?.telos || null
          this.helpView = 'telos-complete'
          this.resetTelosReminderCount()
          return
        }

        this.telosError = response.data?.error || this.vt('I could not extract a stable profile from the interview.')
        this.openTelosForm(true)
      } catch (e) {
        this.telosError = e?.response?.data?.error || this.vt('I could not process the interview right now.')
        this.openTelosForm(true)
      } finally {
        this.chatLoading = false
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

      this.queue.push({
        id: triggerId,
        script,
        context,
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
    async dismiss() {
      if (this.isHelpOpen) {
        if (this.telosOnboardingActive) {
          this.postponeTelosOnboarding()
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
        this.postponeTelosOnboarding()
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
    async setLanguage(language) {
      const normalized = normalizeVirtuProfLanguage(language) || detectVirtuProfLanguage()
      this.language = normalized
      persistVirtuProfLanguagePreference(normalized)
      try {
        await axios.put(generateUrl('/apps/learning/api/virtuprof/language'), { language: normalized })
      } catch (e) {
        // Local fallback already persisted.
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
      this.telosAnswers = []
      this.telosQuestionIndex = 0
      this.telosSaving = false
      this.telosError = ''
      this.telosSaved = false
      this.telosFormFallbackMode = false
      this.telosCompletionProfile = null
      this.pendingOnboardingAction = null
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
        this.postponeTelosOnboarding()
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
      if (this.helpView === 'telos-onboarding') {
        return this.buildTelosOnboardingStep()
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
    buildTelosOnboardingStep() {
      const currentQuestion = this.telosQuestions[this.telosQuestionIndex]
      const total = this.telosQuestions.length || 10
      const current = Math.min(this.telosQuestionIndex + 1, total)
      return {
        title: this.vt('Learning profile onboarding'),
        text: currentQuestion
          ? this.vt('Question {current} of {total}. Answer in your own words and I will adapt the next step to you.', { current, total })
          : this.vt('Answer the next question in your own words.'),
        chatPlaceholder: this.vt('Type your answer...'),
        hideMoreOptions: true,
        showIntroInline: true,
        renderActionsInline: true,
        disableSuggestions: true,
        actions: [
          { label: this.vt('Fill manually'), type: 'open-telos-form' },
          { label: this.vt('Later'), type: 'postpone-telos-onboarding' },
        ],
      }
    },
    buildTelosFormStep() {
      return {
        title: this.vt('Learning profile'),
        text: this.telosFormFallbackMode
          ? this.vt('You do not have to chat. Fill in the short form instead and I will still adapt explanations to you.')
          : this.vt('Fill in the most important learning goals and self-assessment fields. This helps VirtuProf and gives instructors only aggregated class-level insight.'),
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
      const summaryName = (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function' && OC.getCurrentUser()?.displayName)
        ? String(OC.getCurrentUser().displayName).split(' ')[0]
        : this.vt('You')
      return {
        title: this.vt('Profile saved'),
        text: `${summaryName}, ich hab jetzt ein gutes Bild von dir:

${role}
Ziel: ${targetCert} bis ${targetDate}
Stark: ${strengths}
Üben: ${weaknesses}
${this.formatTelosHours(telos.hours_per_week)}
${learningStyle}-Lerner

Ich passe meine Erklärungen ab jetzt an dich an. Soll ich dir die App zeigen, oder willst du direkt loslegen?`,
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
      return {
        title: this.vt('VirtuProf'),
        text: this.vt('I stay in the corner now. Open me any time for short explanations or quick FAQs.'),
        actions: [
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
    handleReportError() {
      const qId = this.currentContext?.questionContext?.questionId
      if (!qId) {
        return
      }
      const message = 'Fehler melden: Frage #' + qId
      this.handleChatSend(message)
    },

    isHintRequest(message) {
      const lower = message.toLowerCase().trim()
      const hintKeywords = ['tipp', 'hint', 'hilfe', 'help me', 'einen tipp', 'give me a hint', 'gib mir einen tipp']
      return hintKeywords.some(kw => lower === kw || lower.startsWith(kw + ' ') || lower.endsWith(' ' + kw))
    },
    async handleChatSend(message) {
      if (!message || this.chatLoading) {
        return
      }

      if (this.telosOnboardingActive && this.helpView === 'telos-onboarding') {
        const currentQuestion = this.telosQuestions[this.telosQuestionIndex]
        if (!currentQuestion) {
          this.openTelosForm(true)
          return
        }

        this.chatMessages.push({ role: 'user', text: message })
        this.telosAnswers.push({
          key: currentQuestion.key,
          question: currentQuestion.text,
          answer: message,
        })

        if (this.telosQuestionIndex < this.telosQuestions.length - 1) {
          await this.requestTelosInterviewTurn(this.telosQuestionIndex + 2)
          return
        }

        await this.submitTelosInterview()
        return
      }

      // PRIV-01: show consent dialog before first AI chat
      if (!this.aiChatConsent) {
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

      // Start loading + talk animation
      this.chatLoading = true
      this.currentAnimation = 'talk'

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
        const msg = {
          role: 'assistant',
          text: answer || this.vt('Sorry, no answer available.'),
          speakable: true,
        }
        if (action === 'file_created' && filePath) {
          msg.filePath = filePath
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
        // Return avatar to idle after a short talk period
        if (this.chatAnimationTimer) {
          clearTimeout(this.chatAnimationTimer)
        }
        this.chatAnimationTimer = setTimeout(() => {
          if (this.currentAnimation === 'talk') {
            this.currentAnimation = 'idle'
          }
          this.chatAnimationTimer = null
        }, 1500)
      }
    },
    // PRIV-01: User accepted the AI consent dialog
    handleConsentAccept() {
      try {
        window.localStorage.setItem('learning:ai_chat_consent', 'accepted')
      } catch (e) {
        // Ignore storage failures.
      }
      this.aiChatConsent = true
      this.showAiConsentDialog = false
      const pendingOnboardingAction = this.pendingOnboardingAction
      this.pendingOnboardingAction = null
      const pending = this.pendingChatMessage
      this.pendingChatMessage = null
      if (pendingOnboardingAction?.type === 'interview-turn') {
        this.requestTelosInterviewTurn(pendingOnboardingAction.nextQuestionNumber)
      } else if (pendingOnboardingAction?.type === 'submit-telos') {
        this.submitTelosInterview()
      } else if (pending) {
        this.handleChatSend(pending)
      }
    },

    // PRIV-01: User declined the AI consent dialog
    handleConsentDecline() {
      this.showAiConsentDialog = false
      this.pendingChatMessage = null
      if (this.pendingOnboardingAction) {
        this.pendingOnboardingAction = null
        this.telosError = this.vt('You can still save your learning profile manually below.')
        this.openTelosForm(true)
      }
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
      if (area === 'course-swipe-pool-select') {
        return {
          title: this.vt('Wahr/Falsch in {course}', { course: courseTitle || this.vt('this course') }),
          text: this.vt('Pick a pool for fast true-or-false practice. This mode is good for quick repetition and recognition.'),
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
      if (area === 'course-swipe-active') {
        return {
          title: this.vt('Wahr/Falsch: {pool}', { pool: poolName || this.vt('selected pool') }),
          text: this.vt('You are inside a true-or-false session. Use it for fast repetition when you want to classify many statements quickly.'),
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
      if (area === 'pool-swipe') {
        return {
          title: this.vt('Wahr/Falsch'),
          text: this.vt('You are viewing a regular pool in true-or-false mode. This is useful for fast recall training.'),
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
  position: fixed;
  right: 24px;
  bottom: 24px;
  z-index: 5000;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}

.virtuprof-container.minimized {
  right: 18px;
  bottom: 18px;
}

.virtuprof-enter-enter-active,
.virtuprof-enter-leave-active {
  transition: opacity 0.2s ease;
}

.virtuprof-enter-enter,
.virtuprof-enter-leave-to {
  opacity: 0;
}

@media (max-width: 768px) {
  .virtuprof-container {
    right: 14px;
    bottom: 14px;
  }
}
</style>
