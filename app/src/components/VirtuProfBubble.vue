<template>
  <div class="virtuprof-bubble">
    <div class="bubble-content">
      <p v-if="step.title" class="bubble-title">{{ step.title }}</p>
      <p v-if="step.text" class="bubble-text">{{ step.text }}</p>

      <div v-if="step.kind === 'ticket-form'" class="ticket-form">
        <label class="ticket-label" for="virtuprof-ticket-subject">{{ t('learning', 'Subject') }}</label>
        <input
          id="virtuprof-ticket-subject"
          class="ticket-input"
          type="text"
          :value="ticketSubject"
          :placeholder="t('learning', 'Short summary')"
          @input="$emit('update:ticketSubject', $event.target.value)">
        <label class="ticket-label" for="virtuprof-ticket-message">{{ t('learning', 'Message') }}</label>
        <textarea
          id="virtuprof-ticket-message"
          class="ticket-textarea"
          rows="5"
          :value="ticketDraft"
          :placeholder="step.placeholder || t('learning', 'Describe your question or problem...')"
          @input="$emit('update:ticketDraft', $event.target.value)" />
        <p v-if="ticketError" class="ticket-error">{{ ticketError }}</p>
        <p v-if="ticketSuccess" class="ticket-success">{{ ticketSuccess }}</p>
      </div>

      <div v-if="step.kind === 'ticket-list'" class="ticket-list">
        <div v-if="tickets.length === 0" class="ticket-empty">
          {{ t('learning', 'No support tickets yet.') }}
        </div>
        <div v-for="ticket in tickets" :key="ticket.id" class="ticket-item">
          <div class="ticket-header">
            <strong>{{ ticket.subject }}</strong>
            <span class="ticket-status" :class="'status-' + ticket.status">{{ ticket.status }}</span>
          </div>
          <div class="ticket-meta">{{ formatTimestamp(ticket.updated_at || ticket.created_at) }}</div>
          <div class="ticket-message">{{ ticket.message }}</div>
          <div v-if="ticket.answer_text" class="ticket-answer">
            <strong>{{ t('learning', 'Answer') }}:</strong> {{ ticket.answer_text }}
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
            {{ t('learning', 'Next') }}
          </NcButton>
          <NcButton
            v-else
            type="secondary"
            size="small"
            @click="$emit('dismiss')">
            {{ t('learning', 'Ok, got it') }}
          </NcButton>
        </template>
      </div>
    </div>
    <div class="bubble-arrow" />
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

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
  },
  methods: {
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
  justify-content: flex-start;
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
