<template>
  <section class="operator-settings" aria-labelledby="learning-operator-heading">
    <h3 id="learning-operator-heading">{{ t('learning', 'Operator details (imprint and privacy notice)') }}</h3>
    <p class="field-help">
      {{ t('learning', 'Learning shows these details on its imprint and privacy pages. Until name, postal address and contact email are entered, both pages show no operator, and Learning does not set the Nextcloud imprint and privacy links. Links already set in the Theming settings are never replaced. Saving with all fields empty removes the details.') }}
    </p>

    <div v-for="field in fields" :key="field.key" class="field-row">
      <label :for="'learning-operator-' + fieldId(field.key)">{{ field.label }}</label>
      <textarea
        v-if="field.multiline"
        :id="'learning-operator-' + fieldId(field.key)"
        v-model="form[field.key]"
        class="nc-input operator-settings__input"
        rows="3"
        :maxlength="field.max"
        :aria-invalid="fieldErrors[field.key] ? 'true' : 'false'" />
      <input
        v-else
        :id="'learning-operator-' + fieldId(field.key)"
        v-model="form[field.key]"
        class="nc-input operator-settings__input"
        :type="field.key === 'email' ? 'email' : 'text'"
        :maxlength="field.max"
        :aria-invalid="fieldErrors[field.key] ? 'true' : 'false'">
      <small v-if="field.help" class="field-help">{{ field.help }}</small>
      <small v-if="fieldErrors[field.key]" class="operator-settings__error" role="alert">
        {{ errorText(fieldErrors[field.key]) }}
      </small>
    </div>

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
    <NcNoteCard v-if="saved" type="success">{{ t('learning', 'Operator details saved') }}</NcNoteCard>

    <div class="actions">
      <NcButton variant="primary" :disabled="saving || loading" @click="save">
        {{ saving ? t('learning', 'Saving...') : t('learning', 'Save operator details') }}
      </NcButton>
    </div>
  </section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { OPERATOR_FIELDS } from '../utils/operatorInfo.js'

const ENDPOINT = '/apps/learning/api/settings/admin/operator'

function emptyForm() {
  return Object.fromEntries(OPERATOR_FIELDS.map(field => [field, '']))
}

/**
 * Admin form for the operator of this installation (audit 2026-11): the imprint and privacy pages
 * used to name the app author's company on every installation.
 */
export default {
  name: 'OperatorSettings',
  components: { NcButton, NcNoteCard },
  data() {
    return {
      loading: true,
      saving: false,
      saved: false,
      error: '',
      configured: false,
      fieldErrors: {},
      form: emptyForm(),
    }
  },
  computed: {
    fields() {
      return [
        { key: 'name', label: t('learning', 'Name of the operator'), max: 200 },
        { key: 'address', label: t('learning', 'Postal address'), max: 500, multiline: true },
        { key: 'register', label: t('learning', 'Register entry (optional)'), max: 200, help: t('learning', 'For example the register court and number of a company or association.') },
        { key: 'email', label: t('learning', 'Contact email'), max: 254 },
        { key: 'content_responsible', label: t('learning', 'Responsible for content (optional)'), max: 200, help: t('learning', 'If empty, the imprint names the operator.') },
      ]
    },
  },
  mounted() {
    this.load()
  },
  methods: {
    fieldId(key) {
      return key.replace(/_/g, '-')
    },
    errorText(code) {
      if (code === 'required') return t('learning', 'This field is required.')
      if (code === 'invalid') return t('learning', 'Please enter a valid email address.')
      if (code === 'too_long') return t('learning', 'This entry is too long.')
      return t('learning', 'Please check this entry.')
    },
    applyResponse(data) {
      const operator = data?.operator || {}
      const form = emptyForm()
      for (const field of OPERATOR_FIELDS) {
        form[field] = typeof operator[field] === 'string' ? operator[field] : ''
      }
      this.form = form
      this.configured = data?.configured === true
    },
    async load() {
      this.loading = true
      this.error = ''
      try {
        const response = await axios.get(generateUrl(ENDPOINT))
        this.applyResponse(response.data)
      } catch (e) {
        this.error = t('learning', 'Failed to load settings')
      } finally {
        this.loading = false
      }
    },
    async save() {
      this.saving = true
      this.saved = false
      this.error = ''
      this.fieldErrors = {}
      try {
        const payload = {}
        for (const field of OPERATOR_FIELDS) {
          payload[field] = this.form[field] || ''
        }
        const response = await axios.put(generateUrl(ENDPOINT), payload)
        this.applyResponse(response.data)
        this.saved = true
      } catch (e) {
        const fields = e?.response?.status === 400 ? e.response.data?.fields : null
        this.fieldErrors = fields && typeof fields === 'object' ? fields : {}
        this.error = t('learning', 'The operator details could not be saved.')
      } finally {
        this.saving = false
      }
    },
  },
}
</script>

<style scoped>
.operator-settings {
  display: grid;
  gap: 14px;
  margin-top: 24px;
}

.operator-settings h3 {
  margin: 0;
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

.operator-settings__input {
  max-width: 480px;
  width: 100%;
}

.operator-settings__error {
  color: var(--color-error-text, var(--color-error));
  font-size: 0.85em;
}

.actions {
  padding-top: 4px;
}
</style>
