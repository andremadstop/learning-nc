<template>
  <div class="learning-settings-wrap">
    <h2>{{ t('learning', 'Admin Settings') }}</h2>

    <NcNoteCard type="info" class="settings-intro">
      {{ t('learning', 'These settings apply to all users of Learning.') }}
    </NcNoteCard>

    <div v-if="loading" class="loading">
      <NcLoadingIcon :size="32" />
      <span>{{ t('learning', 'Loading...') }}</span>
    </div>

    <div v-else class="settings-form">
      <NcNoteCard v-if="!certIssuerReady" type="warning" class="cert-setup-note">
        {{ t('learning', 'Certificates are not active yet. To issue signed completion certificates, the issuer signing key has to be generated once.') }}
        {{ t('learning', 'Until then, courses can be configured for certification but no certificate will be minted when a learner passes.') }}
        <div class="cert-setup-note__actions">
          <NcButton
            variant="primary"
            :disabled="certIssuerInitializing"
            @click="initCertIssuer">
            {{ certIssuerInitializing ? t('learning', 'Generating signing key…') : t('learning', 'Generate signing key') }}
          </NcButton>
        </div>
        <p v-if="certIssuerError" class="cert-setup-note__error" role="alert">
          {{ t('learning', 'The signing key could not be generated: {error}', { error: certIssuerError }) }}
        </p>
        <p class="field-help">
          {{ t('learning', 'Alternatively, on the server command line:') }} <code>occ learning:cert:init-issuer</code>
        </p>
      </NcNoteCard>
      <NcNoteCard v-if="certIssuerJustCreated" type="success" class="cert-setup-note">
        {{ t('learning', 'Signing key generated. Certificates are now active.') }}
      </NcNoteCard>

      <!-- GitHub #59: the inbox sat at the very bottom of this page, below the audit trail. It holds
           the technical requests without a course, which only an administrator can answer, so it
           stays here, at the top. Questions about a course go to that course's instructors. -->
      <div class="ticket-section">
        <div class="audit-header">
          <h3>
            {{ t('learning', 'Support tickets') }}
            <span v-if="openSupportTicketCount > 0" class="ticket-open-count">
              <span aria-hidden="true">{{ openSupportTicketCount }}</span>
              <span class="hidden-visually">{{ t('learning', 'Unbeantwortete Anfragen: {count}', { count: openSupportTicketCount }) }}</span>
            </span>
          </h3>
          <div class="ticket-toolbar">
            <!-- GitHub #59: archived tickets are hidden from the inbox and can be brought back -->
            <NcButton variant="tertiary" class="ticket-archive-toggle" @click="toggleTicketArchive">
              {{ showArchivedTickets ? t('learning', 'Aktuelle Anfragen anzeigen') : t('learning', 'Archivierte Anfragen anzeigen') }}
            </NcButton>
            <NcButton variant="tertiary" @click="loadSupportTickets">{{ t('learning', 'Refresh') }}</NcButton>
          </div>
        </div>
        <p class="field-help ticket-filter-note">
          {{ t('learning', 'Hier stehen nur technische Anfragen ohne Kursbezug. Fragen zu einem Kurs gehen an dessen Lehrende und stehen im Kurs im Bereich „Kommunikation“ unter „Fragen von Lernenden“.') }}
          <a :href="coursesUrl" class="ticket-courses-link">{{ t('learning', 'Zu den Kursen') }}</a>
        </p>

        <!-- GitHub #59: who is told of a new technical ticket. Not every administrator: on an
             instance with several admins each looks after something else. Saved on its own, the
             page's Save button is far below. -->
        <div class="ticket-recipients">
          <InstructorPicker
            v-model="ticketRecipients"
            kind="ticket-recipients"
            :label="t('learning', 'Bei neuen technischen Anfragen benachrichtigen')"
            :placeholder="t('learning', 'Administrator suchen …')"
            :disabled="!ticketRecipientsLoaded || savingTicketRecipients" />
          <small class="field-help">
            {{ t('learning', 'Diese Administratoren erhalten eine Nextcloud-Benachrichtigung, sobald eine neue technische Anfrage eingeht. Andere Administratoren werden nicht benachrichtigt.') }}
          </small>
          <NcNoteCard v-if="ticketRecipientsLoaded && savedTicketRecipients.length === 0" type="warning" class="ticket-recipients-none">
            {{ t('learning', 'Niemand wird über neue technische Anfragen benachrichtigt. Wähle mindestens einen Administrator aus und speichere.') }}
          </NcNoteCard>
          <NcNoteCard v-else-if="silentTicketRecipients.length > 0" type="warning" class="ticket-recipients-silent">
            {{ t('learning', 'Erhalten keine Benachrichtigung (kein Administrator, deaktiviert oder gelöscht): {names}', { names: silentTicketRecipients.join(', ') }, undefined, { escape: false, sanitize: false }) }}
          </NcNoteCard>
          <p v-if="ticketRecipientsError" class="ticket-recipients-error" role="alert">
            {{ ticketRecipientsError }}
          </p>
          <div class="ticket-actions">
            <NcButton
              variant="secondary"
              :disabled="!ticketRecipientsLoaded || savingTicketRecipients"
              @click="saveTicketRecipients">
              {{ savingTicketRecipients ? t('learning', 'Saving...') : t('learning', 'Empfänger speichern') }}
            </NcButton>
            <!-- always in the DOM: a live region that appears together with its text is often not read -->
            <span class="field-help" role="status" data-test="ticket-recipients-saved"><template v-if="ticketRecipientsSaved">{{ t('learning', 'Empfänger gespeichert.') }}</template></span>
          </div>
        </div>

        <p v-if="ticketActionError" class="ticket-recipients-error ticket-action-error" role="alert">
          {{ ticketActionError }}
        </p>
        <p v-if="showArchivedTickets" class="field-help">
          {{ t('learning', 'Archivierte Anfragen sind aus dem Postfach ausgeblendet. Die anfragende Person sieht ihre Anfrage und die Antwort weiterhin.') }}
        </p>
        <div v-if="ticketLoading" class="loading">
          <NcLoadingIcon :size="24" />
          <span>{{ t('learning', 'Loading...') }}</span>
        </div>
        <NcNoteCard v-else-if="ticketError" type="error">{{ ticketError }}</NcNoteCard>
        <div v-else-if="supportTickets.length === 0" class="field-help">
          {{ showArchivedTickets ? t('learning', 'Keine archivierten Anfragen.') : t('learning', 'No support tickets yet.') }}
        </div>
        <div v-else class="ticket-list">
          <div v-for="ticket in supportTickets" :key="ticket.id" class="ticket-card">
            <div class="ticket-card-header">
              <div>
                <strong>{{ ticket.subject }}</strong>
                <div class="ticket-meta">
                  {{ ticket.user_id }} · {{ formatTime(ticket.updated_at || ticket.created_at) }}
                </div>
              </div>
              <span class="ticket-status" :class="'status-' + ticket.status">{{ ticketStatusLabel(ticket.status) }}</span>
            </div>
            <div class="ticket-context">{{ formatTicketContext(ticket) }}</div>
            <p class="ticket-message">{{ ticket.message }}</p>
            <div v-if="ticket.answer_text" class="ticket-existing-answer">
              <strong>{{ t('learning', 'Current answer') }}:</strong> {{ ticket.answer_text }}
            </div>
            <label class="field-row">
              <span>{{ t('learning', 'Answer') }}</span>
              <textarea
                class="ticket-answer-input"
                rows="4"
                :value="ticketAnswers[ticket.id] || ''"
                :placeholder="t('learning', 'Write the admin reply here...')"
                @input="setTicketAnswer(ticket.id, $event.target.value)" />
            </label>
            <div class="ticket-actions">
              <NcButton
                variant="primary"
                :disabled="answeringId === ticket.id || !(ticketAnswers[ticket.id] || '').trim()"
                @click="submitTicketAnswer(ticket.id)">
                {{ answeringId === ticket.id ? t('learning', 'Saving...') : t('learning', 'Save answer') }}
              </NcButton>
            </div>
            <!-- GitHub #59: archive and delete, as instructors do with course questions -->
            <div class="ticket-manage">
              <NcButton v-if="ticket.status === 'archived'"
                variant="secondary"
                :disabled="!!managingTickets[ticket.id]"
                @click="setTicketArchived(ticket, false)">
                {{ t('learning', 'Wiederherstellen') }}
              </NcButton>
              <NcButton v-else
                variant="secondary"
                :disabled="!!managingTickets[ticket.id]"
                @click="setTicketArchived(ticket, true)">
                {{ t('learning', 'Archivieren') }}
              </NcButton>
              <NcButton variant="tertiary"
                :disabled="!!managingTickets[ticket.id]"
                @click="confirmTicket = ticket">
                {{ t('learning', 'Löschen') }}
              </NcButton>
            </div>
          </div>
        </div>
        <NcNoteCard v-if="ticketSuccess" type="success">{{ ticketSuccess }}</NcNoteCard>
      </div>

      <AccessibleDialog v-if="confirmTicket"
        :name="t('learning', 'Anfrage löschen')"
        @closing="cancelDeleteTicket">
        <p>{{ t('learning', 'Möchtest du die Anfrage „{subject}“ wirklich löschen? Anfrage und Antwort sind danach auch für die anfragende Person weg. Das lässt sich nicht rückgängig machen.', { subject: confirmTicket.subject }, undefined, { escape: false, sanitize: false }) }}</p>
        <template #actions>
          <NcButton variant="tertiary" :disabled="deletingTicket" @click="cancelDeleteTicket">
            {{ t('learning', 'Nicht löschen') }}
          </NcButton>
          <NcButton variant="error" :disabled="deletingTicket" @click="deleteTicket">
            {{ t('learning', 'Löschen') }}
          </NcButton>
        </template>
      </AccessibleDialog>

      <!-- GitHub #56: the label is the switch's own slot (Nextcloud pattern: switch and label on one
           line, and the switch gets an accessible name); help text is its description. -->
      <div class="switch-row">
        <NcCheckboxRadioSwitch
          :model-value="form.dailyChallengeEnabled"
          type="switch"
          @update:model-value="form.dailyChallengeEnabled = !!$event">
          {{ t('learning', 'Daily Challenge enabled') }}
        </NcCheckboxRadioSwitch>
      </div>

      <div class="field-row">
        <label for="default-language">{{ t('learning', 'Default language') }}</label>
        <select id="default-language" v-model="form.defaultLanguage" class="nc-input">
          <option value="de">{{ t('learning', 'German (Deutsch)') }}</option>
          <option value="en">{{ t('learning', 'English') }}</option>
        </select>
      </div>

      <div class="field-row">
        <label for="max-import">{{ t('learning', 'Max import file size (MB)') }}</label>
        <input
          id="max-import"
          v-model.number="form.maxImportSizeMb"
          class="nc-input"
          type="number"
          min="1"
          max="10" />
      </div>

      <div class="switch-row">
        <NcCheckboxRadioSwitch
          :model-value="form.gamificationEnabled"
          type="switch"
          @update:model-value="form.gamificationEnabled = !!$event">
          {{ t('learning', 'Gamification enabled') }}
        </NcCheckboxRadioSwitch>
      </div>

      <div class="field-row">
        <label>{{ t('learning', 'Enabled simulator tools') }}</label>
        <p class="field-help">
          {{ t('learning', 'Disabled tools disappear for all users in the Werkzeuge tab.') }}
        </p>
        <div class="tool-toggle-grid">
          <label v-for="tool in toolOptions" :key="tool.id" class="tool-toggle-row">
            <input
              type="checkbox"
              :checked="form.enabledTools.includes(tool.id)"
              @change="toggleTool(tool.id, $event.target.checked)">
            <span>{{ tool.label }}</span>
          </label>
        </div>
      </div>

      <!-- GitHub #60: several groups and individual people, picked from Nextcloud -->
      <div class="field-row">
        <InstructorPicker v-model="form.instructors" :disabled="!instructorsLoaded" />
        <p v-if="instructorsError" class="field-help" role="alert">
          {{ instructorsError }}
        </p>
        <small class="field-help">
          {{ t('learning', 'Mitglieder dieser Gruppen und diese Personen dürfen Kurse und Fragenpools anlegen. Sie brauchen dafür keine Administratorrechte in Nextcloud. Nextcloud-Administratoren zählen immer als Kursleitende. Ist nichts ausgewählt, gilt die Gruppe „learning-instructors“.') }}
        </small>
      </div>

      <div class="switch-row">
        <NcCheckboxRadioSwitch
          :model-value="form.allowCourseInstructorFallback"
          type="switch"
          :description="t('learning', 'Eingeschaltet erhalten auch alle, die in irgendeinem Kurs als Kursleitung eingetragen sind, globale Kursleitungsrechte, zum Beispiel neue Kurse anlegen und Fragenpools importieren. Ausgeschaltet (empfohlen) haben diese Rechte nur die oben gewählten Gruppen und Personen.')"
          @update:model-value="form.allowCourseInstructorFallback = !!$event">
          {{ t('learning', 'Allow course-based instructor fallback') }}
        </NcCheckboxRadioSwitch>
      </div>

      <div class="field-row">
        <label for="exam-attempt-limit">{{ t('learning', 'Exam attempts per 24h (per user, pool and course)') }}</label>
        <input
          id="exam-attempt-limit"
          v-model.number="form.examAttemptLimitPerDay"
          class="nc-input"
          type="number"
          min="1"
          max="50" />
      </div>

      <div class="field-row">
        <label for="exam-cooldown">{{ t('learning', 'Exam cooldown (minutes)') }}</label>
        <input
          id="exam-cooldown"
          v-model.number="form.examAttemptCooldownMinutes"
          class="nc-input"
          type="number"
          min="0"
          max="1440" />
      </div>

      <!-- VirtuProf AI section -->
      <div class="ai-section">
        <h3>{{ t('learning', 'VirtuProf AI Assistant') }}</h3>

        <div class="field-row">
          <label for="ai-provider">{{ t('learning', 'AI provider') }}</label>
          <select
            id="ai-provider"
            v-model="form.aiProvider"
            class="nc-input"
            @change="handleAiProviderChange">
            <option value="gemini">{{ t('learning', 'Google Gemini (external)') }}</option>
            <option value="ollama">{{ t('learning', 'Ollama (local/self-hosted)') }}</option>
            <option value="disabled">{{ t('learning', 'Disabled') }}</option>
          </select>
          <small class="field-help">
            {{ t('learning', 'Choose whether VirtuProf uses Google Gemini, a local Ollama endpoint, or stays disabled for all users.') }}
          </small>
        </div>

        <NcNoteCard v-if="showGeminiConfig" type="info" class="dpa-hint">
          <span>
            {{ t('learning', 'When AI is enabled, user questions and the currently displayed quiz question (text, answer options, explanation) are sent to Google Gemini (an external AI service). In exam mode, the correct answer is never transmitted. Error reports stay local and are not sent to Gemini. Admins subject to GDPR/DSGVO must review and accept the') }}
            {{ ' ' }}
            <a
              href="https://cloud.google.com/terms/data-processing-addendum"
              target="_blank"
              rel="noopener noreferrer">{{ t('learning', 'Google Data Processing Addendum (DPA)') }}</a>{{ '.' }}
          </span>
        </NcNoteCard>

        <NcNoteCard v-else-if="showOllamaConfig" type="info" class="dpa-hint">
          {{ t('learning', 'Local-first mode: VirtuProf requests stay on your configured Ollama host. Verify the URL, the installed model, and your internal access controls before enabling chat for users.') }}
        </NcNoteCard>

        <NcNoteCard v-else type="info" class="dpa-hint">
          {{ t('learning', 'AI is globally disabled. VirtuProf chat stays hidden for all users until you choose a provider and enable the feature again.') }}
        </NcNoteCard>

        <div class="switch-row">
          <NcCheckboxRadioSwitch
            :model-value="form.aiEnabled"
            :disabled="isAiProviderDisabled"
            type="switch"
            :description="isAiProviderDisabled
              ? t('learning', 'Select a provider first. The chat remains disabled while the provider is set to Disabled.')
              : t('learning', 'When disabled, the AI chat input is hidden for all users. When enabled, each user must give consent before their first message is sent.')"
            @update:model-value="form.aiEnabled = !!$event">
            {{ t('learning', 'Enable AI chat (VirtuProf)') }}
          </NcCheckboxRadioSwitch>
        </div>

        <NcNoteCard v-if="pdfTextExtractionAvailable === false" type="warning" class="pdf-extraction-hint">
          {{ t('learning', 'PDF text extraction is not available on this server (pdftotext missing). VirtuProf can only use Markdown course materials; students can still open every file.') }}
        </NcNoteCard>

        <div v-if="showGeminiConfig" class="field-row">
          <label for="gemini-api-key">{{ t('learning', 'Gemini API Key') }}</label>
          <input
            id="gemini-api-key"
            v-model="form.geminiApiKey"
            class="nc-input"
            type="password"
            autocomplete="new-password"
            :placeholder="form.geminiApiKeySet ? t('learning', '(key saved — enter new value to replace)') : t('learning', 'Enter Gemini API Key')" />
          <small class="field-help">
            {{ t('learning', 'Get your API key from') }}
            <a
              href="https://aistudio.google.com/app/apikey"
              target="_blank"
              rel="noopener noreferrer">Google AI Studio</a>.
            {{ t('learning', 'Leave blank to keep the existing key.') }}
          </small>
        </div>

        <div v-if="showOllamaConfig" class="field-row">
          <label for="ai-ollama-url">{{ t('learning', 'Ollama URL') }}</label>
          <input
            id="ai-ollama-url"
            v-model.trim="form.aiOllamaUrl"
            class="nc-input"
            type="url"
            autocomplete="off"
            placeholder="http://localhost:11434" />
          <small class="field-help">
            {{ t('learning', 'Base URL of the Ollama API that should answer VirtuProf requests.') }}
          </small>
        </div>

        <div v-if="showOllamaConfig" class="field-row">
          <label for="ai-ollama-model">{{ t('learning', 'Ollama model') }}</label>
          <input
            id="ai-ollama-model"
            v-model.trim="form.aiOllamaModel"
            class="nc-input"
            type="text"
            autocomplete="off"
            placeholder="llama3" />
          <small class="field-help">
            {{ t('learning', 'Model name passed to Ollama, for example llama3, mistral, or a custom local model tag.') }}
          </small>
        </div>
      </div>

      <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
      <NcNoteCard v-if="saved" type="success">{{ t('learning', 'Settings saved') }}</NcNoteCard>

      <div class="actions">
        <NcButton variant="primary" :disabled="saving" @click="save">
          {{ saving ? t('learning', 'Saving...') : t('learning', 'Save') }}
        </NcButton>
      </div>

      <OperatorSettings />

      <CertLogoLibrary />
      <CertBackgroundLibrary />

      <details class="audit-section">
        <summary class="audit-summary">
          <h3 class="audit-summary-title">{{ t('learning', 'Recent Audit Events') }} ({{ auditEvents.length }})</h3>
        </summary>
        <div class="audit-header">
          <NcButton variant="tertiary" @click="loadAudit">{{ t('learning', 'Refresh') }}</NcButton>
        </div>
        <div v-if="auditLoading" class="loading">
          <NcLoadingIcon :size="24" />
          <span>{{ t('learning', 'Loading...') }}</span>
        </div>
        <div v-else-if="auditEvents.length === 0" class="field-help">
          {{ t('learning', 'No audit events yet.') }}
        </div>
        <table v-else class="audit-table">
          <thead>
            <tr>
              <th>{{ t('learning', 'Time') }}</th>
              <th>{{ t('learning', 'Event') }}</th>
              <th>{{ t('learning', 'User') }}</th>
              <th>{{ t('learning', 'Session') }}</th>
              <th>{{ t('learning', 'Pool') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in auditEvents" :key="e.id">
              <td>{{ formatTime(e.created_at) }}</td>
              <td>{{ e.event_key }}</td>
              <td>{{ e.user_id || '-' }}</td>
              <td>{{ e.session_id || '-' }}</td>
              <td>{{ e.pool_id || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </details>

      <div class="section audit-liveness">
        <h3>{{ t('learning', 'Audit-Trail — Liveness') }}</h3>

        <div v-if="auditIsOverdue" class="audit-overdue-warning" role="alert">
          ⚠ {{ t('learning', 'Checkpoint überfällig! Ein Audit-Ereignis wartet seit mehr als 8 Tagen auf einen Checkpoint.') }}
          <template v-if="!certIssuerReady">
            {{ t('learning', 'Ursache: Checkpoints werden mit dem Signaturschlüssel signiert, und der fehlt noch. Erzeuge ihn oben im Zertifikate-Hinweis.') }}
          </template>
          <template v-else>
            {{ t('learning', 'Checkpoints erstellt ein wöchentlicher Hintergrundjob. Prüfe, ob die Nextcloud-Hintergrundjobs (Cron) laufen.') }}
          </template>
        </div>

        <table class="audit-liveness__table">
          <tbody>
            <tr>
              <td>{{ t('learning', 'Letzter Checkpoint') }}</td>
              <td>
                <span v-if="auditLastCheckpointAt > 0">{{ formatTime(auditLastCheckpointAt) }}</span>
                <span v-else>{{ t('learning', 'Noch kein Checkpoint') }}</span>
              </td>
            </tr>
            <tr>
              <td>{{ t('learning', 'Ereignisse seit letztem Checkpoint') }}</td>
              <td>{{ auditEventsSinceCheckpoint }}</td>
            </tr>
            <tr>
              <td>{{ t('learning', 'Forgejo-Anker') }}</td>
              <td>
                <span v-if="!auditAnchorEnabled">{{ t('learning', 'Deaktiviert (Standard)') }}</span>
                <span v-else-if="auditAnchorStatus === 'ok'">✓ {{ t('learning', 'Aktiv — letzter Anker OK') }}</span>
                <span v-else>✗ {{ t('learning', 'Aktiv — letzter Anker fehlgeschlagen') }}</span>
              </td>
            </tr>
          </tbody>
        </table>

        <p class="audit-liveness__hint field-help">
          {{ t('learning', 'Kettenintegrität prüfen:') }} <code>occ learning:audit:verify</code><br>
          {{ t('learning', 'Fork-Auflösung:') }} <a href="https://github.com/andremadstop/learning-nc/blob/main/docs/audit-fork-runbook.md" target="_blank" rel="noopener noreferrer"><code>audit-fork-runbook.md</code></a>
        </p>
      </div>

    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { nextTick } from 'vue'
import AccessibleDialog from './AccessibleDialog.vue'
import OperatorSettings from './OperatorSettings.vue'
import CertBackgroundLibrary from './CertBackgroundLibrary.vue'
import CertLogoLibrary from './CertLogoLibrary.vue'
import InstructorPicker from './InstructorPicker.vue'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ALL_TOOL_IDS, TOOL_CATALOG } from '../utils/toolCatalog.js'
import { toInstructorPayload, toOptions } from '../utils/member-picker.js'

export default {
  name: 'AdminSettings',
  components: { AccessibleDialog, CertBackgroundLibrary, CertLogoLibrary, InstructorPicker, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, OperatorSettings },
  data() {
    return {
      loading: true,
      saving: false,
      error: '',
      saved: false,
      certIssuerReady: true,
      // GitHub #60: the instructor list is only saved after it was loaded; saving an unloaded
      // (empty) list would replace the configured groups with the default group.
      instructorsLoaded: false,
      instructorsError: '',
      // GitHub #35: null = not reported by the server; false shows the pdftotext hint
      pdfTextExtractionAvailable: null,
      certIssuerInitializing: false,
      certIssuerError: '',
      certIssuerJustCreated: false,
      // AUDIT-08: audit-trail liveness (defaults neutral so the widget renders before load() resolves).
      auditLastCheckpointAt: 0,
      auditEventsSinceCheckpoint: 0,
      auditAnchorEnabled: false,
      auditAnchorStatus: 'none',
      auditIsOverdue: false,
      auditLoading: false,
      auditEvents: [],
      ticketLoading: false,
      ticketLoadSeq: 0,
      ticketError: '',
      ticketSuccess: '',
      answeringId: null,
      supportTickets: [],
      ticketAnswers: {},
      // GitHub #59: archive and delete of technical tickets; the open count is the server's
      showArchivedTickets: false,
      ticketOpenCount: null,
      managingTickets: {},
      // a failed archive/delete must not replace the list (ticketError does)
      ticketActionError: '',
      confirmTicket: null,
      deletingTicket: false,
      // GitHub #59: who is told of new technical tickets (picker options) and what is saved
      ticketRecipients: [],
      savedTicketRecipients: [],
      ticketRecipientsLoaded: false,
      savingTicketRecipients: false,
      ticketRecipientsSaved: false,
      ticketRecipientsError: '',
      form: {
        dailyChallengeEnabled: true,
        defaultLanguage: 'de',
        maxImportSizeMb: 2,
        gamificationEnabled: true,
        enabledTools: [...ALL_TOOL_IDS],
        // GitHub #60: picker options ('group:<gid>' / 'user:<uid>')
        instructors: [],
        allowCourseInstructorFallback: false,
        examAttemptLimitPerDay: 5,
        examAttemptCooldownMinutes: 10,
        aiEnabled: false,
        aiProvider: 'gemini',
        aiOllamaUrl: 'http://localhost:11434',
        aiOllamaModel: 'llama3',
        geminiApiKey: '',
        geminiApiKeySet: false,
      },
    }
  },
  mounted() {
    this.load()
    this.loadAudit()
    this.loadSupportTickets()
    this.loadTicketRecipients()
  },
  computed: {
    // GitHub #59: unanswered technical requests, next to the heading
    openSupportTicketCount() {
      // the server's count stays right while the archive is shown
      if (Number.isInteger(this.ticketOpenCount)) {
        return this.ticketOpenCount
      }
      return this.supportTickets.filter((ticket) => ticket.status === 'open').length
    },
    // GitHub #59: saved recipients who would get no notice as things are
    silentTicketRecipients() {
      return this.savedTicketRecipients.filter((user) => user.notified !== true).map((user) => user.label || user.id)
    },
    // a full URL: this component is mounted in the app and on Nextcloud's admin settings page
    coursesUrl() {
      return generateUrl('/apps/learning/courses')
    },
    toolOptions() {
      return TOOL_CATALOG.map((tool) => ({
        id: tool.id,
        label: t('learning', tool.labelKey),
      }))
    },
    isAiProviderDisabled() {
      return this.form.aiProvider === 'disabled'
    },
    showGeminiConfig() {
      return this.form.aiProvider === 'gemini'
    },
    showOllamaConfig() {
      return this.form.aiProvider === 'ollama'
    },
  },
  methods: {
    normalizeEnabledTools(enabledTools) {
      const source = Array.isArray(enabledTools) ? enabledTools : ALL_TOOL_IDS
      return ALL_TOOL_IDS.filter((toolId) => source.includes(toolId))
    },
    normalizeAiProvider(provider) {
      return ['gemini', 'ollama', 'disabled'].includes(provider) ? provider : 'gemini'
    },
    handleAiProviderChange() {
      this.form.aiProvider = this.normalizeAiProvider(this.form.aiProvider)
      if (this.form.aiProvider === 'disabled') {
        this.form.aiEnabled = false
      }
    },
    /**
     * 0 is a valid cooldown ("none"); only an empty or non-numeric value falls back to 10.
     * `x || 10` turned a stored or entered 0 into 10 (audit 2026-11).
     */
    normalizeCooldownMinutes(value) {
      if (value === '' || value === null || value === undefined) return 10
      const minutes = Number(value)
      if (!Number.isFinite(minutes)) return 10
      return Math.max(0, Math.min(1440, Math.round(minutes)))
    },
    async load() {
      this.loading = true
      this.error = ''
      try {
        // GitHub #60: the instructor list loads on its own; failing, it costs the picker, not the page
        const instructorsRequest = this.loadInstructors()
        const [adminResponse, toolsResponse] = await Promise.all([
          axios.get(generateUrl('/apps/learning/api/settings/admin')),
          axios.get(generateUrl('/apps/learning/api/settings/tools')),
        ])
        const data = adminResponse.data || {}
        this.form.dailyChallengeEnabled = (data.daily_challenge_enabled || 'yes') === 'yes'
        this.form.defaultLanguage = data.default_language === 'en' ? 'en' : 'de'
        this.form.maxImportSizeMb = Math.max(1, Math.min(10, Number(data.max_import_size_mb || 2)))
        this.form.gamificationEnabled = (data.gamification_enabled || 'yes') === 'yes'
        this.form.enabledTools = this.normalizeEnabledTools(toolsResponse.data?.enabled_tools)
        await instructorsRequest
        this.form.allowCourseInstructorFallback = (data.allow_course_instructor_fallback || 'no') === 'yes'
        this.form.examAttemptLimitPerDay = Math.max(1, Math.min(50, Number(data.exam_attempt_limit_per_day || 5)))
        this.form.examAttemptCooldownMinutes = this.normalizeCooldownMinutes(data.exam_attempt_cooldown_minutes)
        // PRIV-02 / PRIV-05: AI settings
        this.form.aiProvider = this.normalizeAiProvider(data.ai_provider)
        this.form.aiOllamaUrl = (data.ai_ollama_url || 'http://localhost:11434').trim() || 'http://localhost:11434'
        this.form.aiOllamaModel = (data.ai_ollama_model || 'llama3').trim() || 'llama3'
        this.form.aiEnabled = this.form.aiProvider !== 'disabled' && (data.ai_enabled || 'no') === 'yes'
        this.form.geminiApiKeySet = data.gemini_api_key_set === true
        // GitHub #35: course materials feed the AI assistant only if PDFs can be read as text
        this.pdfTextExtractionAvailable = typeof data.pdf_text_extraction_available === 'boolean'
          ? data.pdf_text_extraction_available
          : null
        this.form.geminiApiKey = ''
        // Certificates (v5.0.0): banner nudges fresh installs to init the signing key.
        this.certIssuerReady = data.cert_issuer_ready !== false
        // AUDIT-08: audit-trail liveness (neutral fallbacks for a pre-migration fresh install).
        this.auditLastCheckpointAt = data.audit_last_checkpoint_at ?? 0
        this.auditEventsSinceCheckpoint = data.audit_events_since_checkpoint ?? 0
        this.auditAnchorEnabled = data.audit_anchor_enabled ?? false
        this.auditAnchorStatus = data.audit_anchor_status ?? 'none'
        this.auditIsOverdue = data.audit_is_overdue ?? false
      } catch (e) {
        this.error = t('learning', 'Failed to load settings')
      } finally {
        this.loading = false
      }
    },
    /** GitHub #60: never throws; instructorsLoaded stays false (picker disabled, list not saved) on failure. */
    async loadInstructors() {
      this.instructorsError = ''
      try {
        const res = await axios.get(generateUrl('/apps/learning/api/settings/admin/instructors'))
        this.form.instructors = toOptions(res.data)
        this.instructorsLoaded = true
      } catch (e) {
        this.instructorsLoaded = false
        this.instructorsError = t('learning', 'Die Kursleitenden konnten nicht geladen werden.')
      }
    },
    async initCertIssuer() {
      if (this.certIssuerInitializing) {
        return
      }
      this.certIssuerInitializing = true
      this.certIssuerError = ''
      try {
        await axios.post(generateUrl('/apps/learning/api/settings/admin/cert-issuer'))
        this.certIssuerReady = true
        this.certIssuerJustCreated = true
      } catch (e) {
        // 409 = a key already exists (another tab or admin got there first): that is the goal state.
        if (e?.response?.data?.cert_issuer_ready === true) {
          this.certIssuerReady = true
        } else {
          this.certIssuerError = e?.response?.data?.error || e?.message || String(e)
        }
      } finally {
        this.certIssuerInitializing = false
      }
    },
    async save() {
      this.saving = true
      this.error = ''
      this.saved = false
      try {
        await Promise.all([
          axios.put(generateUrl('/apps/learning/api/settings/admin'), {
            daily_challenge_enabled: this.form.dailyChallengeEnabled ? 'yes' : 'no',
            default_language: this.form.defaultLanguage === 'en' ? 'en' : 'de',
            max_import_size_mb: Math.max(1, Math.min(10, Number(this.form.maxImportSizeMb || 2))),
            gamification_enabled: this.form.gamificationEnabled ? 'yes' : 'no',
            allow_course_instructor_fallback: this.form.allowCourseInstructorFallback ? 'yes' : 'no',
            exam_attempt_limit_per_day: Math.max(1, Math.min(50, Number(this.form.examAttemptLimitPerDay || 5))),
            exam_attempt_cooldown_minutes: this.normalizeCooldownMinutes(this.form.examAttemptCooldownMinutes),
            // PRIV-02 / PRIV-05: AI settings
            ai_provider: this.normalizeAiProvider(this.form.aiProvider),
            ai_ollama_url: (this.form.aiOllamaUrl || 'http://localhost:11434').trim() || 'http://localhost:11434',
            ai_ollama_model: (this.form.aiOllamaModel || 'llama3').trim() || 'llama3',
            ai_enabled: this.form.aiProvider !== 'disabled' && this.form.aiEnabled ? 'yes' : 'no',
            ...(this.form.geminiApiKey.trim() !== '' ? { gemini_api_key: this.form.geminiApiKey.trim() } : {}),
          }),
          axios.put(generateUrl('/apps/learning/api/settings/tools'), {
            enabledTools: this.normalizeEnabledTools(this.form.enabledTools),
          }),
          ...(this.instructorsLoaded
            ? [axios.put(generateUrl('/apps/learning/api/settings/admin/instructors'), toInstructorPayload(this.form.instructors))
              .then((res) => { this.form.instructors = toOptions(res.data) })]
            : []),
        ])
        this.saved = true
      } catch (e) {
        this.error = t('learning', 'Failed to save settings')
      } finally {
        this.saving = false
      }
    },
    async loadAudit() {
      this.auditLoading = true
      try {
        const res = await axios.get(generateUrl('/apps/learning/api/settings/admin/audit'), { params: { limit: 100, offset: 0 } })
        this.auditEvents = Array.isArray(res.data?.events) ? res.data.events : []
      } catch (e) {
        this.auditEvents = []
      } finally {
        this.auditLoading = false
      }
    },
    async loadSupportTickets() {
      // only the latest request writes the list and ends the loading state: a late answer
      // (the other list, or an older load of the same one) must not show under the new mode
      const seq = ++this.ticketLoadSeq
      this.ticketLoading = true
      this.ticketError = ''
      this.ticketSuccess = ''
      try {
        const archived = this.showArchivedTickets
        const res = await axios.get(generateUrl('/apps/learning/api/settings/admin/support-tickets'), {
          params: archived ? { limit: 100, archived: 1 } : { limit: 100 },
        })
        if (seq !== this.ticketLoadSeq) return
        this.supportTickets = Array.isArray(res.data?.tickets) ? res.data.tickets : []
        this.ticketOpenCount = Number.isInteger(res.data?.open_count) ? res.data.open_count : null
        const nextAnswers = {}
        this.supportTickets.forEach(ticket => {
          nextAnswers[ticket.id] = ticket.answer_text || ''
        })
        this.ticketAnswers = nextAnswers
      } catch (e) {
        if (seq !== this.ticketLoadSeq) return
        this.supportTickets = []
        this.ticketError = t('learning', 'Failed to load support tickets')
      } finally {
        if (seq === this.ticketLoadSeq) this.ticketLoading = false
      }
    },
    setTicketAnswer(ticketId, value) {
      this.ticketAnswers[ticketId] = value
    },
    toggleTool(toolId, enabled) {
      const next = new Set(this.normalizeEnabledTools(this.form.enabledTools))
      if (enabled) {
        next.add(toolId)
      } else {
        next.delete(toolId)
      }
      this.form.enabledTools = ALL_TOOL_IDS.filter((id) => next.has(id))
    },
    async submitTicketAnswer(ticketId) {
      // An empty answer is refused by the server; it is not sent (the button is disabled, too).
      if (!(this.ticketAnswers[ticketId] || '').trim()) return
      this.answeringId = ticketId
      this.ticketActionError = ''
      this.ticketSuccess = ''
      try {
        await axios.post(generateUrl(`/apps/learning/api/settings/admin/support-tickets/${ticketId}/answer`), {
          answerText: this.ticketAnswers[ticketId] || '',
        })
        this.ticketSuccess = t('learning', 'Support ticket answer saved')
        await this.loadSupportTickets()
      } catch (e) {
        // The persona sweep (C5): this went to ticketError, which takes the place of the whole
        // ticket list, so a failed answer hid every ticket behind the server's English message.
        // It belongs above the list, as archive and delete errors do; the typed answer stays.
        this.ticketActionError = t('learning', 'Failed to save support ticket answer')
      } finally {
        this.answeringId = null
      }
    },
    ticketStatusLabel(status) {
      if (status === 'open') return t('learning', 'Offen (Status einer Frage)')
      if (status === 'answered') return t('learning', 'Answered')
      if (status === 'archived') return t('learning', 'Archiviert')
      return status
    },
    toggleTicketArchive() {
      this.showArchivedTickets = !this.showArchivedTickets
      this.ticketActionError = ''
      this.loadSupportTickets()
    },
    // archive, restore and delete answer with the new inbox count; no extra list request, whose
    // rate limit (20/min) is lower than theirs (30/min)
    applyTicketOpenCount(data) {
      if (Number.isInteger(data?.open_count)) this.ticketOpenCount = data.open_count
    },
    async setTicketArchived(ticket, archived) {
      if (this.managingTickets[ticket.id]) return
      this.managingTickets[ticket.id] = true
      this.ticketActionError = ''
      this.ticketSuccess = ''
      try {
        const res = archived
          ? await axios.post(generateUrl('/apps/learning/api/settings/admin/support-tickets/{id}/archive', { id: ticket.id }))
          : await axios.post(generateUrl('/apps/learning/api/settings/admin/support-tickets/{id}/restore', { id: ticket.id }))
        // it leaves the list it was in: the inbox when archived, the archive when restored
        this.removeTicket(ticket)
        this.applyTicketOpenCount(res?.data)
      } catch (e) {
        this.ticketActionError = archived
          ? t('learning', 'Die Anfrage konnte nicht archiviert werden.')
          : t('learning', 'Die Anfrage konnte nicht wiederhergestellt werden.')
      } finally {
        delete this.managingTickets[ticket.id]
      }
    },
    // The clicked button leaves with its ticket; keyboard focus moves to the ticket that takes
    // its place (or the one above), else to the archive switch, not to the page body.
    async removeTicket(ticket) {
      const index = this.supportTickets.findIndex((x) => x.id === ticket.id)
      this.supportTickets = this.supportTickets.filter((x) => x.id !== ticket.id)
      await nextTick()
      const items = this.$el?.querySelectorAll?.('.ticket-card .ticket-manage') || []
      const next = items[Math.min(Math.max(index, 0), items.length - 1)]
      const target = next?.querySelector('button') || this.$el?.querySelector?.('.ticket-archive-toggle')
      target?.focus?.()
    },
    cancelDeleteTicket() {
      if (!this.deletingTicket) {
        this.confirmTicket = null
      }
    },
    async deleteTicket() {
      const ticket = this.confirmTicket
      if (!ticket || this.deletingTicket) return
      this.deletingTicket = true
      this.ticketActionError = ''
      this.ticketSuccess = ''
      try {
        const res = await axios.delete(generateUrl('/apps/learning/api/settings/admin/support-tickets/{id}', { id: ticket.id }))
        this.confirmTicket = null
        this.removeTicket(ticket)
        this.applyTicketOpenCount(res?.data)
      } catch (e) {
        this.confirmTicket = null
        this.ticketActionError = t('learning', 'Die Anfrage konnte nicht gelöscht werden.')
      } finally {
        this.deletingTicket = false
      }
    },
    /** GitHub #59: never throws; on failure the picker stays disabled and nothing is saved. */
    async loadTicketRecipients() {
      this.ticketRecipientsError = ''
      try {
        const res = await axios.get(generateUrl('/apps/learning/api/settings/admin/ticket-recipients'))
        this.applyTicketRecipients(res.data)
        this.ticketRecipientsLoaded = true
      } catch (e) {
        this.ticketRecipientsLoaded = false
        this.ticketRecipientsError = t('learning', 'Die Empfänger konnten nicht geladen werden.')
      }
    },
    applyTicketRecipients(data) {
      const users = Array.isArray(data?.users) ? data.users : []
      this.savedTicketRecipients = users
      this.ticketRecipients = toOptions({ users })
    },
    async saveTicketRecipients() {
      if (!this.ticketRecipientsLoaded || this.savingTicketRecipients) return
      this.savingTicketRecipients = true
      this.ticketRecipientsSaved = false
      this.ticketRecipientsError = ''
      try {
        const userIds = (this.ticketRecipients || []).filter((o) => o.type === 'user').map((o) => o.value)
        const res = await axios.put(generateUrl('/apps/learning/api/settings/admin/ticket-recipients'), { userIds })
        this.applyTicketRecipients(res.data)
        this.ticketRecipientsSaved = true
      } catch (e) {
        this.ticketRecipientsError = t('learning', 'Die Empfänger konnten nicht gespeichert werden.')
      } finally {
        this.savingTicketRecipients = false
      }
    },

    formatTicketContext(ticket) {
      const parts = []
      const context = ticket.context || {}
      if (context.courseTitle) {
        parts.push(context.courseTitle)
      } else if (ticket.course_id) {
        parts.push(`${t('learning', 'Course')} #${ticket.course_id}`)
      }
      if (context.poolName) {
        parts.push(context.poolName)
      } else if (ticket.pool_id) {
        parts.push(`${t('learning', 'Pool')} #${ticket.pool_id}`)
      }
      if (context.area) {
        parts.push(context.area)
      }
      if (ticket.question_id) {
        parts.push(`${t('learning', 'Question')} #${ticket.question_id}`)
      }
      if (ticket.duel_code) {
        parts.push(`${t('learning', 'Duel')} ${ticket.duel_code}`)
      }
      return parts.join(' · ') || t('learning', 'No context available')
    },
    formatTime(ts) {
      if (!ts) return '-'
      try {
        return new Date(ts * 1000).toLocaleString()
      } catch {
        return String(ts)
      }
    },
  },
}
</script>

<style scoped>
.learning-settings-wrap {
  max-width: 760px;
  margin: 0;
  padding: 8px 0;
}

.settings-intro {
  margin-bottom: 16px;
}

.loading {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--color-text-maxcontrast);
}

.settings-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.field-row {
  display: grid;
  gap: 8px;
}

.field-row label {
  font-weight: 600;
}

.field-help {
  color: var(--color-text-maxcontrast);
  font-size: 0.85em;
}

/* GitHub #55: Nextcloud core gives every input and select width: 130px; without a width of
   their own the fields stayed that narrow on the settings pages. */
.nc-input {
  width: 100%;
  max-width: 280px;
  box-sizing: border-box;
}

.actions {
  padding-top: 4px;
}

.tool-toggle-grid {
  display: grid;
  gap: 8px 12px;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.tool-toggle-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-main-background);
}

.ai-section {
  margin-top: 8px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}

.ai-section h3 {
  margin: 0 0 12px;
  font-size: 1em;
  font-weight: 700;
}

.dpa-hint {
  margin-bottom: 14px;
}

.dpa-hint a {
  color: var(--color-primary-element);
  text-decoration: underline;
}

.audit-section {
  margin-top: 20px;
}

.audit-summary {
  cursor: pointer;
  user-select: none;
  list-style: none;
  display: flex;
  align-items: center;
  gap: 8px;
}

.audit-summary::-webkit-details-marker {
  display: none;
}

.audit-summary::before {
  content: '\25B6';
  font-size: 0.7em;
  transition: transform 0.2s;
}

details[open] > .audit-summary::before {
  transform: rotate(90deg);
}

.audit-summary-title {
  margin: 0;
  display: inline;
}

.audit-liveness {
  margin-top: 20px;
}

.audit-liveness h3 {
  margin: 0 0 12px;
  font-size: 1em;
  font-weight: 700;
}

.cert-setup-note__actions {
  margin: 10px 0 6px;
}

.cert-setup-note__error {
  color: var(--color-error-text, var(--color-error));
  font-weight: 600;
}

.audit-overdue-warning {
  margin-bottom: 12px;
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid var(--color-border-error);
  background: var(--color-error);
  color: var(--color-error-text, var(--color-error));
  font-weight: 600;
}

.audit-liveness__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9em;
}

.audit-liveness__table td {
  padding: 6px 8px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
}

.audit-liveness__table td:first-child {
  font-weight: 600;
  width: 55%;
}

.audit-liveness__hint {
  margin-top: 10px;
}

.ticket-section {
  margin-top: 20px;
}

.ticket-open-count {
  display: inline-block;
  min-width: 1.5em;
  margin-inline-start: 6px;
  padding: 0 6px;
  border-radius: 10px;
  background: var(--color-primary-element, var(--color-primary));
  color: var(--color-primary-element-text, var(--color-primary-text));
  font-size: 0.8em;
  font-weight: 600;
  line-height: 1.5;
  text-align: center;
  vertical-align: middle;
}

.ticket-courses-link {
  color: var(--color-primary-element, var(--color-primary));
  text-decoration: underline;
}

.audit-header {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.audit-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9em;
}

.audit-table th,
.audit-table td {
  padding: 6px 8px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
}

.ticket-list {
  display: grid;
  gap: 12px;
}

.ticket-card {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 14px;
  background: var(--color-main-background);
  display: grid;
  gap: 10px;
}

.ticket-card-header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.ticket-meta,
.ticket-context {
  color: var(--color-text-maxcontrast);
  font-size: 0.85em;
}

.ticket-status {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-maxcontrast);
}

.ticket-status.status-answered {
  color: var(--color-success-text);
}

.ticket-message,
.ticket-existing-answer {
  margin: 0;
  line-height: 1.5;
}

.ticket-answer-input {
  width: 100%;
  box-sizing: border-box;
  min-height: 110px;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 10px 12px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  resize: vertical;
  font: inherit;
}

.ticket-actions {
  display: flex;
  justify-content: flex-end;
}

/* GitHub #59: archive switch, recipients and per-ticket archive/delete */
.ticket-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.ticket-recipients {
  display: grid;
  gap: 8px;
  margin-block: 8px 16px;
}

.ticket-recipients .ticket-actions {
  justify-content: flex-start;
  align-items: center;
  gap: 8px;
}

.ticket-recipients-error {
  margin: 0;
  color: var(--color-error-text, var(--color-error));
}

.ticket-manage {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}

.ticket-status.status-archived {
  color: var(--color-main-text);
}
</style>
