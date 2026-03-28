<template>
  <div class="virtuprof-bubble" :dir="textDirection">
    <div class="bubble-content">

      <!-- ── Welcome step (opt-in / opt-out) ──────────────── -->
      <template v-if="step.kind === 'welcome'">
        <p v-if="step.title" class="bubble-title">{{ step.title }}</p>
        <p v-if="step.text" class="bubble-text bubble-text--welcome">{{ step.text }}</p>
        <div class="bubble-actions stacked">
          <NcButton
            v-for="action in step.actions"
            :key="action.label"
            :type="action.type === 'start-journey' ? 'primary' : 'secondary'"
            size="small"
            @click="$emit('action', action)">
            {{ action.label }}
          </NcButton>
        </div>
      </template>

      <!-- ── Preset selection ────────────────────────── -->
      <template v-else-if="step.kind === 'preset-select'">
        <p v-if="step.title" class="bubble-title">{{ step.title }}</p>
        <p v-if="step.text" class="bubble-text">{{ step.text }}</p>
        <div class="preset-cards">
          <button
            v-for="preset in step.presets"
            :key="preset.id"
            type="button"
            class="preset-card"
            @click="$emit('action', { type: 'apply-preset', presetId: preset.id })">
            <span class="preset-card__icon">{{ preset.icon }}</span>
            <span class="preset-card__label">{{ preset.label }}</span>
          </button>
          <button
            type="button"
            class="preset-card preset-card--custom"
            @click="$emit('action', { type: 'start-custom-journey' })">
            <span class="preset-card__icon">✏️</span>
            <span class="preset-card__label">{{ vt('Custom profile') }}</span>
          </button>
        </div>
      </template>

      <!-- ── Guided Journey (step-by-step onboarding) ──── -->
      <template v-else-if="step.kind === 'telos-journey'">
        <div class="telos-progress">
          <span
            v-for="i in step.journeyTotalSteps"
            :key="i"
            :class="['telos-dot', { active: i <= step.journeyStepIndex + 1, current: i === step.journeyStepIndex + 1 }]" />
        </div>
        <p class="bubble-title">{{ step.title }}</p>
        <p class="bubble-text bubble-text--journey">{{ step.text }}</p>
        <p v-if="step.privacyHint" class="journey-privacy-hint">
          <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true"><path d="M8 1a7 7 0 100 14A7 7 0 008 1zm0 3a1 1 0 110 2 1 1 0 010-2zm1.5 8h-3v-1h1V8H6.5V7h2v4h1v1z" fill="currentColor" /></svg>
          {{ step.privacyHint }}
        </p>

        <div class="telos-journey-fields">
          <!-- Step: background -->
          <template v-if="step.journeyStepId === 'background'">
            <div class="telos-field">
              <label class="ticket-label" for="journey-role">{{ vt('Current role / background') }}</label>
              <input
                id="journey-role"
                class="ticket-input"
                type="text"
                :value="telosForm.telos?.role || ''"
                :placeholder="vt('e.g. career changer, trainee, admin')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.role', value: $event.target.value })">
            </div>
            <div class="telos-field">
              <label class="ticket-label">{{ vt('Experience level') }}</label>
              <div class="journey-button-group">
                <button
                  v-for="lvl in ['beginner', 'intermediate', 'advanced']"
                  :key="lvl"
                  type="button"
                  :class="['journey-choice-btn', { active: telosForm.telos?.experience_level === lvl }]"
                  @click="$emit('action', { type: 'update-telos-field', field: 'telos.experience_level', value: lvl })">
                  {{ vt(lvl.charAt(0).toUpperCase() + lvl.slice(1)) }}
                </button>
              </div>
            </div>
          </template>

          <!-- Step: goal -->
          <template v-else-if="step.journeyStepId === 'goal'">
            <div class="telos-field">
              <label class="ticket-label" for="journey-cert">{{ vt('Target certification') }}</label>
              <input
                id="journey-cert"
                class="ticket-input"
                type="text"
                :value="telosForm.telos?.target_cert || ''"
                :placeholder="vt('e.g. Network+, CCNA')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.target_cert', value: $event.target.value })">
            </div>
            <div class="telos-field">
              <label class="ticket-label" for="journey-date">{{ vt('Target date') }} <small>({{ vt('optional') }})</small></label>
              <input
                id="journey-date"
                class="ticket-input"
                type="date"
                :value="telosForm.telos?.target_date || ''"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.target_date', value: $event.target.value })">
            </div>
          </template>

          <!-- Step: strengths -->
          <template v-else-if="step.journeyStepId === 'strengths'">
            <div class="telos-field">
              <label class="ticket-label" for="journey-strengths">{{ vt('Strengths') }}</label>
              <textarea
                id="journey-strengths"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.telos?.strengths || ''"
                :placeholder="vt('Comma-separated topics you already know well')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.strengths', value: $event.target.value })" />
            </div>
            <div class="telos-field">
              <label class="ticket-label" for="journey-weaknesses">{{ vt('Weaknesses') }}</label>
              <textarea
                id="journey-weaknesses"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.telos?.weaknesses || ''"
                :placeholder="vt('Comma-separated topics you still struggle with')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.weaknesses', value: $event.target.value })" />
            </div>
          </template>

          <!-- Step: style -->
          <template v-else-if="step.journeyStepId === 'style'">
            <div class="telos-field">
              <label class="ticket-label">{{ vt('Learning style') }}</label>
              <div class="journey-button-group">
                <button
                  v-for="style in ['solo', 'group', 'mixed']"
                  :key="style"
                  type="button"
                  :class="['journey-choice-btn', { active: telosForm.telos?.learning_style === style }]"
                  @click="$emit('action', { type: 'update-telos-field', field: 'telos.learning_style', value: style })">
                  {{ vt(style.charAt(0).toUpperCase() + style.slice(1)) }}
                </button>
              </div>
            </div>
            <div class="telos-field">
              <label class="ticket-label" for="journey-hours">{{ vt('Hours per week') }}</label>
              <input
                id="journey-hours"
                class="ticket-input"
                type="number"
                min="0"
                step="0.5"
                :value="telosForm.telos?.hours_per_week || ''"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.hours_per_week', value: $event.target.value })">
            </div>
          </template>

          <!-- Step: peers -->
          <template v-else-if="step.journeyStepId === 'peers'">
            <div class="telos-field">
              <label class="ticket-label" for="journey-help-offer">{{ vt('I can help with...') }}</label>
              <textarea
                id="journey-help-offer"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.help_offer || ''"
                :placeholder="vt('Comma-separated topics you can explain to others')"
                @input="$emit('action', { type: 'update-telos-field', field: 'help_offer', value: $event.target.value })" />
            </div>
            <div class="telos-field">
              <label class="ticket-label" for="journey-help-wanted">{{ vt('I need help with...') }}</label>
              <textarea
                id="journey-help-wanted"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.help_wanted || ''"
                :placeholder="vt('Comma-separated topics you want support with')"
                @input="$emit('action', { type: 'update-telos-field', field: 'help_wanted', value: $event.target.value })" />
            </div>
            <div class="telos-field">
              <label class="ticket-label">{{ vt('Visibility') }}</label>
              <div class="journey-button-group">
                <button
                  v-for="vis in ['private', 'course', 'public']"
                  :key="vis"
                  type="button"
                  :class="['journey-choice-btn', { active: telosForm.visibility === vis }]"
                  @click="$emit('action', { type: 'update-telos-field', field: 'visibility', value: vis })">
                  {{ vt(vis.charAt(0).toUpperCase() + vis.slice(1)) }}
                </button>
              </div>
            </div>
          </template>

          <!-- Step: extra (optional) -->
          <template v-else-if="step.journeyStepId === 'extra'">
            <div class="telos-field">
              <label class="ticket-label" for="journey-motivation">{{ vt('Motivation') }}</label>
              <textarea
                id="journey-motivation"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.telos?.motivation || ''"
                :placeholder="vt('Why are you learning this right now?')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.motivation', value: $event.target.value })" />
            </div>
            <div class="telos-field">
              <label class="ticket-label" for="journey-notes">{{ vt('Notes') }}</label>
              <textarea
                id="journey-notes"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.telos?.notes || ''"
                :placeholder="vt('Anything else that matters for your learning plan')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.notes', value: $event.target.value })" />
            </div>
          </template>
        </div>

        <p v-if="telosError" class="ticket-error">{{ telosError }}</p>

        <div class="bubble-actions stacked">
          <NcButton
            v-for="action in step.actions"
            :key="action.label"
            :type="action.type === 'journey-back' ? 'tertiary' : 'primary'"
            size="small"
            :disabled="telosSaving"
            @click="$emit('action', action)">
            {{ telosSaving && action.type === 'submit-telos-form' ? vt('Saving...') : action.label }}
          </NcButton>
        </div>
      </template>

      <!-- ── Legacy telos-form (full form fallback) ────── -->
      <template v-else-if="step.kind === 'telos-form'">
        <p v-if="step.title" class="bubble-title">{{ step.title }}</p>
        <p v-if="step.text" class="bubble-text">{{ step.text }}</p>

        <div class="telos-form">
          <div class="telos-grid">
            <div class="telos-field">
              <label class="ticket-label" for="telos-role">{{ vt('Current role / background') }}</label>
              <input
                id="telos-role"
                class="ticket-input"
                type="text"
                :value="telosForm.telos?.role || ''"
                :placeholder="vt('e.g. career changer, trainee, admin')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.role', value: $event.target.value })">
            </div>

            <div class="telos-field">
              <label class="ticket-label" for="telos-experience">{{ vt('Experience level') }}</label>
              <select
                id="telos-experience"
                class="ticket-select"
                :value="telosForm.telos?.experience_level || 'beginner'"
                @change="$emit('action', { type: 'update-telos-field', field: 'telos.experience_level', value: $event.target.value })">
                <option value="beginner">{{ vt('Beginner') }}</option>
                <option value="intermediate">{{ vt('Intermediate') }}</option>
                <option value="advanced">{{ vt('Advanced') }}</option>
              </select>
            </div>

            <div class="telos-field">
              <label class="ticket-label" for="telos-target-cert">{{ vt('Target certification') }}</label>
              <input
                id="telos-target-cert"
                class="ticket-input"
                type="text"
                :value="telosForm.telos?.target_cert || ''"
                :placeholder="vt('e.g. Network+, CCNA')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.target_cert', value: $event.target.value })">
            </div>

            <div class="telos-field">
              <label class="ticket-label" for="telos-target-date">{{ vt('Target date') }}</label>
              <input
                id="telos-target-date"
                class="ticket-input"
                type="date"
                :value="telosForm.telos?.target_date || ''"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.target_date', value: $event.target.value })">
            </div>

            <div class="telos-field">
              <label class="ticket-label" for="telos-hours">{{ vt('Hours per week') }}</label>
              <input
                id="telos-hours"
                class="ticket-input"
                type="number"
                min="0"
                step="0.5"
                :value="telosForm.telos?.hours_per_week || ''"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.hours_per_week', value: $event.target.value })">
            </div>

            <div class="telos-field">
              <label class="ticket-label" for="telos-learning-style">{{ vt('Learning style') }}</label>
              <select
                id="telos-learning-style"
                class="ticket-select"
                :value="telosForm.telos?.learning_style || 'solo'"
                @change="$emit('action', { type: 'update-telos-field', field: 'telos.learning_style', value: $event.target.value })">
                <option value="solo">{{ vt('Solo') }}</option>
                <option value="group">{{ vt('Group') }}</option>
                <option value="mixed">{{ vt('Mixed') }}</option>
              </select>
            </div>

            <div class="telos-field telos-field--full">
              <label class="ticket-label" for="telos-strengths">{{ vt('Strengths') }}</label>
              <textarea
                id="telos-strengths"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.telos?.strengths || ''"
                :placeholder="vt('Comma-separated topics you already know well')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.strengths', value: $event.target.value })" />
            </div>

            <div class="telos-field telos-field--full">
              <label class="ticket-label" for="telos-weaknesses">{{ vt('Weaknesses') }}</label>
              <textarea
                id="telos-weaknesses"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.telos?.weaknesses || ''"
                :placeholder="vt('Comma-separated topics you still struggle with')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.weaknesses', value: $event.target.value })" />
            </div>

            <div class="telos-field telos-field--full">
              <label class="ticket-label" for="telos-motivation">{{ vt('Motivation') }}</label>
              <textarea
                id="telos-motivation"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.telos?.motivation || ''"
                :placeholder="vt('Why are you learning this right now?')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.motivation', value: $event.target.value })" />
            </div>

            <div class="telos-field telos-field--full">
              <label class="ticket-label" for="telos-notes">{{ vt('Notes') }}</label>
              <textarea
                id="telos-notes"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.telos?.notes || ''"
                :placeholder="vt('Anything else that matters for your learning plan')"
                @input="$emit('action', { type: 'update-telos-field', field: 'telos.notes', value: $event.target.value })" />
            </div>

            <div class="telos-field telos-field--full">
              <label class="ticket-label" for="telos-bio">{{ vt('Short bio') }}</label>
              <textarea
                id="telos-bio"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.bio || ''"
                :placeholder="vt('Optional short introduction for peers and instructors')"
                @input="$emit('action', { type: 'update-telos-field', field: 'bio', value: $event.target.value })" />
            </div>

            <div class="telos-field telos-field--full">
              <label class="ticket-label" for="telos-help-offer">{{ vt('I can help with...') }}</label>
              <textarea
                id="telos-help-offer"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.help_offer || ''"
                :placeholder="vt('Comma-separated topics you can explain to others')"
                @input="$emit('action', { type: 'update-telos-field', field: 'help_offer', value: $event.target.value })" />
            </div>

            <div class="telos-field telos-field--full">
              <label class="ticket-label" for="telos-help-wanted">{{ vt('I need help with...') }}</label>
              <textarea
                id="telos-help-wanted"
                class="ticket-textarea"
                rows="2"
                :value="telosForm.help_wanted || ''"
                :placeholder="vt('Comma-separated topics you want support with')"
                @input="$emit('action', { type: 'update-telos-field', field: 'help_wanted', value: $event.target.value })" />
            </div>

            <div class="telos-field">
              <label class="ticket-label" for="telos-visibility">{{ vt('Visibility') }}</label>
              <select
                id="telos-visibility"
                class="ticket-select"
                :value="telosForm.visibility || 'private'"
                @change="$emit('action', { type: 'update-telos-field', field: 'visibility', value: $event.target.value })">
                <option value="private">{{ vt('Private') }}</option>
                <option value="course">{{ vt('Course') }}</option>
                <option value="public">{{ vt('Public') }}</option>
              </select>
            </div>
          </div>

          <p v-if="telosError" class="ticket-error">{{ telosError }}</p>
          <p v-if="telosSaved" class="ticket-success">{{ vt('Learning profile saved.') }}</p>
        </div>

        <div class="bubble-actions stacked">
          <NcButton
            type="primary"
            size="small"
            :disabled="telosSaving"
            @click="$emit('action', { type: 'submit-telos-form' })">
            {{ telosSaving ? vt('Saving...') : vt('Save learning profile') }}
          </NcButton>
          <NcButton
            type="secondary"
            size="small"
            :disabled="telosSaving"
            @click="$emit('action', { type: 'postpone-telos-onboarding' })">
            {{ vt('Later') }}
          </NcButton>
        </div>
      </template>

      <!-- ── AI-first layout (aiEnabled=true) ──────────────────── -->
      <template v-else-if="aiEnabled">
        <!-- AI consent dialog: shown before first chat use -->
        <div v-if="showConsentDialog" class="ai-consent-overlay" role="dialog" aria-modal="true" :aria-label="vt('AI consent required')">
          <p class="ai-consent-text">{{ vt('Your question will be sent to Google Gemini (an external AI service). Do you agree?') }}</p>
          <div class="ai-consent-actions">
            <NcButton type="primary" size="small" @click="$emit('consent-accept')">{{ vt('I agree') }}</NcButton>
            <NcButton type="secondary" size="small" @click="$emit('consent-decline')">{{ vt('Cancel') }}</NcButton>
          </div>
        </div>

        <div v-if="step.showIntroInline && (step.title || step.text)" class="chat-step-intro">
          <p v-if="step.title" class="bubble-title">{{ step.title }}</p>
          <p v-if="step.text" class="bubble-text">{{ step.text }}</p>
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
            <div class="chat-msg-body">
              <span class="chat-msg-text">{{ msg.text }}</span>
              <button
                v-if="msg.role === 'assistant' && supportsSpeechSynthesis && msg.text"
                type="button"
                class="chat-audio-btn"
                :aria-label="vt('Read aloud')"
                :title="vt('Read aloud')"
                @click="speakText(msg.text)">
                <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
                  <path d="M2 6h3l3-3v10L5 10H2z" fill="currentColor" />
                  <path d="M10.5 5.2a3.4 3.4 0 010 5.6M12.2 3.5a5.8 5.8 0 010 9" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
                </svg>
              </button>
            </div>
            <a
              v-if="msg.filePath"
              :href="ncFileUrl(msg.filePath)"
              target="_blank"
              rel="noopener noreferrer"
              class="chat-file-link">
              {{ msg.filePath }}
            </a>
            <details v-if="msg.sources && msg.sources.length" class="chat-rag-sources">
              <summary class="rag-sources-toggle">
                {{ vt('Sources') }} ({{ msg.sources.length }})
              </summary>
              <ul class="rag-sources-list">
                <li v-for="(src, sIdx) in msg.sources" :key="sIdx" class="rag-source-item">
                  <span class="rag-source-file">{{ src.source_file }}</span>
                  <span v-if="src.chapter" class="rag-source-chapter"> &mdash; {{ vt('Ch.') }} {{ src.chapter }}</span>
                </li>
              </ul>
            </details>
          </div>
          <div v-if="chatLoading" class="chat-typing" aria-label="VirtuProf is typing">
            <span class="typing-dot" />
            <span class="typing-dot" />
            <span class="typing-dot" />
          </div>
        </div>

        <!-- Quick suggestion chips (above input) -->
        <div v-if="!examBlocked && !step.disableSuggestions" class="chat-suggestions" role="group" :aria-label="vt('Quick suggestions')">
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
          <button
            v-if="sttEnabled && supportsSpeechRecognition"
            type="button"
            class="chat-mic-btn"
            :class="{ active: isListening }"
            :disabled="chatLoading"
            :aria-label="isListening ? vt('Listening...') : vt('Hold to talk')"
            :title="isListening ? vt('Listening...') : vt('Hold to talk')"
            @mousedown.prevent="startListening"
            @mouseup.prevent="stopListening"
            @mouseleave="stopListening"
            @touchstart.prevent="startListening"
            @touchend.prevent="stopListening"
            @touchcancel.prevent="stopListening">
            <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
              <path d="M8 2.5a2 2 0 00-2 2V8a2 2 0 004 0V4.5a2 2 0 00-2-2z" fill="currentColor" />
              <path d="M4 7.5a4 4 0 008 0M8 11.5v2.5M5.5 14h5" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
            </svg>
          </button>
          <input
            ref="chatInput"
            v-model="chatInput"
            class="chat-input"
            type="text"
            :placeholder="step.chatPlaceholder || vt('Ask VirtuProf...')"
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
        <div v-if="isListening" class="chat-listening" role="status">
          <span class="chat-listening-dot" />
          {{ vt('Listening...') }}
        </div>
        <p v-else-if="speechError" class="chat-voice-error">{{ speechError }}</p>

        <div
          v-if="step.renderActionsInline && step.actions && step.actions.length"
          class="bubble-actions"
          :class="{ stacked: step.actionLayout === 'stacked' || step.actions.length > 3 }">
          <NcButton
            v-for="action in step.actions"
            :key="action.label"
            type="secondary"
            size="small"
            @click="$emit('action', action)">
            {{ action.label }}
          </NcButton>
        </div>

        <!-- Actions row: report + clear on same line -->
        <div class="chat-actions-row">
          <button
            v-if="hasQuestionContext && !examBlocked"
            type="button"
            class="chat-clear-btn"
            :disabled="chatLoading"
            @click="$emit('report-error')">
            {{ vt('Report error') }}
          </button>
          <button
            v-if="chatMessages.length > 0"
            type="button"
            class="chat-clear-btn"
            @click="$emit('action', { type: 'clear-chat-history' })">
            {{ vt('Clear chat history') }}
          </button>
        </div>

        <!-- Collapsible "Mehr Optionen" section -->
        <div v-if="!step.hideMoreOptions" class="mehr-optionen">
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
    telosForm: {
      type: Object,
      default: () => ({
        telos: {},
        bio: '',
        help_offer: '',
        help_wanted: '',
        visibility: 'private',
      }),
    },
    telosSaving: {
      type: Boolean,
      default: false,
    },
    telosError: {
      type: String,
      default: '',
    },
    telosSaved: {
      type: Boolean,
      default: false,
    },
    ttsEnabled: {
      type: Boolean,
      default: false,
    },
    sttEnabled: {
      type: Boolean,
      default: false,
    },
    voiceLang: {
      type: String,
      default: '',
    },
  },
  data() {
    return {
      chatInput: '',
      moreOptionsOpen: false,
      supportsSpeechSynthesis: false,
      supportsSpeechRecognition: false,
      speechRecognition: null,
      isListening: false,
      speechError: '',
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
  mounted() {
    if (typeof window === 'undefined') {
      return
    }
    this.supportsSpeechSynthesis = 'speechSynthesis' in window
    this.supportsSpeechRecognition = !!(window.SpeechRecognition || window.webkitSpeechRecognition)
    if (this.supportsSpeechRecognition) {
      this.setupSpeechRecognition()
    }
  },
  beforeDestroy() {
    this.stopListening()
    if (typeof window !== 'undefined' && window.speechSynthesis) {
      window.speechSynthesis.cancel()
    }
  },
  watch: {
    chatMessages(newMessages) {
      this.$nextTick(() => {
        this.scrollChatToBottom()
      })
      const lastMessage = Array.isArray(newMessages) ? newMessages[newMessages.length - 1] : null
      if (this.ttsEnabled && this.supportsSpeechSynthesis && lastMessage?.role === 'assistant' && lastMessage?.speakable === true) {
        this.speakText(lastMessage.text)
      }
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
    voiceLang() {
      this.updateSpeechRecognitionLanguage()
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
    resolvedVoiceLang() {
      if (this.voiceLang) {
        return this.voiceLang
      }
      if (typeof navigator !== 'undefined' && navigator.language) {
        return navigator.language
      }
      return 'de-DE'
    },
    speakText(text) {
      if (!this.supportsSpeechSynthesis || typeof window === 'undefined' || !window.speechSynthesis) {
        return
      }
      const normalized = String(text || '').trim()
      if (!normalized) {
        return
      }

      window.speechSynthesis.cancel()

      const utterance = new window.SpeechSynthesisUtterance(normalized)
      utterance.lang = this.resolvedVoiceLang()
      utterance.rate = 0.95
      utterance.pitch = 1

      const preferredLang = utterance.lang.split('-')[0].toLowerCase()
      const voices = window.speechSynthesis.getVoices()
      const preferredVoice = voices.find((voice) => {
        const voiceLang = String(voice.lang || '').toLowerCase()
        return voiceLang.startsWith(preferredLang) && String(voice.name || '').includes('Google')
      }) || voices.find((voice) => String(voice.lang || '').toLowerCase().startsWith(preferredLang))

      if (preferredVoice) {
        utterance.voice = preferredVoice
      }

      window.speechSynthesis.speak(utterance)
    },
    setupSpeechRecognition() {
      if (typeof window === 'undefined') {
        return
      }
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
      if (!SpeechRecognition) {
        this.supportsSpeechRecognition = false
        return
      }

      const recognition = new SpeechRecognition()
      recognition.lang = this.resolvedVoiceLang()
      recognition.interimResults = false
      recognition.maxAlternatives = 1
      recognition.onresult = (event) => {
        const transcript = event?.results?.[0]?.[0]?.transcript || ''
        if (transcript) {
          const current = this.chatInput.trim()
          this.chatInput = current ? `${current} ${transcript.trim()}` : transcript.trim()
          this.$nextTick(() => this.$refs.chatInput?.focus())
        }
      }
      recognition.onerror = () => {
        this.speechError = this.vt('Voice input is currently unavailable.')
        this.isListening = false
      }
      recognition.onend = () => {
        this.isListening = false
      }
      this.speechRecognition = recognition
    },
    updateSpeechRecognitionLanguage() {
      if (this.speechRecognition) {
        this.speechRecognition.lang = this.resolvedVoiceLang()
      }
    },
    startListening() {
      if (!this.sttEnabled || !this.supportsSpeechRecognition || this.chatLoading) {
        return
      }
      if (!this.speechRecognition) {
        this.setupSpeechRecognition()
      }
      if (!this.speechRecognition || this.isListening) {
        return
      }

      this.speechError = ''
      this.updateSpeechRecognitionLanguage()
      try {
        this.isListening = true
        this.speechRecognition.start()
      } catch (e) {
        this.isListening = false
        this.speechError = this.vt('Voice input is currently unavailable.')
      }
    },
    stopListening() {
      if (!this.speechRecognition) {
        return
      }
      try {
        this.speechRecognition.stop()
      } catch (e) {
        this.isListening = false
      }
    },
    scrollChatToBottom() {
      const el = this.$refs.chatHistory
      if (el) {
        el.scrollTop = el.scrollHeight
      }
    },
    ncFileUrl(path) {
      // Build a Nextcloud Files URL for a path like /Learning/Lernplan.md
      return '/apps/files/?dir=' + encodeURIComponent(path.substring(0, path.lastIndexOf('/'))) + '&scrollto=' + encodeURIComponent(path.split('/').pop())
    },
  },
}
</script>

<style scoped>
.virtuprof-bubble {
  position: relative;
  inset: auto;
  width: 100%;
  height: 100%;
  min-height: 0;
  max-height: none;
  overflow: hidden;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 22px;
  box-shadow: 0 20px 42px rgba(15, 23, 42, 0.08);
  padding: 16px;
  animation: bubble-appear 0.25s ease-out;
  display: flex;
  flex-direction: column;
}

@media (max-width: 480px) {
  .virtuprof-bubble {
    min-height: 420px;
    border-radius: 18px;
    padding: 14px;
  }
}

.bubble-content {
  position: relative;
  z-index: 1;
  padding-top: 2px;
  display: flex;
  flex: 1;
  min-height: 0;
  flex-direction: column;
  overflow-y: auto;
}

/* bubble-toolbar + close-btn removed — dismiss via Close button in FAQ menu */

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
  white-space: pre-wrap;
}

.chat-step-intro {
  margin-bottom: 10px;
}

.ticket-form,
.ticket-list,
.invite-list,
.telos-form {
  display: grid;
  gap: 8px;
  margin-bottom: 12px;
}

.telos-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.telos-field {
  display: grid;
  gap: 4px;
}

.telos-field--full {
  grid-column: 1 / -1;
}

@media (max-width: 520px) {
  .telos-grid {
    grid-template-columns: 1fr;
  }

  .telos-field--full {
    grid-column: auto;
  }
}

/* Preset cards */
.preset-cards {
  display: grid;
  gap: 8px;
  margin: 12px 0;
}

.preset-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border: 2px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-main-background);
  cursor: pointer;
  text-align: left;
  font: inherit;
  color: var(--color-main-text);
  transition: border-color 0.15s, background 0.15s, transform 0.15s;
}

.preset-card:hover {
  border-color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 6%, var(--color-main-background));
  transform: translateY(-1px);
}

.preset-card--custom {
  border-style: dashed;
}

.preset-card__icon {
  font-size: 24px;
  flex-shrink: 0;
  width: 32px;
  text-align: center;
}

.preset-card__label {
  font-size: 14px;
  font-weight: 500;
  line-height: 1.4;
}

/* Journey (step-by-step onboarding) */
.telos-progress {
  display: flex;
  gap: 6px;
  justify-content: center;
  margin-bottom: 14px;
}

.telos-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-border);
  transition: background 0.2s, transform 0.2s;
}

.telos-dot.active {
  background: var(--color-primary-element);
}

.telos-dot.current {
  transform: scale(1.3);
  background: var(--color-primary-element);
}

.telos-journey-fields {
  display: grid;
  gap: 12px;
  margin: 8px 0 12px;
}

.journey-button-group {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.journey-choice-btn {
  flex: 1;
  min-width: 80px;
  padding: 10px 12px;
  border: 2px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-main-background);
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  text-align: center;
  color: var(--color-main-text);
  transition: border-color 0.15s, background 0.15s, color 0.15s;
}

.journey-choice-btn:hover {
  border-color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 8%, var(--color-main-background));
}

.journey-choice-btn.active {
  border-color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 14%, var(--color-main-background));
  color: var(--color-primary-element);
  font-weight: 600;
}

.bubble-text--welcome,
.bubble-text--journey {
  white-space: pre-line;
  line-height: 1.5;
}

.journey-privacy-hint {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  margin: 4px 0 8px;
  padding: 8px 10px;
  border-radius: 8px;
  background: color-mix(in srgb, var(--color-primary-element) 6%, var(--color-main-background));
  font-size: 11.5px;
  line-height: 1.5;
  color: var(--color-text-maxcontrast);
}

.journey-privacy-hint svg {
  flex-shrink: 0;
  margin-top: 2px;
  color: var(--color-primary-element);
  opacity: 0.7;
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
  line-height: 1.25;
  height: auto;
  min-height: 28px;
  padding: 6px 10px;
  font-size: 13px;
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
  max-height: none;
  min-height: 180px;
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

.chat-msg-body {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.chat-msg-text {
  flex: 1;
  min-width: 0;
  white-space: pre-wrap;
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

.chat-rag-sources {
  margin-top: 4px;
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
}
.rag-sources-toggle {
  cursor: pointer;
  user-select: none;
  opacity: 0.7;
}
.rag-sources-toggle:hover {
  opacity: 1;
}
.rag-sources-list {
  margin: 4px 0 0 0;
  padding-left: 16px;
  list-style: disc;
}
.rag-source-item {
  margin: 2px 0;
  word-break: break-word;
}
.rag-source-file {
  font-weight: 500;
}
.rag-source-chapter {
  font-style: italic;
}

.chat-audio-btn,
.chat-mic-btn {
  flex-shrink: 0;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text-maxcontrast);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
  transition: border-color 0.12s ease, color 0.12s ease, background 0.12s ease, transform 0.12s ease;
}

.chat-audio-btn:hover,
.chat-mic-btn:hover {
  border-color: var(--color-primary-element);
  color: var(--color-primary-element);
}

.chat-mic-btn.active {
  border-color: var(--color-error);
  color: var(--color-error);
  background: color-mix(in srgb, var(--color-error) 16%, transparent);
  animation: virtuprof-pulse 0.9s ease-in-out infinite;
}

.chat-audio-btn:disabled,
.chat-mic-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
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

.chat-listening,
.chat-voice-error {
  margin: 6px 0 0;
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}

.chat-listening {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.chat-listening-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-error);
  box-shadow: 0 0 0 0 rgba(235, 87, 87, 0.35);
  animation: virtuprof-pulse-dot 1s ease-in-out infinite;
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

@keyframes virtuprof-pulse {
  0%, 100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.04);
  }
}

@keyframes virtuprof-pulse-dot {
  0% {
    box-shadow: 0 0 0 0 rgba(235, 87, 87, 0.35);
  }
  70% {
    box-shadow: 0 0 0 8px rgba(235, 87, 87, 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(235, 87, 87, 0);
  }
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
  display: grid;
  gap: 10px;
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
  display: none;
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
.chat-actions-row {
  display: flex;
  justify-content: flex-start;
  flex-wrap: wrap;
  gap: 12px;
  padding: 6px 0 0;
}

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
