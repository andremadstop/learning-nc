<template>
  <AccessibleDialog :name="t('learning', 'Import Questions')" @closing="$emit('close')" size="normal">
    <div class="format-tabs" role="tablist">
      <button @click="chooseFormat('csv')" :class="['tab-btn', { active: format === 'csv' }]" role="tab" :aria-selected="format === 'csv' ? 'true' : 'false'">{{ t('learning', 'CSV') }}</button>
      <button @click="chooseFormat('json')" :class="['tab-btn', { active: format === 'json' }]" role="tab" :aria-selected="format === 'json' ? 'true' : 'false'">{{ t('learning', 'JSON') }}</button>
    </div>

    <NcNoteCard type="info" class="format-help">
      <template v-if="format === 'csv'">
        <strong>{{ t('learning', 'CSV Format:') }}</strong><br/>
        <code>question,answer1,answer2,...,answerN,correct_number,explanation</code><br/>
        <small>
          {{ t('learning', 'Example:') }}<br/>
          <code class="example-code">What is 2+2?,3,4,5,6,2,Because math</code><br/>
          <code class="example-code">Who wrote Hamlet?,Shakespeare,Dickens,Tolkien,1</code><br/>
          <code class="example-code">What is H2O?,Water,open</code><br/><br/>
          {{ t('learning', '2-8 answers per question. correct_number: which answer is correct (1 = first). Explanation is optional. Header row is auto-detected.') }}
          {{ t('learning', 'Free text format: question,model_answer,open') }}
        </small>
      </template>
      <template v-else>
        <strong>{{ t('learning', 'JSON Format: Array of question objects') }}</strong><br/>
        <code class="example-code">[{"text":"What is 2+2?","answers":[{"text":"3","is_correct":false},{"text":"4","is_correct":true}],"explanation":"Basic math"}]</code><br/>
        <code class="example-code">[{"question":"Capital of France?","options":["Berlin","Paris","Rome"],"answer":1}]</code><br/>
        <small>
          {{ t('learning', 'Also accepted: {"questions": […]}, a single question object, or the pool file format {"_meta": …, "pools": [ … ]} with exactly one pool (its name and description are not applied here; for several pools use "occ learning:import-pool-json").') }}<br/>
          {{ t('learning', 'Answers: "answers", "options" or "choices" — objects with "is_correct", or plain strings. Without "is_correct", name the correct answer on the question: "answer", "correct" or "correct_index" count from 0 (0 = first answer), "correct_number" counts from 1; a letter (A = first) or the exact answer text also work, a list names several correct answers.') }}<br/>
          {{ t('learning', 'Optional fields: difficulty, explanation, question_type ("multi" for multiple correct answers, "open" for free text with one model answer), exam_key, handbook_key, handbook_title, chapter_key, chapter_title, chapter_order.') }}
        </small>
      </template>
    </NcNoteCard>

    <div class="input-section">
      <div
        class="dropzone"
        :class="{ 'dropzone--active': dragging }"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="handleDrop"
        @click="$refs.fileInput.click()"
      >
        <input ref="fileInput" type="file" accept=".csv,.txt,.json" @change="handleFile" style="display:none" />
        <div v-if="fileName" class="dropzone-file">
          <strong>{{ fileName }}</strong>
          <small v-if="textData">({{ Math.round(textData.length / 1024) }} KB)</small>
        </div>
        <div v-else class="dropzone-hint">
          {{ t('learning', 'Drop CSV or JSON file here — or click to browse') }}
        </div>
      </div>
      <div class="input-actions">
        <NcButton variant="tertiary" @click="loadExample">{{ t('learning', 'Load Example') }}</NcButton>
        <NcButton v-if="fileName" variant="tertiary" @click="clearFile">{{ t('learning', 'Clear') }}</NcButton>
      </div>
      <p class="or-divider">{{ t('learning', '— or paste below —') }}</p>
      <textarea v-model="textData" :placeholder="format === 'csv' ? t('learning', 'Paste CSV data here...') : t('learning', 'Paste JSON data here...')" rows="6" class="nc-input data-input"></textarea>
    </div>

    <NcNoteCard v-if="jsonHint" type="warning" class="json-hint">
      {{ jsonHint }}
    </NcNoteCard>

    <NcNoteCard v-if="previewError" type="error" class="preview-error">
      {{ previewError }}
    </NcNoteCard>

    <div v-if="preview.length > 0" class="preview-section">
      <h4>{{ t('learning', 'Preview (first {n} items)', { n: preview.length }) }}</h4>
      <div v-for="(item, i) in preview" :key="i" :class="['preview-item', { 'preview-item--error': item.error }]">
        <strong>Q{{ item.num || i + 1 }}:</strong> {{ item.text }}
        <span v-if="item.error" class="preview-item-error">— {{ item.error }}</span>
        <span v-else class="preview-answers">({{ previewDetail(item) }})</span>
      </div>
      <div v-if="previewSummary" class="preview-summary">
        <strong>{{ t('learning', '{importable} of {total} items can be imported', { importable: previewSummary.importable, total: previewSummary.total }) }}</strong>
        <div v-if="previewSummary.errors.length > 0" class="error-list">
          <div v-for="(err, i) in previewSummary.errors.slice(0, 5)" :key="'pe'+i">{{ err }}</div>
          <div v-if="previewSummary.errors.length > 5">{{ t('learning', '...and {n} more', { n: previewSummary.errors.length - 5 }) }}</div>
        </div>
        <div v-if="previewSummary.warnings.length > 0" class="warning-list">
          <div v-for="(w, i) in previewSummary.warnings.slice(0, 5)" :key="'pw'+i" class="warning-item">{{ w }}</div>
          <div v-if="previewSummary.warnings.length > 5">{{ t('learning', '...and {n} more warnings', { n: previewSummary.warnings.length - 5 }) }}</div>
        </div>
      </div>
    </div>

    <NcNoteCard v-if="result && result.imported > 0" type="success">
      <strong>{{ t('learning', '{imported} of {total} questions imported', { imported: result.imported, total: result.total_items || result.total_lines || result.imported }) }}</strong>
      <div v-if="result.warnings && result.warnings.length > 0" class="warning-list">
        <div v-for="(w, i) in result.warnings.slice(0, 5)" :key="'w'+i" class="warning-item">{{ w }}</div>
        <div v-if="result.warnings.length > 5">{{ t('learning', '...and {n} more warnings', { n: result.warnings.length - 5 }) }}</div>
      </div>
      <div v-if="result.errors && result.errors.length > 0" class="error-list">
        <strong>{{ t('learning', '{n} skipped:', { n: result.errors.length }) }}</strong>
        <div v-for="(err, i) in result.errors.slice(0, 5)" :key="'e'+i">{{ err }}</div>
        <div v-if="result.errors.length > 5">{{ t('learning', '...and {n} more', { n: result.errors.length - 5 }) }}</div>
      </div>
    </NcNoteCard>
    <NcNoteCard v-if="result && result.imported === 0" type="error">
      {{ t('learning', 'Import failed — no questions could be imported') }}
      <div v-if="result.errors" class="error-list">
        <div v-for="(err, i) in result.errors.slice(0, 5)" :key="i">{{ err }}</div>
        <div v-if="result.errors.length > 5">{{ t('learning', '...and {n} more', { n: result.errors.length - 5 }) }}</div>
      </div>
    </NcNoteCard>

    <template #actions>
      <NcButton variant="tertiary" @click="$emit('close')">{{ result ? t('learning', 'Done') : t('learning', 'Cancel') }}</NcButton>
      <NcButton v-if="!result" variant="primary" @click="doImport" :disabled="importing || !textData">{{ importing ? t('learning', 'Importing...') : t('learning', 'Import') }}</NcButton>
    </template>
  </AccessibleDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess } from '@nextcloud/dialogs';
import AccessibleDialog from './AccessibleDialog.vue';
import { analyzeCsvImport, analyzeJsonImport } from '../utils/importPreview.js';

const CSV_EXAMPLE = `What is the capital of France?,Berlin,Paris,London,Madrid,2,Paris has been the capital since the 10th century
What color is the sky?,Green,Blue,Red,Yellow,2
How many days in a week?,5,6,7,8,3,Seven days from Monday to Sunday`;

const JSON_EXAMPLE = JSON.stringify([
  {"text":"What is the capital of France?","answers":[{"text":"Berlin","is_correct":false},{"text":"Paris","is_correct":true},{"text":"London","is_correct":false}],"explanation":"Paris has been the capital since the 10th century"},
  {"text":"What color is the sky?","answers":[{"text":"Green","is_correct":false},{"text":"Blue","is_correct":true},{"text":"Red","is_correct":false}]},
  {"text":"How many days in a week?","answers":[{"text":"Five","is_correct":false},{"text":"Six","is_correct":false},{"text":"Seven","is_correct":true}]}
], null, 2);

/**
 * True only for text that really is JSON (an object or a list). A CSV whose first field starts
 * with "[" or "{" (e.g. "[Network] Which port…") must stay CSV.
 */
function looksLikeJson(text) {
  const trimmed = String(text || '').replace(/^\uFEFF/, '').trim();
  if (!trimmed.startsWith('[') && !trimmed.startsWith('{')) return false;
  try {
    const parsed = JSON.parse(trimmed);
    return parsed !== null && typeof parsed === 'object';
  } catch (e) {
    return false;
  }
}

/**
 * The parse error of text that starts like JSON ("[" or "{") but is not valid JSON, else null.
 * Such text stays on the CSV tab (it may be a CSV like "[Network] Which port…"); when the CSV
 * reading finds nothing to import, the dialog says it looks like broken JSON.
 */
function jsonParseError(text) {
  const trimmed = String(text || '').replace(/^\uFEFF/, '').trim();
  if (!trimmed.startsWith('[') && !trimmed.startsWith('{')) return null;
  try {
    JSON.parse(trimmed);
    return null;
  } catch (e) {
    return e && e.message ? e.message : String(e);
  }
}

/** The server translates its messages itself (IL10N); only make `imported` a number. */
function normalizeResult(data) {
  const out = { ...(data || {}) };
  if (typeof out.imported !== 'number') out.imported = Number(out.imported) || 0;
  return out;
}

export default {
  name: 'ImportDialog',
  components: { AccessibleDialog, NcButton, NcNoteCard },
  props: { poolId: { type: Number, required: true } },
  data() {
    return { format: 'csv', textData: '', fileName: '', importing: false, result: null, preview: [], previewError: '', previewSummary: null, dragging: false, formatChosen: false };
  },
  computed: {
    /** Broken JSON pasted on the CSV tab: name the parse error and the JSON tab (GitHub #34). */
    jsonHint() {
      if (this.format !== 'csv') return '';
      const message = jsonParseError(this.textData);
      if (message === null) return '';
      if (this.previewSummary && this.previewSummary.importable > 0) return '';
      return t('learning', 'This looks like JSON, but it is not valid JSON: {message}. If it is meant to be JSON, choose the JSON tab.', { message }, undefined, { escape: false });
    }
  },
  watch: {
    textData(text) {
      // GitHub #34: pasted JSON went to the CSV parser, because format detection ran only for
      // files. Text that parses as a JSON object or list switches the tab (only from CSV, never
      // back, and never once the user picked the format by hand).
      if (this.format === 'csv' && !this.formatChosen && looksLikeJson(text)) {
        this.format = 'json';
        return; // the format watcher updates the preview
      }
      this.updatePreview(); this.result = null;
    },
    format() { this.updatePreview(); this.result = null; }
  },
  methods: {
    chooseFormat(format) {
      // Clicking the tab that is already active is no choice: automatic detection stays on.
      if (format === this.format) return;
      this.formatChosen = true;
      this.format = format;
    },
    loadExample() {
      this.textData = this.format === 'csv' ? CSV_EXAMPLE : JSON_EXAMPLE;
      this.fileName = '';
    },
    handleFile(e) {
      const file = e.target.files[0];
      if (!file) return;
      this.loadFile(file);
    },
    handleDrop(e) {
      this.dragging = false;
      const file = e.dataTransfer.files[0];
      if (!file) return;
      this.loadFile(file);
    },
    loadFile(file) {
      this.fileName = file.name;
      // Auto-detect format from extension
      const ext = file.name.split('.').pop().toLowerCase();
      if (ext === 'json') {
        this.format = 'json';
      } else if (ext === 'csv' || ext === 'txt') {
        this.format = 'csv';
      }
      const reader = new FileReader();
      reader.onload = (ev) => {
        let text = ev.target.result;
        // Auto-detect JSON content even for .txt/.csv files (only if it really parses as JSON)
        if (looksLikeJson(text)) {
          this.format = 'json';
        }
        this.textData = text;
      };
      reader.readAsText(file);
    },
    clearFile() {
      this.fileName = '';
      this.textData = '';
      this.preview = [];
      this.result = null;
      this.formatChosen = false;
      if (this.$refs.fileInput) this.$refs.fileInput.value = '';
    },
    previewDetail(item) {
      if (item.kind === 'pbq') return t('learning', 'simulation (PBQ)');
      if (item.kind === 'open') return t('learning', 'open question');
      return item.answerCount === 1 ? t('learning', '1 answer') : t('learning', '{n} answers', { n: item.answerCount });
    },
    updatePreview() {
      this.previewError = '';
      this.previewSummary = null;
      if (!this.textData.trim()) { this.preview = []; return; }
      try {
        // Same rules as the server's readers (GitHub #34): never "(4 answers)" for an item the
        // server will refuse, a parse error is shown instead of an empty preview, and a quoted
        // CSV field may hold commas, semicolons and line breaks.
        const analysis = this.format === 'json' ? analyzeJsonImport(this.textData) : analyzeCsvImport(this.textData);
        if (analysis.error) {
          this.preview = [];
          this.previewError = analysis.error;
          return;
        }
        this.preview = analysis.items.slice(0, 3).map(item => ({ ...item, text: item.text.substring(0, 80) }));
        this.previewSummary = analysis;
      } catch (e) {
        this.preview = [];
        this.previewError = t('learning', 'The preview failed: {message}', { message: e && e.message ? e.message : String(e) }, undefined, { escape: false });
      }
    },
    async doImport() {
      this.importing = true; this.result = null;
      try {
        const endpoint = this.format === 'csv' ? 'csv' : 'json';
        const payload = this.format === 'csv' ? { csvData: this.textData } : { jsonData: this.textData };
        const response = await axios.post(generateUrl('/apps/learning/api/pools/' + this.poolId + '/import/' + endpoint), payload);
        this.result = normalizeResult(response.data);
        if (this.result.imported > 0) { showSuccess(t('learning', '{n} questions imported', { n: this.result.imported })); this.$emit('imported', this.result.imported); }
      } catch (error) {
        const status = error.response?.status;
        const data = error.response?.data;
        if (status === 429) {
          // UserRateLimit on the import routes: not a format problem, so do not say it is one.
          this.result = { imported: 0, errors: [t('learning', 'Too many imports in a short time. Please wait a minute and try again.')] };
        } else if (data && Array.isArray(data.errors) && data.errors.length > 0) {
          this.result = normalizeResult(data);
        } else {
          this.result = {
            imported: 0,
            errors: [data?.error || t('learning', 'Import failed. Check your data format.')]
          };
        }
      } finally { this.importing = false; }
    }
  }
};
</script>

<style scoped>
.format-tabs { display: flex; gap: 4px; margin-bottom: 16px; padding: 4px; background: var(--color-background-hover); border-radius: 8px; max-width: 200px; }
.tab-btn { flex: 1; padding: 8px 16px; border: none; background: transparent; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s; min-height: 40px; color: var(--color-main-text); }
.tab-btn:hover { background: var(--color-background-dark); }
.tab-btn.active { background: var(--color-primary-element); color: var(--color-primary-element-text); }
.format-help { margin-bottom: 16px; }
.format-help code { display: inline-block; padding: 4px 8px; background: var(--color-background-dark); border-radius: 4px; font-size: 12px; word-break: break-all; margin: 4px 0; }
.input-section { margin-bottom: 16px; }
.dropzone { border: 2px dashed var(--color-border); border-radius: var(--border-radius-large); padding: 24px; text-align: center; cursor: pointer; transition: all 0.2s; background: var(--color-background-hover); }
.dropzone:hover, .dropzone--active { border-color: var(--color-primary-element); background: var(--color-primary-element-light); }
.dropzone-hint { color: var(--color-text-maxcontrast); font-size: 14px; }
.dropzone-file { font-size: 14px; }
.dropzone-file small { color: var(--color-text-maxcontrast); margin-left: 8px; }
.input-actions { display: flex; gap: 8px; margin-top: 8px; }
.example-code { display: block; margin: 2px 0; }
.or-divider { text-align: center; font-size: 12px; color: var(--color-text-maxcontrast); margin: 8px 0; }
.warning-list { margin-top: 8px; font-size: 12px; color: var(--color-warning-text); }
.warning-item { margin-bottom: 2px; }
.nc-input { width: 100%; padding: 10px 12px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); font-size: 14px; background: var(--color-main-background); color: var(--color-main-text); transition: border-color 0.2s; box-sizing: border-box; }
.nc-input:focus { border-color: var(--color-primary-element); outline: none; }
.data-input { font-family: monospace; font-size: 13px; resize: vertical; }
.preview-section { margin-bottom: 16px; }
.preview-section h4 { font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--color-main-text); }
.preview-item { padding: 6px 10px; background: var(--color-background-hover); border-radius: 4px; font-size: 13px; margin-bottom: 4px; color: var(--color-main-text); }
.preview-answers { color: var(--color-text-maxcontrast); font-size: 12px; }
.preview-item--error { border-left: 3px solid var(--color-error); }
.preview-item-error { color: var(--color-error-text); font-size: 12px; }
.preview-summary { font-size: 13px; margin-top: 8px; }
.preview-error { margin-bottom: 16px; }
.error-list { margin-top: 8px; font-size: 12px; }
</style>
