<template>
  <div class="virtuprof-bubble" :dir="textDirection">
    <div class="bubble-content">
      <div class="bubble-toolbar">
        <div class="bubble-language-toggle" role="group" aria-label="VirtuProf language">
          <button
            v-for="option in languageOptions"
            :key="option"
            type="button"
            class="bubble-language-btn"
            :class="{ active: option === effectiveLanguage }"
            @click="$emit('language-change', option)">
            {{ option.toUpperCase() }}
          </button>
        </div>
      </div>

      <!-- ── AI-first layout (aiEnabled=true) ──────────────────── -->
      <template v-if="aiEnabled">
        <!-- AI consent dialog: shown before first chat use -->
        <div v-if="showConsentDialog" class="ai-consent-overlay" role="dialog" aria-modal="true" :aria-label="vt('AI consent required')">
          <p class="ai-consent-text">{{ vt('Your question will be sent to Google Gemini (an external AI service). Do you agree?') }}</p>
          <div class="ai-consent-actions">
            <NcButton type="primary" size="small" @click="$emit('consent-accept')">{{ vt('I agree') }}</NcButton>
            <NcButton type="secondary" size="small" @click="$emit('consent-decline')">{{ vt('Cancel') }}</NcButton>
          </div>
        </div>

        <!-- Chat history (primary content) -->
        <div
          ref="chatHistory"
          class="chat-history"
          role="log"
          aria-live="polite"
          aria-label="VirtuProf chat">
          <div
            v-for="(msg, idx) in chatMessages"
            :key="idx"
            class="chat-msg"
            :class="msg.role === 'user' ? 'chat-msg--user' : 'chat-msg--assistant'">
            <span>{{ msg.text }}</span>
            <a
              v-if="msg.filePath"
              :href="ncFileUrl(msg.filePath)"
              target="_blank"
              rel="noopener noreferrer"
              class="chat-file-link">
              {{ msg.filePath }}
            </a>
          </div>
          <div v-if="chatLoading" class="chat-typing" aria-label="VirtuProf is typing">
            <span class="typing-dot" />
            <span class="typing-dot" />
            <span class="typing-dot" />
          </div>
        </div>

        <!-- Quick suggestion chips (above input) -->
        <div v-if="!examBlocked" class="chat-suggestions" role="group" :aria-label="vt('Quick suggestions')">
          <button
            v-for="suggestion in quickSuggestions"
            :key="suggestion.key"
            type="button"
            class="chat-suggestion-btn"
            :disabled="chatLoading"
            @click="sendSuggestion(suggestion.text)">
            {{ suggestion.label }}
          </button>
        </div>

        <!-- Exam blocked notice -->
        <div v-if="examBlocked" class="exam-blocked-notice" role="status">
          <span class="exam-blocked-icon">&#128274;</span>
          {{ vt('Not available during exam') }}
        </div>

        <!-- Chat input row -->
        <div v-if="!examBlocked" class="chat-input-row">
          <input
            ref="chatInput"
            v-model="chatInput"
            class="chat-input"
            type="text"
            :placeholder="vt('Ask VirtuProf...')"
            :disabled="chatLoading"
            maxlength="500"
            @keyup.enter="sendChat">
          <button
            type="button"
            class="chat-send-btn"
            :disabled="chatLoading || !chatInput.trim()"
            :aria-label="vt('Send')"
            @click="sendChat">
            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true">
              <path d="M2 10l16-8-6 8 6 8z" fill="currentColor" />
            </svg>
          </button>
        </div>

        <!-- Report error button -->
        <button
          v-if="hasQuestionContext && !examBlocked"
          type="button"
          class="report-error-btn"
          :disabled="chatLoading"
          @click="$emit('report-error')">
          &#9888; {{ vt('Report error in question') }}
        </button>

        <!-- Clear row -->
        <div v-if="chatMessages.length > 0" class="chat-clear-row">
          <button
            type="button"
            class="chat-clear-btn"
            @click="$emit('action', { type: 'clear-chat-history' })">
            {{ vt('Clear chat history') }}
          </button>
        </div>

        <!-- Collapsible "Mehr Optionen" section -->
        <div class="mehr-optionen">
          <button
            type="button"
            class="mehr-optionen-toggle"
            :aria-expanded="moreOptionsOpen.toString()"
            @click="moreOptionsOpen = !moreOptionsOpen">
            <svg
              class="mehr-optionen-chevron"
              :class="{ open: moreOptionsOpen }"
              viewBox="0 0 16 16"
              width="12"
              height="12"
              aria-hidden="true">
              <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            {{ vt('More options') }}
          </button>

          <div v-if="moreOptionsOpen" class="mehr-optionen-body">
            <!-- Existing step content (FAQ menus etc.) when in help mode -->
            <p v-if="step.title" class="bubble-title">{{ step.title }}</p>
            <p v-if="step.text" class="bubble-text">{{ step.text }}</p>

            <div v-if="step.kind === 'ticket-form'" class="ticket-form">
              <div class="ticket-category-row">
                <label class="ticket-label" for="virtuprof-ticket-category">{{ vt('Category') }}</label>
                <select
                  id="virtuprof-ticket-category"
                  class="ticket-select"
                  :value="step.ticketCategory || 'technical'"
                  @change="$emit('action', { type: 'set-ticket-category', value: $event.target.value })">
                  <option v-if="step.hasCourseContext" value="course_content">{{ vt('Course content question') }}</option>
                  <option value="technical">{{ vt('Technical problem') }}</option>
                  <option value="usage">{{ vt('Usage question') }}</option>
                </select>
                <small v-if="step.categoryHint" class="ticket-category-hint">{{ step.categoryHint }}</small>
              </div>
              <label class="ticket-label" for="virtuprof-ticket-subject">{{ vt('Subject') }}</label>
              <input
                id="virtuprof-ticket-subject"
                class="ticket-input"
                type="text"
                :value="ticketSubject"
                :placeholder="vt('Short summary')"
                @input="$emit('update:ticketSubject', $event.target.value)">
              <label class="ticket-label" for="virtuprof-ticket-message">{{ vt('Message') }}</label>
              <textarea
                id="virtuprof-ticket-message"
                class="ticket-textarea"
                rows="5"
                :value="ticketDraft"
                :placeholder="step.placeholder || vt('Describe your question or problem...')"
                @input="$emit('update:ticketDraft', $event.target.value)" />
              <p v-if="ticketError" class="ticket-error">{{ ticketError }}</p>
              <p v-if="ticketSuccess" class="ticket-success">{{ ticketSuccess }}</p>
            </div>

            <div v-if="step.kind === 'ticket-list'" class="ticket-list">
              <div v-if="tickets.length === 0" class="ticket-empty">
                {{ vt('No support tickets yet.') }}
              </div>
              <div v-for="ticket in tickets" :key="ticket.id" class="ticket-item">
                <div class="ticket-header">
                  <strong>{{ ticket.subject }}</strong>
                  <span class="ticket-status" :class="'status-' + ticket.status">{{ statusLabel(ticket.status) }}</span>
                </div>
                <div class="ticket-meta">{{ formatTimestamp(ticket.updated_at || ticket.created_at) }}</div>
                <div class="ticket-message">{{ ticket.message }}</div>
                <div v-if="ticket.answer_text" class="ticket-answer">
                  <strong>{{ vt('Answer') }}:</strong> {{ ticket.answer_text }}
                </div>
              </div>
            </div>

            <div v-if="step.kind === 'invite-list'" class="invite-list">
              <div v-if="!hasInviteGroups" class="ticket-empty">
                {{ vt('No active duel invites right now.') }}
              </div>
              <div v-for="group in step.inviteGroups || []" :key="group.id" class="invite-group">
                <p class="invite-group-title">{{ group.title }}</p>
                <div v-for="invite in group.invites" :key="group.id + '-' + invite.id" class="invite-item">
                  <div class="ticket-header">
                    <strong>{{ invite.title }}</strong>
                    <span class="ticket-status" :class="'status-' + invite.status">{{ invite.statusLabel || statusLabel(invite.status) }}</span>
                    <!-- X dismiss button for outgoing and incoming invite cards -->
                    <button
                      type="button"
                      class="invite-dismiss-btn"
                      :aria-label="invite.direction === 'incoming' ? vt('Decline') : vt('Cancel invite')"
                      @click="$emit('action', invite.direction === 'incoming'
                        ? { type: 'decline-invite', inviteId: invite.id }
                        : { type: 'cancel-invite', inviteId: invite.id })">
                      <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
                        <path d="M3 3l10 10M13 3L3 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                      </svg>
                    </button>
                  </div>
                  <div v-if="invite.subtitle" class="ticket-meta">{{ invite.subtitle }}</div>
                  <div v-if="invite.updatedAt" class="ticket-meta">{{ formatTimestamp(invite.updatedAt) }}</div>
                  <div class="ticket-message">{{ invite.message }}</div>
                  <div v-if="invite.itemActions && invite.itemActions.length" class="invite-item-actions">
                    <NcButton
                      v-for="action in invite.itemActions"
                      :key="invite.id + '-' + action.type"
                      type="secondary"
                      size="small"
                      @click="$emit('action', action)">
                      {{ action.label }}
                    </NcButton>
                  </div>
                </div>
              </div>
            </div>

            <div
              class="bubble-actions"
              :class="{ stacked: step.actionLayout === 'stacked' || (step.actions && step.actions.length > 3) }">
              <template v-if="step.actions && step.actions.length">
                <NcButton
                  v-for="action in step.actions"
                  :key="action.label"
                  type="secondary"
                  size="small"
                  :disabled="ticketSending && action.type === 'submit-ticket'"
                  @click="$emit('action', action)">
                  {{ action.label }}
                </NcButton>
              </template>
              <template v-else>
                <NcButton
                  v-if="stepIndex < totalSteps - 1"
                  type="primary"
                  size="small"
                  @click="$emit('next')">
                  {{ vt('Next') }}
                </NcButton>
                <NcButton
                  v-else
                  type="secondary"
                  size="small"
                  @click="$emit('dismiss')">
                  {{ vt('Ok, got it') }}
                </NcButton>
              </template>
            </div>
          </div>
        </div>
      </template>

      <!-- ── Non-AI fallback layout (aiEnabled=false) ───────────── -->
      <template v-else>
        <p v-if="step.title" class="bubble-title">{{ step.title }}</p>
        <p v-if="step.text" class="bubble-text">{{ step.text }}</p>

        <div v-if="step.kind === 'ticket-form'" class="ticket-form">
          <div class="ticket-category-row">
            <label class="ticket-label" for="virtuprof-ticket-category">{{ vt('Category') }}</label>
            <select
              id="virtuprof-ticket-category"
              class="ticket-select"
              :value="step.ticketCategory || 'technical'"
              @change="$emit('action', { type: 'set-ticket-category', value: $event.target.value })">
              <option v-if="step.hasCourseContext" value="course_content">{{ vt('Course content question') }}</option>
              <option value="technical">{{ vt('Technical problem') }}</option>
              <option value="usage">{{ vt('Usage question') }}</option>
            </select>
            <small v-if="step.categoryHint" class="ticket-category-hint">{{ step.categoryHint }}</small>
          </div>
          <label class="ticket-label" for="virtuprof-ticket-subject">{{ vt('Subject') }}</label>
          <input
            id="virtuprof-ticket-subject"
            class="ticket-input"
            type="text"
            :value="ticketSubject"
            :placeholder="vt('Short summary')"
            @input="$emit('update:ticketSubject', $event.target.value)">
          <label class="ticket-label" for="virtuprof-ticket-message">{{ vt('Message') }}</label>
          <textarea
            id="virtuprof-ticket-message"
            class="ticket-textarea"
            rows="5"
            :value="ticketDraft"
            :placeholder="step.placeholder || vt('Describe your question or problem...')"
            @input="$emit('update:ticketDraft', $event.target.value)" />
          <p v-if="ticketError" class="ticket-error">{{ ticketError }}</p>
          <p v-if="ticketSuccess" class="ticket-success">{{ ticketSuccess }}</p>
        </div>

        <div v-if="step.kind === 'ticket-list'" class="ticket-list">
          <div v-if="tickets.length === 0" class="ticket-empty">
            {{ vt('No support tickets yet.') }}
          </div>
          <div v-for="ticket in tickets" :key="ticket.id" class="ticket-item">
            <div class="ticket-header">
              <strong>{{ ticket.subject }}</strong>
              <span class="ticket-status" :class="'status-' + ticket.status">{{ statusLabel(ticket.status) }}</span>
            </div>
            <div class="ticket-meta">{{ formatTimestamp(ticket.updated_at || ticket.created_at) }}</div>
            <div class="ticket-message">{{ ticket.message }}</div>
            <div v-if="ticket.answer_text" class="ticket-answer">
              <strong>{{ vt('Answer') }}:</strong> {{ ticket.answer_text }}
            </div>
          </div>
        </div>

        <div v-if="step.kind === 'invite-list'" class="invite-list">
          <div v-if="!hasInviteGroups" class="ticket-empty">
            {{ vt('No active duel invites right now.') }}
          </div>
          <div v-for="group in step.inviteGroups || []" :key="group.id" class="invite-group">
            <p class="invite-group-title">{{ group.title }}</p>
            <div v-for="invite in group.invites" :key="group.id + '-' + invite.id" class="invite-item">
              <div class="ticket-header">
                <strong>{{ invite.title }}</strong>
                <span class="ticket-status" :class="'status-' + invite.status">{{ invite.statusLabel || statusLabel(invite.status) }}</span>
                <!-- X dismiss button -->
                <button
                  type="button"
                  class="invite-dismiss-btn"
                  :aria-label="invite.direction === 'incoming' ? vt('Decline') : vt('Cancel invite')"
                  @click="$emit('action', invite.direction === 'incoming'
                    ? { type: 'decline-invite', inviteId: invite.id }
                    : { type: 'cancel-invite', inviteId: invite.id })">
                  <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
                    <path d="M3 3l10 10M13 3L3 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                  </svg>
                </button>
              </div>
              <div v-if="invite.subtitle" class="ticket-meta">{{ invite.subtitle }}</div>
              <div v-if="invite.updatedAt" class="ticket-meta">{{ formatTimestamp(invite.updatedAt) }}</div>
              <div class="ticket-message">{{ invite.message }}</div>
              <div v-if="invite.itemActions && invite.itemActions.length" class="invite-item-actions">
                <NcButton
                  v-for="action in invite.itemActions"
                  :key="invite.id + '-' + action.type"
                  type="secondary"
                  size="small"
                  @click="$emit('action', action)">
                  {{ action.label }}
                </NcButton>
              </div>
            </div>
          </div>
        </div>

        <div v-if="totalSteps > 1" class="step-dots">
          <span
            v-for="i in totalSteps"
            :key="i"
            class="dot"
            :class="{ active: i === stepIndex + 1 }" />
        </div>

        <div
          class="bubble-actions"
          :class="{ stacked: step.actionLayout === 'stacked' || (step.actions && step.actions.length > 3) }">
          <template v-if="step.actions && step.actions.length">
            <NcButton
              v-for="action in step.actions"
              :key="action.label"
              type="secondary"
              size="small"
              :disabled="ticketSending && action.type === 'submit-ticket'"
              @click="$emit('action', action)">
              {{ action.label }}
            </NcButton>
          </template>
          <template v-else>
            <NcButton
              v-if="stepIndex < totalSteps - 1"
              type="primary"
              size="small"
              @click="$emit('next')">
              {{ vt('Next') }}
            </NcButton>
            <NcButton
              v-else
              type="secondary"
              size="small"
              @click="$emit('dismiss')">
              {{ vt('Ok, got it') }}
            </NcButton>
          </template>
        </div>
      </template>
    </div>
    <div class="bubble-arrow" />
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { translateVirtuProf, VIRTUPROF_LANGUAGE_OPTIONS } from '../utils/virtuprof-i18n.js'

export default {
  name: 'VirtuProfBubble',
  components: { NcButton },
  props: {
    step: {
      type: Object,
      required: true,
    },
    stepIndex: {
      type: Number,
      default: 0,
    },
    totalSteps: {
      type: Number,
      default: 1,
    },
    ticketSubject: {
      type: String,
      default: '',
    },
    ticketDraft: {
      type: String,
      default: '',
    },
    ticketSending: {
      type: Boolean,
      default: false,
    },
    ticketError: {
      type: String,
      default: '',
    },
    ticketSuccess: {
      type: String,
      default: '',
    },
    tickets: {
      type: Array,
      default: () => [],
    },
    language: {
      type: String,
      default: '',
    },
    chatMessages: {
      type: Array,
      default: () => [],
    },
    chatLoading: {
      type: Boolean,
      default: false,
    },
    aiEnabled: {
      type: Boolean,
      default: false,
    },
    showConsentDialog: {
      type: Boolean,
      default: false,
    },
    examBlocked: {
      type: Boolean,
      default: false,
    },
    hasQuestionContext: {
      type: Boolean,
      default: false,
    },
  },
  data() {
    return {
      chatInput: '',
      moreOptionsOpen: false,
    }
  },
  computed: {
    effectiveLanguage() {
      return this.language || 'de'
    },
    languageOptions() {
      return VIRTUPROF_LANGUAGE_OPTIONS
    },
    textDirection() {
      return this.effectiveLanguage === 'ar' ? 'rtl' : 'ltr'
    },
    hasInviteGroups() {
      return Array.isArray(this.step?.inviteGroups) && this.step.inviteGroups.some(group => Array.isArray(group.invites) && group.invites.length > 0)
    },
    quickSuggestions() {
      return [
        { key: 'was-ist', label: this.vt('What is...?'), text: this.vt('What is...?') },
        { key: 'erklaere', label: this.vt('Explain my last question'), text: this.vt('Explain my last question') },
        { key: 'zusammenfassung', label: this.vt('Create summary'), text: 'zusammenfassung erstellen' },
      ]
    },
  },
  watch: {
    chatMessages() {
      this.$nextTick(() => {
        this.scrollChatToBottom()
      })
    },
    chatLoading(newVal) {
      if (!newVal) {
        this.$nextTick(() => {
          this.scrollChatToBottom()
          if (this.$refs.chatInput) {
            this.$refs.chatInput.focus()
          }
        })
      }
    },
  },
  methods: {
    vt(key, params = {}) {
      return translateVirtuProf(this.effectiveLanguage, key, params)
    },
    formatTimestamp(value) {
      if (!value) {
        return ''
      }
      try {
        const locale = this.effectiveLanguage === 'ar'
          ? 'ar'
          : this.effectiveLanguage === 'ru'
            ? 'ru'
            : this.effectiveLanguage === 'de'
              ? 'de'
              : 'en'
        return new Date(Number(value) * 1000).toLocaleString(locale)
      } catch (e) {
        return String(value)
      }
    },
    statusLabel(status) {
      const normalized = String(status || '').toLowerCase()
      const labels = {
        open: 'Open',
        answered: 'Answered',
        closed: 'Closed',
        accepted: 'Accepted',
        declined: 'Declined',
        canceled: 'Canceled',
        invited: 'Invited',
        ready: 'Ready',
        active: 'Active',
      }
      return this.vt(labels[normalized] || status)
    },
    sendChat() {
      const text = this.chatInput.trim()
      if (!text || this.chatLoading) {
        return
      }
      this.$emit('chat-send', text)
      this.chatInput = ''
    },
    sendSuggestion(text) {
      if (this.chatLoading) {
        return
      }
      this.$emit('chat-send', text)
    },
    scrollChatToBottom() {
      const el = this.$refs.chatHistory
      if (el) {
        el.scrollTop = el.scrollHeight
      }
    },
    ncFileUrl(path) {
      // Build a Nextcloud Files URL for a path like /Learning/Lernplan.md
      const encoded = encodeURIComponent(path)
      return '/apps/files/?dir=' + encodeURIComponent(path.substring(0, path.lastIndexOf('/'))) + '&scrollto=' + encodeURIComponent(path.split('/').pop())
    },
  },
}
</script>

<style scoped>
.virtuprof-bubble {
  position: absolute;
  right: 0;
  bottom: 96px;
  width: min(400px, calc(100vw - 32px));
  max-height: calc(100vh - 160px);
  overflow-y: auto;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 14px;
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.18);
  padding: 16px;
  animation: bubble-appear 0.25s ease-out;
  display: flex;
  flex-direction: column;
}

@media (max-width: 480px) {
  .virtuprof-bubble {
    position: fixed;
    inset: 60px 0 0 0;
    width: 100%;
    max-height: none;
    border-radius: 16px 16px 0 0;
    bottom: 0;
    z-index: 10000;
  }
}

.bubble-content {
  position: relative;
  z-index: 1;
}

.bubble-toolbar {
  display: flex;
  justify-content: flex-end;
  margin: -2px 0 8px;
}

.bubble-language-toggle {
  display: inline-flex;
  gap: 4px;
}

.bubble-language-btn {
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text-maxcontrast);
  border-radius: 999px;
  padding: 2px 6px;
  font-size: 10px;
  font-weight: 700;
  line-height: 1.2;
  cursor: pointer;
}

.bubble-language-btn.active {
  border-color: var(--color-primary-element);
  color: var(--color-primary-element);
}

.bubble-title {
  margin: 0 0 6px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-maxcontrast);
}

.bubble-text {
  margin: 0 0 12px;
  font-size: 13px;
  line-height: 1.55;
  color: var(--color-main-text);
}

.ticket-form,
.ticket-list,
.invite-list {
  display: grid;
  gap: 8px;
  margin-bottom: 12px;
}

.invite-group {
  display: grid;
  gap: 8px;
}

.invite-group-title {
  margin: 0;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-text-maxcontrast);
}

.ticket-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.ticket-category-row {
  display: grid;
  gap: 4px;
}

.ticket-select {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 8px 10px;
  font: inherit;
  font-size: 13px;
}

.ticket-category-hint {
  font-size: 11px;
  color: var(--color-text-maxcontrast);
  margin-top: 2px;
}

.ticket-input,
.ticket-textarea {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 10px 12px;
  font: inherit;
}

.ticket-textarea {
  resize: vertical;
  min-height: 110px;
}

.ticket-error {
  margin: 0;
  font-size: 12px;
  color: var(--color-error);
}

.ticket-success {
  margin: 0;
  font-size: 12px;
  color: var(--color-success);
}

.ticket-empty {
  color: var(--color-text-maxcontrast);
  font-size: 13px;
}

.ticket-item {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 10px 12px;
  background: color-mix(in srgb, var(--color-main-background) 92%, var(--color-background-hover));
}

.invite-item {
  position: relative;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 10px 12px;
  background: color-mix(in srgb, var(--color-main-background) 92%, var(--color-background-hover));
}

.ticket-header {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  align-items: flex-start;
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

.ticket-meta {
  font-size: 11px;
  color: var(--color-text-maxcontrast);
}

.ticket-message,
.ticket-answer {
  font-size: 13px;
  line-height: 1.5;
  color: var(--color-main-text);
}

/* ── Invite dismiss (X) button ─────────────────────────── */
.invite-dismiss-btn {
  flex-shrink: 0;
  width: 20px;
  height: 20px;
  border: none;
  background: transparent;
  color: var(--color-text-maxcontrast);
  cursor: pointer;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  margin-inline-start: auto;
  transition: background 0.12s ease, color 0.12s ease;
}

.invite-dismiss-btn:hover {
  background: var(--color-background-hover);
  color: var(--color-error);
}

.invite-item-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}

.step-dots {
  display: flex;
  gap: 5px;
  margin-bottom: 12px;
}

.dot {
  width: 6px;
  height: 6px;
  border-radius: 999px;
  background: var(--color-border-dark);
}

.dot.active {
  background: var(--color-primary-element);
}

.bubble-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  flex-wrap: wrap;
}

.bubble-actions.stacked {
  flex-direction: column;
  align-items: stretch;
}

.bubble-actions.stacked :deep(button) {
  width: 100%;
  justify-content: flex-start;
  white-space: normal;
  text-align: start;
  line-height: 1.35;
  height: auto;
  min-height: 36px;
  overflow-wrap: anywhere;
}

.bubble-actions.stacked :deep(.button-vue__text) {
  display: block;
  width: 100%;
  white-space: normal;
  overflow-wrap: anywhere;
}

/* ── AI consent overlay ───────────────────────────── */
.ai-consent-overlay {
  margin-bottom: 10px;
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 10px;
}

.ai-consent-text {
  margin: 0 0 10px;
  font-size: 13px;
  line-height: 1.5;
  color: var(--color-main-text);
}

.ai-consent-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

/* ── Chat section ─────────────────────────────────── */
.chat-history {
  max-height: min(360px, 50vh);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 8px;
  scroll-behavior: smooth;
  flex: 1;
}

@media (max-width: 480px) {
  .chat-history {
    max-height: none;
    flex: 1;
  }
}

.chat-msg {
  max-width: 85%;
  padding: 7px 10px;
  border-radius: 12px;
  font-size: 13px;
  line-height: 1.45;
  word-break: break-word;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.chat-msg--user {
  align-self: flex-end;
  background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
  color: var(--color-main-text);
  border-bottom-right-radius: 4px;
}

.chat-msg--assistant {
  align-self: flex-start;
  background: var(--color-background-hover);
  color: var(--color-main-text);
  border-bottom-left-radius: 4px;
}

/* RTL: flip user/assistant alignment */
[dir="rtl"] .chat-msg--user {
  align-self: flex-start;
  border-bottom-right-radius: 12px;
  border-bottom-left-radius: 4px;
}

[dir="rtl"] .chat-msg--assistant {
  align-self: flex-end;
  border-bottom-left-radius: 12px;
  border-bottom-right-radius: 4px;
}

.chat-file-link {
  font-size: 11px;
  color: var(--color-primary-element);
  text-decoration: underline;
  text-underline-offset: 2px;
  overflow-wrap: anywhere;
}

.chat-file-link:hover {
  opacity: 0.8;
}

.chat-typing {
  display: flex;
  align-self: flex-start;
  gap: 4px;
  padding: 8px 10px;
  background: var(--color-background-hover);
  border-radius: 12px;
  border-bottom-left-radius: 4px;
}

.typing-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-text-maxcontrast);
  animation: typing-pulse 1.2s ease-in-out infinite;
}

.typing-dot:nth-child(2) {
  animation-delay: 0.2s;
}

.typing-dot:nth-child(3) {
  animation-delay: 0.4s;
}

@keyframes typing-pulse {
  0%, 80%, 100% {
    opacity: 0.3;
    transform: scale(0.85);
  }
  40% {
    opacity: 1;
    transform: scale(1.15);
  }
}

/* ── Quick suggestion chips ────────────────────────── */
.chat-suggestions {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-bottom: 8px;
}

.chat-suggestion-btn {
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text-maxcontrast);
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  transition: border-color 0.12s ease, color 0.12s ease, background 0.12s ease;
  white-space: nowrap;
}

.chat-suggestion-btn:hover:not(:disabled) {
  border-color: var(--color-primary-element);
  color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
}

.chat-suggestion-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

/* ── Chat input row ───────────────────────────────── */
.chat-input-row {
  display: flex;
  gap: 6px;
  align-items: center;
}

.chat-input {
  flex: 1;
  box-sizing: border-box;
  border: 1px solid var(--color-border);
  border-radius: 20px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 7px 12px;
  font: inherit;
  font-size: 13px;
  outline: none;
}

.chat-input:focus {
  border-color: var(--color-primary-element);
}

.chat-input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.chat-send-btn {
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: none;
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.15s ease;
  padding: 0;
}

.chat-send-btn:hover:not(:disabled) {
  opacity: 0.85;
}

.chat-send-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

/* ── Chat clear button ─────────────────────────────── */
.chat-clear-row {
  display: flex;
  justify-content: flex-end;
  margin-top: 4px;
}

.chat-clear-btn {
  background: none;
  border: none;
  padding: 2px 4px;
  font-size: 11px;
  color: var(--color-text-maxcontrast);
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.chat-clear-btn:hover {
  color: var(--color-error);
}

/* ── Mehr Optionen collapsible ────────────────────── */
.mehr-optionen {
  margin-top: 10px;
  border-top: 1px solid var(--color-border);
  padding-top: 8px;
}

.mehr-optionen-toggle {
  display: flex;
  align-items: center;
  gap: 5px;
  background: none;
  border: none;
  padding: 2px 0;
  font-size: 11px;
  color: var(--color-text-maxcontrast);
  cursor: pointer;
  font: inherit;
  font-size: 11px;
}

.mehr-optionen-toggle:hover {
  color: var(--color-main-text);
}

.mehr-optionen-chevron {
  transition: transform 0.18s ease;
}

.mehr-optionen-chevron.open {
  transform: rotate(180deg);
}

.mehr-optionen-body {
  margin-top: 8px;
}

/* ── Accessibility: reduced motion ─────────────────── */
@media (prefers-reduced-motion: reduce) {
  .typing-dot {
    animation: none;
    opacity: 1;
    transform: none;
  }
  .chat-history {
    scroll-behavior: auto;
  }
  .mehr-optionen-chevron {
    transition: none;
  }
}

.bubble-arrow {
  position: absolute;
  inset-inline-end: 22px;
  bottom: -8px;
  width: 16px;
  height: 16px;
  background: var(--color-main-background);
  border-inline-end: 1px solid var(--color-border);
  border-bottom: 1px solid var(--color-border);
  transform: rotate(45deg);
}

@keyframes bubble-appear {
  from {
    opacity: 0;
    transform: translateY(10px) scale(0.96);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

/* EXAM-01: Exam blocked notice */
.exam-blocked-notice {
  text-align: center;
  color: #888;
  padding: 12px;
  font-style: italic;
  border-top: 1px solid var(--color-border);
}

.exam-blocked-icon {
  margin-right: 4px;
}

/* REP-01: Report error button */
.report-error-btn {
  text-align: left;
  color: var(--color-warning-text, #c45911);
  background: transparent;
  border: none;
  padding: 6px 12px;
  cursor: pointer;
  font-size: 13px;
  opacity: 0.8;
  width: 100%;
}

.report-error-btn:hover:not(:disabled) {
  opacity: 1;
  text-decoration: underline;
}

.report-error-btn:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}
</style>
