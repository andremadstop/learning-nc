/**
 * VirtuProf-Chat & versioniertes KI-Consent (DSGVO).
 * Aus VirtuProf.vue extrahiert als Options-API-Mixin (Zero-Behavior-Change).
 * Geteilter Präsentations-State (visible/isMinimized/helpView/currentContext) sowie
 * applyReaction/vt leben im Host-Component und werden über die gemergte Instanz aufgelöst.
 * HINWEIS: handleReportError bleibt bewusst im Component (Ticket/Cross-Cutting), obwohl
 * es in chatMessages schreibt.
 */
import axios from "@nextcloud/axios"
import { generateUrl } from "@nextcloud/router"
import { isHintRequest } from "./utils/virtuprof-chat-classify.js"
import consentData from "../data/ai-consent.json"

export default {
	data() {
		return {
			chatMessages: [],
			chatLoading: false,
			chatAnimationTimer: null,
			aiConsentVersion: null,
			consentData,
			showAiConsentDialog: false,
			pendingChatMessage: null,
			aiEnabled: false,
			hintLevel: 0,
			lastHintQuestionId: null,
		}
	},
	methods: {
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
      if (isHintRequest(message) && this.currentContext?.questionContext) {
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
	},
}
