<template>
  <section
    class="virtuprof-fullscreen"
    :dir="textDirection"
    role="dialog"
    aria-modal="true"
    :aria-label="t('learning', 'VirtuProf Fullscreen')">
    <div v-if="showConsentDialog" class="ai-consent-overlay" role="dialog" aria-modal="true" :aria-label="consentData.title || t('learning', 'AI consent required')">
      <h4 v-if="consentData.title" class="ai-consent-title">{{ consentData.title }}</h4>
      <!-- eslint-disable-next-line vue/no-v-html — static JSON bundled with app, not user input -->
      <div v-if="consentData.body_html" v-html="consentData.body_html" class="ai-consent-body"></div>
      <p class="ai-consent-privacy-link">
        <a href="#/settings/privacy">{{ t('learning', 'Mehr zum Datenschutz') }}</a>
      </p>
      <div class="ai-consent-actions">
        <button type="button" class="header-action header-action--primary" @click="$emit('consent-accept')">
          {{ consentData.accept_label || t('learning', 'Akzeptieren') }}
        </button>
        <button type="button" class="header-action" @click="$emit('consent-decline')">
          {{ consentData.decline_label || t('learning', 'Ablehnen') }}
        </button>
      </div>
    </div>

    <div class="virtuprof-fullscreen__surface">
      <header
        class="virtuprof-fullscreen__header"
        @touchstart.passive="onHeaderTouchStart"
        @touchend.passive="onHeaderTouchEnd">
        <div class="virtuprof-fullscreen__heading">
          <p class="virtuprof-fullscreen__eyebrow">{{ t('learning', 'Erklärbot') }}</p>
          <h2 class="virtuprof-fullscreen__title">{{ title || t('learning', 'VirtuProf') }}</h2>
          <p v-if="subtitle" class="virtuprof-fullscreen__subtitle">{{ subtitle }}</p>
        </div>
        <div class="virtuprof-fullscreen__header-actions">
          <button
            v-if="isMobile"
            type="button"
            class="header-action"
            aria-controls="virtuprof-fullscreen-context"
            :aria-expanded="mobileContextOpen ? 'true' : 'false'"
            @click="mobileContextOpen = !mobileContextOpen">
            {{ mobileContextOpen ? t('learning', 'Chat') : t('learning', 'Kontext') }}
          </button>
          <button
            type="button"
            class="header-action header-action--close"
            :aria-label="t('learning', 'Vollbild schließen')"
            @click="$emit('close')">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      </header>

      <div class="virtuprof-fullscreen__body">
        <section class="virtuprof-fullscreen__chat-column">
          <div v-if="showIntroCard" class="virtuprof-fullscreen__intro">
            <p v-if="step && step.title" class="virtuprof-fullscreen__intro-title">{{ step.title }}</p>
            <p v-if="step && step.text" class="virtuprof-fullscreen__intro-text">{{ step.text }}</p>
          </div>

          <div
            ref="chatHistory"
            class="virtuprof-fullscreen__history"
            role="log"
            aria-live="polite"
            :aria-label="t('learning', 'VirtuProf chat')">
            <div v-if="!aiEnabled" class="virtuprof-fullscreen__empty-state">
              <h3>{{ t('learning', 'AI chat is currently disabled') }}</h3>
              <p>{{ t('learning', 'VirtuProf kann dich weiterhin in der Sidebar begleiten, aber Chat-Antworten sind nicht verfügbar, bis der Admin die KI wieder aktiviert.') }}</p>
            </div>
            <div v-else-if="chatMessages.length === 0" class="virtuprof-fullscreen__empty-state">
              <h3>{{ t('learning', 'Frag mich alles zu deinem Lernstoff.') }}</h3>
              <p>{{ t('learning', 'Nutze die Vorschläge unten oder schreibe direkt eine Frage. Der Verlauf bleibt mit der Sidebar synchron.') }}</p>
            </div>

            <article
              v-for="(msg, idx) in chatMessages"
              :key="idx"
              class="virtuprof-fullscreen__message"
              :class="msg.role === 'user' ? 'virtuprof-fullscreen__message--user' : 'virtuprof-fullscreen__message--assistant'">
              <div class="virtuprof-fullscreen__message-meta">
                <span>{{ msg.role === 'user' ? t('learning', 'Du') : t('learning', 'VirtuProf') }}</span>
              </div>
              <div class="virtuprof-fullscreen__message-body">
                {{ msg.text }}
              </div>
              <a
                v-if="msg.filePath"
                :href="ncFileUrl(msg.filePath)"
                target="_blank"
                rel="noopener noreferrer"
                class="virtuprof-fullscreen__file-link">
                {{ msg.filePath }}
              </a>
            </article>

            <div v-if="chatLoading" class="virtuprof-fullscreen__typing" :aria-label="t('learning', 'VirtuProf tippt')">
              <span class="typing-dot" />
              <span class="typing-dot" />
              <span class="typing-dot" />
            </div>
          </div>

          <div v-if="aiEnabled && !examBlocked" class="virtuprof-fullscreen__suggestions" :aria-label="t('learning', 'Schnellvorschläge')" role="group">
            <button
              v-for="suggestion in quickSuggestions"
              :key="suggestion.key"
              type="button"
              class="virtuprof-fullscreen__suggestion"
              :disabled="chatLoading"
              @click="sendSuggestion(suggestion.text)">
              {{ suggestion.label }}
            </button>
          </div>

          <div v-if="examBlocked" class="virtuprof-fullscreen__exam-blocked" role="status">
            <span aria-hidden="true">&#128274;</span>
            {{ t('learning', 'VirtuProf-Chat ist im Prüfungsmodus nicht verfügbar.') }}
          </div>

          <form v-if="aiEnabled && !examBlocked" class="virtuprof-fullscreen__composer" @submit.prevent="sendChat">
            <textarea
              ref="composer"
              v-model="draft"
              class="virtuprof-fullscreen__textarea"
              :placeholder="inputPlaceholder"
              :disabled="chatLoading"
              rows="1"
              maxlength="500"
              @input="autosizeComposer"
              @keydown.enter.exact.prevent="sendChat" />
            <button
              type="submit"
              class="virtuprof-fullscreen__send"
              :disabled="chatLoading || !draft.trim()">
              {{ t('learning', 'Senden') }}
            </button>
          </form>
        </section>

        <aside
          id="virtuprof-fullscreen-context"
          class="virtuprof-fullscreen__context"
          :class="{ 'virtuprof-fullscreen__context--mobile-open': mobileContextOpen }">
          <div class="virtuprof-fullscreen__context-section">
            <h3>{{ t('learning', 'Kontext') }}</h3>
            <dl class="virtuprof-fullscreen__context-list">
              <template v-for="entry in contextEntries" :key="entry.key">
                <dt>{{ entry.label }}</dt>
                <dd>{{ entry.value }}</dd>
              </template>
            </dl>
          </div>

          <div class="virtuprof-fullscreen__context-section">
            <div class="virtuprof-fullscreen__context-heading">
              <h3>{{ t('learning', 'RAG-Quellen') }}</h3>
              <button
                v-if="hasQuestionContext"
                type="button"
                class="virtuprof-fullscreen__link-btn"
                :disabled="chatLoading"
                @click="$emit('report-error')">
                {{ t('learning', 'Fehler melden') }}
              </button>
            </div>

            <div v-if="latestSources.length === 0" class="virtuprof-fullscreen__sources-empty">
              {{ t('learning', 'Noch keine Quellen in der letzten Antwort.') }}
            </div>

            <ul v-else class="virtuprof-fullscreen__sources-list">
              <li v-for="(source, index) in latestSources" :key="index" class="virtuprof-fullscreen__source-item">
                <span class="virtuprof-fullscreen__source-file">{{ source.source_file }}</span>
                <span v-if="source.chapter" class="virtuprof-fullscreen__source-meta">{{ t('learning', 'Kapitel') }} {{ source.chapter }}</span>
              </li>
            </ul>
          </div>
        </aside>
      </div>

      <button
        v-if="isMobile && mobileContextOpen"
        type="button"
        class="virtuprof-fullscreen__mobile-backdrop"
        :aria-label="t('learning', 'Kontextfeld schließen')"
        @click="mobileContextOpen = false" />
    </div>
  </section>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { translateVirtuProf } from '../utils/virtuprof-i18n.js'

export default {
  name: 'VirtuProfFullscreen',
  props: {
    title: {
      type: String,
      default: '',
    },
    subtitle: {
      type: String,
      default: '',
    },
    language: {
      type: String,
      default: '',
    },
    step: {
      type: Object,
      default: null,
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
    consentData: {
      type: Object,
      default: () => ({}),
    },
    examBlocked: {
      type: Boolean,
      default: false,
    },
    hasQuestionContext: {
      type: Boolean,
      default: false,
    },
    currentContext: {
      type: Object,
      default: () => ({}),
    },
  },
  data() {
    return {
      draft: '',
      mobileContextOpen: false,
      headerTouchY: 0,
      isMobile: false,
    }
  },
  computed: {
    effectiveLanguage() {
      return this.language || 'de'
    },
    textDirection() {
      return this.effectiveLanguage === 'ar' ? 'rtl' : 'ltr'
    },
    inputPlaceholder() {
      return this.step?.chatPlaceholder || this.vt('Ask VirtuProf...')
    },
    showIntroCard() {
      return Boolean(this.step && (this.step.title || this.step.text) && this.chatMessages.length === 0)
    },
    latestSources() {
      const assistantMessages = [...this.chatMessages].reverse()
      const latestWithSources = assistantMessages.find((message) => Array.isArray(message.sources) && message.sources.length > 0)
      return latestWithSources?.sources || []
    },
    contextEntries() {
      const entries = []
      const area = String(this.currentContext?.area || '').trim()
      const courseTitle = String(this.currentContext?.courseTitle || '').trim()
      const poolName = String(this.currentContext?.poolName || '').trim()
      const questionId = this.currentContext?.questionContext?.questionId || this.currentContext?.questionId || null

      if (courseTitle) {
        entries.push({ key: 'course', label: t('learning', 'Kurs'), value: courseTitle })
      }
      if (poolName) {
        entries.push({ key: 'pool', label: t('learning', 'Pool'), value: poolName })
      }
      if (area) {
        entries.push({ key: 'area', label: t('learning', 'Bereich'), value: area })
      }
      if (questionId) {
        entries.push({ key: 'question', label: t('learning', 'Frage'), value: `#${questionId}` })
      }

      if (entries.length === 0) {
        entries.push({ key: 'default', label: t('learning', 'Status'), value: t('learning', 'Kein zusätzlicher Kontext aktiv') })
      }

      return entries
    },
    quickSuggestions() {
      return [
        { key: 'was-ist', label: this.vt('What is...?'), text: this.vt('What is...?') },
        { key: 'erklaere', label: this.vt('Explain my last question'), text: this.vt('Explain my last question') },
        { key: 'zusammenfassung', label: this.vt('Create summary'), text: this.vt('Create summary') },
      ]
    },
  },
  mounted() {
    this.updateViewportMode()
    this.$nextTick(() => {
      this.scrollChatToBottom()
      this.autosizeComposer()
    })

    if (typeof window !== 'undefined') {
      window.addEventListener('resize', this.updateViewportMode)
      document.addEventListener('keydown', this.handleGlobalKeydown)
    }
  },
  beforeUnmount() {
    if (typeof window !== 'undefined') {
      window.removeEventListener('resize', this.updateViewportMode)
      document.removeEventListener('keydown', this.handleGlobalKeydown)
    }
  },
  watch: {
    chatMessages() {
      this.$nextTick(() => {
        this.scrollChatToBottom()
      })
    },
    chatLoading(newValue) {
      if (!newValue) {
        this.$nextTick(() => {
          this.scrollChatToBottom()
          this.focusComposer()
        })
      }
    },
  },
  methods: {
    t,
    vt(key, params = {}) {
      return translateVirtuProf(this.effectiveLanguage, key, params)
    },
    updateViewportMode() {
      this.isMobile = typeof window !== 'undefined' ? window.innerWidth < 600 : false
      if (!this.isMobile) {
        this.mobileContextOpen = false
      }
    },
    handleGlobalKeydown(event) {
      if (event.key === 'Escape') {
        this.$emit('close')
      }
    },
    onHeaderTouchStart(event) {
      if (!event.touches || event.touches.length !== 1) {
        return
      }
      this.headerTouchY = event.touches[0].clientY
    },
    onHeaderTouchEnd(event) {
      if (!this.isMobile || !event.changedTouches || event.changedTouches.length !== 1) {
        return
      }
      const deltaY = event.changedTouches[0].clientY - this.headerTouchY
      if (deltaY > 80) {
        this.$emit('close')
      }
    },
    sendChat() {
      const text = this.draft.trim()
      if (!text || this.chatLoading) {
        return
      }
      this.$emit('send', text)
      this.draft = ''
      this.$nextTick(() => {
        this.autosizeComposer()
      })
    },
    sendSuggestion(text) {
      if (!text || this.chatLoading) {
        return
      }
      this.$emit('send', text)
    },
    scrollChatToBottom() {
      const element = this.$refs.chatHistory
      if (element) {
        element.scrollTop = element.scrollHeight
      }
    },
    focusComposer() {
      const element = this.$refs.composer
      if (element) {
        element.focus()
      }
    },
    autosizeComposer() {
      const element = this.$refs.composer
      if (!element) {
        return
      }
      element.style.height = 'auto'
      element.style.height = `${Math.min(element.scrollHeight, 120)}px`
    },
    ncFileUrl(path) {
      const normalizedPath = String(path || '')
      const lastSlashIndex = normalizedPath.lastIndexOf('/')
      const directory = lastSlashIndex > 0 ? normalizedPath.slice(0, lastSlashIndex) : '/'
      const fileName = lastSlashIndex >= 0 ? normalizedPath.slice(lastSlashIndex + 1) : normalizedPath
      return generateUrl('/apps/files/') + '?dir=' + encodeURIComponent(directory) + '&scrollto=' + encodeURIComponent(fileName)
    },
  },
}
</script>

<style scoped>
.virtuprof-fullscreen {
  --virtuprof-overlay-accent: color-mix(in srgb, var(--color-primary-element) 14%, transparent);
  --virtuprof-overlay-success: color-mix(in srgb, var(--color-success) 16%, transparent);
  --virtuprof-overlay-backdrop: color-mix(in srgb, var(--color-background-darker) 55%, transparent);
  --virtuprof-surface-border: color-mix(in srgb, var(--color-border) 78%, transparent);
  --virtuprof-surface-background: color-mix(in srgb, var(--color-main-background) 94%, var(--color-background-darker) 6%);
  --virtuprof-surface-elevated: color-mix(in srgb, var(--color-main-background) 88%, var(--color-border) 12%);
  --virtuprof-surface-muted: color-mix(in srgb, var(--color-main-background) 92%, var(--color-border) 8%);
  --virtuprof-backdrop: color-mix(in srgb, var(--color-background-darker) 38%, transparent);
  --virtuprof-shadow: color-mix(in srgb, var(--color-background-darker) 22%, transparent);
  --virtuprof-overlay-text: var(--color-main-text);
  --virtuprof-overlay-link: var(--color-primary-element);
  position: fixed;
  inset: 0;
  z-index: 2500;
  display: flex;
  justify-content: center;
  padding: 20px;
  background:
    radial-gradient(circle at top left, var(--virtuprof-overlay-accent), transparent 30%),
    radial-gradient(circle at bottom right, var(--virtuprof-overlay-success), transparent 28%),
    var(--virtuprof-overlay-backdrop);
  backdrop-filter: blur(12px);
}

.virtuprof-fullscreen__surface {
  position: relative;
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  width: min(100%, 900px);
  max-width: 900px;
  min-height: 0;
  border: 1px solid var(--virtuprof-surface-border);
  border-radius: 28px;
  overflow: hidden;
  background: var(--virtuprof-surface-background);
  box-shadow: 0 30px 90px var(--virtuprof-shadow);
}

.virtuprof-fullscreen__header {
  position: sticky;
  top: 0;
  z-index: 2;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 24px 18px;
  border-bottom: 1px solid var(--color-border);
  background: color-mix(in srgb, var(--color-main-background) 82%, var(--virtuprof-surface-elevated) 18%);
  backdrop-filter: blur(12px);
  touch-action: pan-y;
}

.virtuprof-fullscreen__heading {
  min-width: 0;
}

.virtuprof-fullscreen__eyebrow {
  margin: 0 0 6px;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--color-primary-element);
}

.virtuprof-fullscreen__title {
  margin: 0;
  font-size: clamp(1.35rem, 1.1rem + 0.9vw, 2rem);
  line-height: 1.1;
  color: var(--color-main-text);
}

.virtuprof-fullscreen__subtitle {
  margin: 8px 0 0;
  font-size: 0.95rem;
  color: var(--color-text-maxcontrast);
  opacity: 0.78;
}

.virtuprof-fullscreen__header-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.header-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 40px;
  padding: 0 14px;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-main-background) 72%, transparent);
  color: var(--color-main-text);
  cursor: pointer;
}

.header-action:hover {
  background: color-mix(in srgb, var(--color-main-background) 80%, var(--color-border) 20%);
}

.header-action--primary {
  background: var(--color-primary-element);
  border-color: var(--color-primary-element);
  color: var(--color-primary-element-text);
}

.header-action--close {
  width: 40px;
  padding: 0;
  font-size: 1.5rem;
  line-height: 1;
}

.virtuprof-fullscreen__body {
  display: grid;
  grid-template-columns: minmax(0, 7fr) minmax(280px, 3fr);
  flex: 1;
  min-height: 0;
}

.virtuprof-fullscreen__chat-column {
  display: flex;
  flex-direction: column;
  min-height: 0;
  padding: 18px 22px 22px;
  gap: 16px;
}

.virtuprof-fullscreen__intro {
  padding: 16px 18px;
  border: 1px solid color-mix(in srgb, var(--color-primary-element) 18%, transparent);
  border-radius: 18px;
  background: color-mix(in srgb, var(--color-primary-element) 10%, var(--color-main-background) 90%);
}

.virtuprof-fullscreen__intro-title {
  margin: 0 0 8px;
  font-weight: 700;
  color: var(--color-main-text);
}

.virtuprof-fullscreen__intro-text {
  margin: 0;
  color: var(--color-text-maxcontrast);
  opacity: 0.88;
}

.virtuprof-fullscreen__history {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 14px;
  min-height: 0;
  padding-right: 6px;
  overflow: auto;
}

.virtuprof-fullscreen__empty-state {
  margin: auto 0;
  padding: 28px;
  border: 1px dashed var(--color-border);
  border-radius: 22px;
  background: var(--virtuprof-surface-muted);
}

.virtuprof-fullscreen__empty-state h3 {
  margin: 0 0 10px;
  font-size: 1.05rem;
}

.virtuprof-fullscreen__empty-state p {
  margin: 0;
  line-height: 1.55;
  color: var(--color-text-maxcontrast);
  opacity: 0.82;
}

.virtuprof-fullscreen__message {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-width: min(92%, 760px);
}

.virtuprof-fullscreen__message--user {
  align-self: flex-end;
  margin-left: auto;
}

.virtuprof-fullscreen__message--assistant {
  align-self: flex-start;
}

.virtuprof-fullscreen__message-meta {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
  opacity: 0.68;
}

.virtuprof-fullscreen__message-body {
  padding: 14px 16px;
  border-radius: 20px;
  line-height: 1.6;
  white-space: pre-wrap;
}

.virtuprof-fullscreen__message--assistant .virtuprof-fullscreen__message-body {
  background: color-mix(in srgb, var(--color-background-darker) 88%, var(--color-main-background) 12%);
  color: var(--color-main-text);
}

.virtuprof-fullscreen__message--user .virtuprof-fullscreen__message-body {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
}

.virtuprof-fullscreen__file-link {
  font-size: 0.82rem;
  color: var(--color-primary-element);
  text-decoration: none;
}

.virtuprof-fullscreen__file-link:hover {
  text-decoration: underline;
}

.virtuprof-fullscreen__typing {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 0 4px;
}

.typing-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: var(--color-primary-element);
  opacity: 0.42;
  animation: typingPulse 1.2s infinite ease-in-out;
}

.typing-dot:nth-child(2) {
  animation-delay: 0.15s;
}

.typing-dot:nth-child(3) {
  animation-delay: 0.3s;
}

.virtuprof-fullscreen__suggestions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.virtuprof-fullscreen__suggestion {
  padding: 10px 14px;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  background: transparent;
  color: var(--color-main-text);
  cursor: pointer;
}

.virtuprof-fullscreen__suggestion:hover:not(:disabled) {
  border-color: var(--color-primary-element);
  color: var(--color-primary-element);
}

.virtuprof-fullscreen__exam-blocked {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 16px;
  background: color-mix(in srgb, var(--color-warning) 12%, var(--color-main-background) 88%);
  color: var(--color-main-text);
}

.virtuprof-fullscreen__composer {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  padding: 16px;
  border: 1px solid var(--color-border);
  border-radius: 22px;
  background: var(--virtuprof-surface-muted);
}

.virtuprof-fullscreen__textarea {
  flex: 1;
  min-height: 44px;
  max-height: 120px;
  padding: 10px 12px;
  border: 0;
  background: transparent;
  color: var(--color-main-text);
  resize: none;
  outline: none;
}

.virtuprof-fullscreen__send {
  min-width: 110px;
  min-height: 44px;
  padding: 0 18px;
  border: 0;
  border-radius: 14px;
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  cursor: pointer;
}

.virtuprof-fullscreen__send:disabled {
  opacity: 0.52;
  cursor: not-allowed;
}

.virtuprof-fullscreen__context {
  display: flex;
  flex-direction: column;
  gap: 18px;
  min-height: 0;
  padding: 20px 20px 22px;
  border-left: 1px solid var(--color-border);
  background: color-mix(in srgb, var(--color-main-background) 90%, var(--color-background-darker) 10%);
  overflow: auto;
}

.virtuprof-fullscreen__context-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: color-mix(in srgb, var(--color-main-background) 92%, transparent);
}

.virtuprof-fullscreen__context-section h3 {
  margin: 0;
  font-size: 0.95rem;
}

.virtuprof-fullscreen__context-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.virtuprof-fullscreen__link-btn {
  border: 0;
  padding: 0;
  background: transparent;
  color: var(--color-primary-element);
  cursor: pointer;
}

.virtuprof-fullscreen__context-list {
  display: grid;
  grid-template-columns: minmax(0, 110px) minmax(0, 1fr);
  gap: 8px 12px;
  margin: 0;
}

.virtuprof-fullscreen__context-list dt {
  font-weight: 700;
  color: var(--color-text-maxcontrast);
  opacity: 0.78;
}

.virtuprof-fullscreen__context-list dd {
  margin: 0;
  min-width: 0;
  word-break: break-word;
  color: var(--color-main-text);
}

.virtuprof-fullscreen__sources-empty {
  color: var(--color-text-maxcontrast);
  opacity: 0.78;
}

.virtuprof-fullscreen__sources-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.virtuprof-fullscreen__source-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 12px;
  border-radius: 14px;
  background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
}

.virtuprof-fullscreen__source-file {
  font-weight: 700;
  color: var(--color-main-text);
}

.virtuprof-fullscreen__source-meta {
  font-size: 0.82rem;
  color: var(--color-text-maxcontrast);
  opacity: 0.78;
}

.virtuprof-fullscreen__mobile-backdrop {
  display: none;
}

.ai-consent-overlay {
  position: absolute;
  inset: 0;
  z-index: 4;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 14px;
  padding: 28px;
  background: color-mix(in srgb, var(--color-background-darker) 88%, transparent);
  backdrop-filter: blur(10px);
}

.ai-consent-title {
  margin: 0;
  font-size: 1.1rem;
  color: var(--virtuprof-overlay-text);
}

.ai-consent-body {
  max-height: min(52vh, 420px);
  overflow: auto;
  color: var(--virtuprof-overlay-text);
}

.ai-consent-privacy-link {
  margin: 0;
}

.ai-consent-privacy-link a {
  color: var(--virtuprof-overlay-link);
}

.ai-consent-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

@keyframes typingPulse {
  0%, 80%, 100% {
    transform: translateY(0);
    opacity: 0.35;
  }
  40% {
    transform: translateY(-4px);
    opacity: 1;
  }
}

@media (max-width: 1024px) {
  .virtuprof-fullscreen {
    padding: 12px;
  }

  .virtuprof-fullscreen__surface {
    width: 100%;
    max-width: none;
  }

  .virtuprof-fullscreen__body {
    grid-template-columns: minmax(0, 1fr);
  }

  .virtuprof-fullscreen__context {
    border-left: 0;
    border-top: 1px solid var(--color-border);
    max-height: 32vh;
  }
}

@media (max-width: 599px) {
  .virtuprof-fullscreen {
    padding: 0;
  }

  .virtuprof-fullscreen__surface {
    border-radius: 0;
    border: 0;
  }

  .virtuprof-fullscreen__header {
    padding: 18px 16px 14px;
  }

  .virtuprof-fullscreen__chat-column {
    padding: 12px 14px 16px;
  }

  .virtuprof-fullscreen__message {
    max-width: 100%;
  }

  .virtuprof-fullscreen__composer {
    padding: 12px;
  }

  .virtuprof-fullscreen__send {
    min-width: 88px;
  }

  .virtuprof-fullscreen__context {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 3;
    display: flex;
    max-height: 58vh;
    border-left: 0;
    border-top: 1px solid var(--color-border);
    border-top-left-radius: 22px;
    border-top-right-radius: 22px;
    transform: translateY(104%);
    transition: transform 180ms ease;
  }

  .virtuprof-fullscreen__context--mobile-open {
    transform: translateY(0);
  }

  .virtuprof-fullscreen__mobile-backdrop {
    position: fixed;
    inset: 0;
    z-index: 2;
    display: block;
    border: 0;
    background: var(--virtuprof-backdrop);
  }
}
</style>
