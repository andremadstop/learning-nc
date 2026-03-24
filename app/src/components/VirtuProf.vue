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
        @next="nextStep"
        @dismiss="dismiss"
        @action="handleAction"
        @language-change="setLanguage"
        @update:ticketSubject="ticketSubject = $event"
        @update:ticketDraft="ticketDraft = $event"
        @chat-send="handleChatSend"
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

export default {
  name: 'VirtuProf',
  components: { VirtuProfAvatar, VirtuProfBubble },
  props: {
    enabled: {
      type: Boolean,
      default: true,
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
    this.$root.$on('virtuprof:refresh-duel-invites', this.handleInviteRefreshRequest)
    this.$root.$on('virtuprof:explain-question', this.handleExplainQuestion)
    await this.loadState()
    // MEM-01: Load persistent chat history so previous conversations are visible immediately
    if (this.aiEnabled) {
      await this.loadChatHistory()
    }
    await this.refreshDuelInvites(false)
    this.startInvitePolling()
    this.$emit('ready')
  },
  beforeDestroy() {
    this.$root.$off('virtuprof:trigger', this.enqueue)
    this.$root.$off('virtuprof:context', this.updateContext)
    this.$root.$off('virtuprof:refresh-duel-invites', this.handleInviteRefreshRequest)
    this.$root.$off('virtuprof:explain-question', this.handleExplainQuestion)
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
        this.dismissedTriggers = Array.isArray(response.data?.dismissed) ? response.data.dismissed : []
        this.language = normalizeVirtuProfLanguage(response.data?.language) || detectVirtuProfLanguage()
        persistVirtuProfLanguagePreference(this.language)
        if (typeof response.data?.enabled === 'boolean') {
          this.$emit('enabled-change', response.data.enabled)
        }
        // PRIV-02: read global AI enabled flag from state
        this.aiEnabled = response.data?.ai_enabled === true
      } catch (e) {
        this.dismissedTriggers = []
        this.language = detectVirtuProfLanguage()
        this.aiEnabled = false
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
    updateContext(context = {}) {
      this.currentContext = {
        ...this.currentContext,
        ...(context || {}),
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
      this.helpView = null
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

    async handleChatSend(message) {
      if (!message || this.chatLoading) {
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

      try {
        const response = await axios.post(generateUrl('/apps/learning/api/virtu-prof/chat'), payload)
        const answer = response.data?.answer
        const action = response.data?.action
        const filePath = response.data?.path || null
        const msg = {
          role: 'assistant',
          text: answer || this.vt('Sorry, no answer available.'),
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
        this.chatMessages.push({ role: 'assistant', text: errorText })
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
      const pending = this.pendingChatMessage
      this.pendingChatMessage = null
      if (pending) {
        this.handleChatSend(pending)
      }
    },

    // PRIV-01: User declined the AI consent dialog
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
