<template>
  <NcDialog :name="t('learning', 'Import Questions')" @closing="$emit('close')" size="normal">
    <div class="format-tabs" role="tablist">
      <button @click="format = 'csv'" :class="['tab-btn', { active: format === 'csv' }]" role="tab" :aria-selected="format === 'csv' ? 'true' : 'false'">{{ t('learning', 'CSV') }}</button>
      <button @click="format = 'json'" :class="['tab-btn', { active: format === 'json' }]" role="tab" :aria-selected="format === 'json' ? 'true' : 'false'">{{ t('learning', 'JSON') }}</button>
    </div>

    <NcNoteCard type="info" class="format-help">
      <template v-if="format === 'csv'">
        <strong>{{ t('learning', 'CSV Format:') }}</strong><br/>
        <code>question,answer1,answer2,answer3,answer4,correct_number,explanation</code><br/>
        <small>{{ t('learning', 'correct_number = 1-4. Explanation is optional.') }}</small>
      </template>
      <template v-else>
        <strong>{{ t('learning', 'JSON Format:') }}</strong><br/>
        <code>[{"text":"Q?","answers":[{"text":"A","is_correct":true},{"text":"B","is_correct":false}]}]</code><br/>
        <small>{{ t('learning', 'Optional: difficulty, explanation') }}</small>
      </template>
    </NcNoteCard>

    <div class="input-section">
      <div class="file-upload">
        <label class="file-label">
          <NcButton type="secondary">{{ t('learning', 'Choose File') }}</NcButton>
          <input type="file" :accept="format === 'csv' ? '.csv,.txt' : '.json'" @change="handleFile" hidden />
        </label>
        <span v-if="fileName" class="file-name">{{ fileName }}</span>
      </div>
      <p class="or-divider">{{ t('learning', '— or paste below —') }}</p>
      <textarea v-model="textData" :placeholder="format === 'csv' ? t('learning', 'Paste CSV data here...') : t('learning', 'Paste JSON data here...')" rows="8" class="nc-input data-input"></textarea>
    </div>

    <div v-if="preview.length > 0" class="preview-section">
      <h4>{{ t('learning', 'Preview (first {n} items)', { n: preview.length }) }}</h4>
      <div v-for="(item, i) in preview" :key="i" class="preview-item">
        <strong>Q{{ i + 1 }}:</strong> {{ item.text }} <span class="preview-answers">({{ item.answerCount }} answers)</span>
      </div>
    </div>

    <NcNoteCard v-if="result && result.imported > 0" type="success">
      <strong>{{ t('learning', '{n} questions imported', { n: result.imported }) }}</strong>
      <span v-if="result.errors && result.errors.length > 0"> ({{ result.errors.length }} {{ t('learning', 'errors') }})</span>
    </NcNoteCard>
    <NcNoteCard v-if="result && result.imported === 0" type="error">
      {{ t('learning', 'Import failed') }}
      <div v-if="result.errors" class="error-list">
        <div v-for="(err, i) in result.errors.slice(0, 5)" :key="i">{{ err }}</div>
        <div v-if="result.errors.length > 5">{{ t('learning', '...and {n} more', { n: result.errors.length - 5 }) }}</div>
      </div>
    </NcNoteCard>

    <template #actions>
      <NcButton type="tertiary" @click="$emit('close')">{{ result ? t('learning', 'Done') : t('learning', 'Cancel') }}</NcButton>
      <NcButton v-if="!result" type="primary" @click="doImport" :disabled="importing || !textData">{{ importing ? t('learning', 'Importing...') : t('learning', 'Import') }}</NcButton>
    </template>
  </NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';

export default {
  name: 'ImportDialog',
  components: { NcDialog, NcButton, NcNoteCard },
  props: { poolId: { type: Number, required: true } },
  data() {
    return { format: 'csv', textData: '', fileName: '', importing: false, result: null, preview: [] };
  },
  watch: {
    textData() { this.updatePreview(); this.result = null; },
    format() { this.updatePreview(); this.result = null; }
  },
  methods: {
    handleFile(e) {
      const file = e.target.files[0];
      if (!file) return;
      this.fileName = file.name;
      const reader = new FileReader();
      reader.onload = (ev) => { this.textData = ev.target.result; };
      reader.readAsText(file);
    },
    updatePreview() {
      if (!this.textData.trim()) { this.preview = []; return; }
      try {
        if (this.format === 'json') {
          let data = JSON.parse(this.textData);
          if (data.questions) data = data.questions;
          this.preview = data.slice(0, 3).map(item => ({ text: (item.text || '').substring(0, 80), answerCount: (item.answers || []).length }));
        } else {
          const lines = this.textData.trim().split('\n').filter(l => l.trim());
          let start = 0;
          if (lines[0] && lines[0].toLowerCase().includes('question')) start = 1;
          this.preview = lines.slice(start, start + 3).map(line => {
            const fields = line.split(',');
            return { text: (fields[0] || '').substring(0, 80), answerCount: Math.max(0, fields.length - 2) };
          });
        }
      } catch (e) { this.preview = []; }
    },
    async doImport() {
      this.importing = true; this.result = null;
      try {
        const endpoint = this.format === 'csv' ? 'csv' : 'json';
        const payload = this.format === 'csv' ? { csvData: this.textData } : { jsonData: this.textData };
        const response = await axios.post(generateUrl('/apps/learning/api/pools/' + this.poolId + '/import/' + endpoint), payload);
        this.result = response.data;
        if (this.result.imported > 0) { showSuccess(t('learning', '{n} questions imported', { n: this.result.imported })); this.$emit('imported', this.result.imported); }
      } catch (error) {
        const data = error.response?.data;
        if (data && data.errors) { this.result = data; }
        else { showError(data?.error || t('learning', 'Import failed')); }
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
.file-upload { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.file-label { cursor: pointer; }
.file-name { font-size: 13px; color: var(--color-text-maxcontrast); }
.or-divider { text-align: center; font-size: 12px; color: var(--color-text-maxcontrast); margin: 8px 0; }
.nc-input { width: 100%; padding: 10px 12px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); font-size: 14px; background: var(--color-main-background); color: var(--color-main-text); transition: border-color 0.2s; box-sizing: border-box; }
.nc-input:focus { border-color: var(--color-primary-element); outline: none; }
.data-input { font-family: monospace; font-size: 13px; resize: vertical; }
.preview-section { margin-bottom: 16px; }
.preview-section h4 { font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--color-main-text); }
.preview-item { padding: 6px 10px; background: var(--color-background-hover); border-radius: 4px; font-size: 13px; margin-bottom: 4px; color: var(--color-main-text); }
.preview-answers { color: var(--color-text-maxcontrast); font-size: 12px; }
.error-list { margin-top: 8px; font-size: 12px; }
</style>