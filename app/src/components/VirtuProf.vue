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
import { SCRIPTS } from '../utils/virtuprof-scripts.js'
import { VOICE_LANGUAGE_OPTIONS, getBrowserVoiceLanguage } from '../utils/virtuprof-voice.js'
import { todayStamp, storageKey } from '../utils/virtuprof-storage.js'
import virtuProfChatMixin from '../virtuProfChatMixin.js'
import virtuProfDuelInvitesMixin from '../virtuProfDuelInvitesMixin.js'
import virtuProfTelosOnboardingMixin from '../virtuProfTelosOnboardingMixin.js'
import virtuProfHelpFaqTicketMixin from '../virtuProfHelpFaqTicketMixin.js'
import {
  detectVirtuProfLanguage,
  translateVirtuProf,
} from '../utils/virtuprof-i18n.js'
import { useOptionalVirtuProfStore } from '../stores/virtuProfStore.js'
import { useSkinStore } from '../stores/skinStore.js'

export default {
  name: 'VirtuProf',
  components: { SkinRenderer, NovaPanel, VirtuProfBubble, VirtuProfFullscreen, OnboardingIntro },
  mixins: [virtuProfChatMixin, virtuProfDuelInvitesMixin, virtuProfTelosOnboardingMixin, virtuProfHelpFaqTicketMixin],
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
      visitedGuideKeys: [],
      repeatGuideKeys: [],
      currentContext: {
        area: 'courses',
        courseTitle: '',
        poolName: '',
      },
      ttsEnabled: false,
      sttEnabled: false,
      voiceLang: '',
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
    hasActiveQuestionContext() {
      return !!(this.currentContext?.questionContext?.questionId)
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
        this.voiceLang = getBrowserVoiceLanguage()
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
      // Hydrate the Pinia skinStore from /api/virtuprof/state.skin so the
      // dock-mounted SkinRenderer dispatches the persisted skin (Phase 153
      // Plan 06 Rule 1 auto-fix: Plan 04 wired loadFromServerPayload only
      // into PersonalSettings.vue, leaving the main app dock stuck at the
      // store default 'nova' for users who picked any other skin).
      useSkinStore().loadFromServerPayload(data)
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
        : getBrowserVoiceLanguage()
      this.onboardingReminderCount = Number.isFinite(Number(data.onboarding_reminder_count))
        ? Math.max(0, Math.min(3, Number(data.onboarding_reminder_count)))
        : 0
      if (data.onboarding_declined === true) {
        this.onboardingDeclined = true
      }
    },
    async saveVirtuProfPreferences(payload) {
      const response = await axios.put(generateUrl('/apps/learning/api/virtuprof/preferences'), payload)
      this.applyVirtuProfState(response.data || {})
      return response.data || {}
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
        return window.localStorage.getItem(storageKey(triggerId)) === todayStamp()
      } catch (e) {
        return false
      }
    },
    markShownToday(triggerId) {
      try {
        window.localStorage.setItem(storageKey(triggerId), todayStamp())
      } catch (e) {
        // Ignore storage failures.
      }
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

  },
}
</script>

<style scoped>
.virtuprof-container {
  width: 100%;
  display: grid;
}

.virtuprof-container :deep(.virtuprof-dock) {
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

.virtuprof-container :deep(.virtuprof-dock:hover) {
  transform: translateY(-1px);
  border-color: color-mix(in srgb, var(--color-primary-element) 45%, var(--color-border));
  box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
}

.virtuprof-container :deep(.virtuprof-dock.has-invite) {
  border-color: color-mix(in srgb, var(--color-primary-element) 60%, var(--color-border));
}

.virtuprof-container :deep(.virtuprof-dock-copy),
.virtuprof-panel-copy {
  min-width: 0;
  flex: 1;
  display: grid;
  gap: 4px;
}

.virtuprof-container :deep(.virtuprof-dock-kicker),
.virtuprof-panel-kicker {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-text-maxcontrast);
}

.virtuprof-container :deep(.virtuprof-dock-title),
.virtuprof-panel-title {
  min-width: 0;
  font-size: 15px;
  font-weight: 700;
  color: var(--color-main-text);
  overflow-wrap: anywhere;
}

.virtuprof-container :deep(.virtuprof-dock-status),
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

.virtuprof-container :deep(.virtuprof-dock),
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
  .virtuprof-container :deep(.virtuprof-dock) {
    padding: 14px 16px;
    border-radius: 16px;
    min-height: 64px;
  }

  .virtuprof-panel {
    max-height: calc(100vh - 120px);
  }

  .virtuprof-panel-header {
    padding: 10px 12px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .virtuprof-container :deep(.virtuprof-dock),
  .virtuprof-panel {
    animation: none;
  }
}
</style>
