<template>
  <div class="virtuprof-bubble">
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
      <p v-if="step.title" class="bubble-title">{{ step.title }}</p>
      <p v-if="step.text" class="bubble-text">{{ step.text }}</p>

      <div v-if="step.kind === 'ticket-form'" class="ticket-form">
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
            <span class="ticket-status" :class="'status-' + ticket.status">{{ ticket.status }}</span>
          </div>
          <div class="ticket-meta">{{ formatTimestamp(ticket.updated_at || ticket.created_at) }}</div>
          <div class="ticket-message">{{ ticket.message }}</div>
          <div v-if="ticket.answer_text" class="ticket-answer">
            <strong>{{ vt('Answer') }}:</strong> {{ ticket.answer_text }}
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
  },
  computed: {
    effectiveLanguage() {
      return this.language || 'de'
    },
    languageOptions() {
      return VIRTUPROF_LANGUAGE_OPTIONS
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
        return new Date(Number(value) * 1000).toLocaleString()
      } catch (e) {
        return String(value)
      }
    },
  },
}
</script>

<style scoped>
.virtuprof-bubble {
  position: absolute;
  right: 0;
  bottom: 96px;
  width: min(320px, calc(100vw - 48px));
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 14px;
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.18);
  padding: 16px;
  animation: bubble-appear 0.25s ease-out;
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
.ticket-list {
  display: grid;
  gap: 8px;
  margin-bottom: 12px;
}

.ticket-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
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
  text-align: left;
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

.bubble-arrow {
  position: absolute;
  right: 22px;
  bottom: -8px;
  width: 16px;
  height: 16px;
  background: var(--color-main-background);
  border-right: 1px solid var(--color-border);
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
</style>
