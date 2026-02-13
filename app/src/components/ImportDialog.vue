<template>
  <div class="dialog-overlay" @click.self="$emit('close')">
    <div class="dialog-content import-dialog">
      <h3>Import Questions</h3>

      <!-- Format selector -->
      <div class="format-tabs">
        <button @click="format = 'csv'" :class="['tab-btn', { active: format === 'csv' }]">CSV</button>
        <button @click="format = 'json'" :class="['tab-btn', { active: format === 'json' }]">JSON</button>
      </div>

      <!-- CSV format help -->
      <div v-if="format === 'csv'" class="format-help">
        <p><strong>CSV Format:</strong></p>
        <code>question,answer1,answer2,answer3,answer4,correct_number,explanation</code>
        <p class="hint">correct_number = 1-4 (which answer is correct). Explanation is optional.</p>
      </div>

      <!-- JSON format help -->
      <div v-else class="format-help">
        <p><strong>JSON Format:</strong></p>
        <code>[{"text":"Question?","answers":[{"text":"A","is_correct":true},{"text":"B","is_correct":false}]}]</code>
        <p class="hint">Optional fields: difficulty, explanation</p>
      </div>

      <!-- File upload or paste -->
      <div class="input-section">
        <div class="file-upload">
          <label class="file-label button">
            📁 Choose File
            <input type="file" :accept="format === 'csv' ? '.csv,.txt' : '.json'" @change="handleFile" hidden />
          </label>
          <span v-if="fileName" class="file-name">{{ fileName }}</span>
        </div>
        <p class="or-divider">— or paste below —</p>
        <textarea
          v-model="textData"
          :placeholder="format === 'csv' ? 'Paste CSV data here...' : 'Paste JSON data here...'"
          rows="8"
          class="data-input"
        ></textarea>
      </div>

      <!-- Preview -->
      <div v-if="preview.length > 0" class="preview-section">
        <h4>Preview (first {{ preview.length }} items)</h4>
        <div v-for="(item, i) in preview" :key="i" class="preview-item">
          <strong>Q{{ i + 1 }}:</strong> {{ item.text }}
          <span class="preview-answers">({{ item.answerCount }} answers)</span>
        </div>
      </div>

      <!-- Results -->
      <div v-if="result" class="result-section" :class="result.imported > 0 ? 'success' : 'error'">
        <strong>{{ result.imported }} questions imported</strong>
        <span v-if="result.errors && result.errors.length > 0">
          ({{ result.errors.length }} errors)
        </span>
        <div v-if="result.errors && result.errors.length > 0" class="error-list">
          <div v-for="(err, i) in result.errors.slice(0, 5)" :key="i" class="error-item">{{ err }}</div>
          <div v-if="result.errors.length > 5">...and {{ result.errors.length - 5 }} more</div>
        </div>
      </div>

      <div class="dialog-actions">
        <button @click="$emit('close')" class="button">{{ result ? 'Done' : 'Cancel' }}</button>
        <button v-if="!result" @click="doImport" class="button primary" :disabled="importing || !textData">
          {{ importing ? 'Importing...' : 'Import' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';

export default {
  name: 'ImportDialog',
  props: {
    poolId: { type: Number, required: true }
  },
  data() {
    return {
      format: 'csv',
      textData: '',
      fileName: '',
      importing: false,
      result: null,
      preview: []
    };
  },
  watch: {
    textData() {
      this.updatePreview();
      this.result = null;
    },
    format() {
      this.updatePreview();
      this.result = null;
    }
  },
  methods: {
    handleFile(e) {
      const file = e.target.files[0];
      if (!file) return;
      this.fileName = file.name;
      const reader = new FileReader();
      reader.onload = (ev) => {
        this.textData = ev.target.result;
      };
      reader.readAsText(file);
    },
    updatePreview() {
      if (!this.textData.trim()) {
        this.preview = [];
        return;
      }
      try {
        if (this.format === 'json') {
          let data = JSON.parse(this.textData);
          if (data.questions) data = data.questions;
          this.preview = data.slice(0, 3).map(item => ({
            text: (item.text || '').substring(0, 80),
            answerCount: (item.answers || []).length
          }));
        } else {
          const lines = this.textData.trim().split('\n').filter(l => l.trim());
          let start = 0;
          if (lines[0] && lines[0].toLowerCase().includes('question')) start = 1;
          this.preview = lines.slice(start, start + 3).map(line => {
            const fields = line.split(',');
            return {
              text: (fields[0] || '').substring(0, 80),
              answerCount: Math.max(0, fields.length - 2)
            };
          });
        }
      } catch (e) {
        this.preview = [];
      }
    },
    async doImport() {
      this.importing = true;
      this.result = null;
      try {
        const endpoint = this.format === 'csv' ? 'csv' : 'json';
        const payload = this.format === 'csv'
          ? { csvData: this.textData }
          : { jsonData: this.textData };
        const response = await axios.post(
          generateUrl(`/apps/learning/api/pools/${this.poolId}/import/${endpoint}`),
          payload
        );
        this.result = response.data;
        if (this.result.imported > 0) {
          showSuccess(`${this.result.imported} questions imported`);
          this.$emit('imported', this.result.imported);
        }
      } catch (error) {
        const data = error.response?.data;
        if (data && data.errors) {
          this.result = data;
        } else {
          showError(data?.error || 'Import failed');
        }
      } finally {
        this.importing = false;
      }
    }
  }
};
</script>

<style scoped>
.dialog-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
}

.dialog-content {
  background: var(--color-main-background);
  border-radius: 8px;
  padding: 24px;
  width: 90%;
  max-width: 600px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 4px 16px rgba(0,0,0,0.2);
}

.dialog-content h3 { margin: 0 0 16px 0; font-size: 18px; font-weight: 600; }

.format-tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 16px;
  padding: 4px;
  background: var(--color-background-hover);
  border-radius: 8px;
  max-width: 200px;
}

.tab-btn {
  flex: 1;
  padding: 8px 16px;
  border: none;
  background: transparent;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 600;
  transition: all 0.2s;
  min-height: 40px;
}

.tab-btn:hover { background: var(--color-background-dark); }
.tab-btn.active { background: var(--color-primary); color: white; }

.format-help {
  padding: 12px;
  background: var(--color-background-hover);
  border-radius: 6px;
  margin-bottom: 16px;
  font-size: 13px;
}

.format-help p { margin: 0 0 4px 0; }
.format-help code {
  display: block;
  padding: 8px;
  background: var(--color-main-background);
  border-radius: 4px;
  font-size: 12px;
  word-break: break-all;
  margin: 8px 0;
}

.hint { color: var(--color-text-lighter); font-size: 12px; }

.input-section { margin-bottom: 16px; }

.file-upload { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.file-label { cursor: pointer; display: inline-block; }
.file-name { font-size: 13px; color: var(--color-text-lighter); }
.or-divider { text-align: center; font-size: 12px; color: var(--color-text-lighter); margin: 8px 0; }

.data-input {
  width: 100%;
  padding: 10px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  font-family: monospace;
  font-size: 13px;
  resize: vertical;
  box-sizing: border-box;
}

.preview-section { margin-bottom: 16px; }
.preview-section h4 { font-size: 13px; font-weight: 600; margin-bottom: 8px; }

.preview-item {
  padding: 6px 10px;
  background: var(--color-background-hover);
  border-radius: 4px;
  font-size: 13px;
  margin-bottom: 4px;
}

.preview-answers { color: var(--color-text-lighter); font-size: 12px; }

.result-section {
  padding: 12px;
  border-radius: 6px;
  margin-bottom: 16px;
}

.result-section.success { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
.result-section.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

.error-list { margin-top: 8px; font-size: 12px; }
.error-item { padding: 2px 0; }

.dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }

.button {
  padding: 10px 20px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  cursor: pointer;
  font-size: 14px;
  min-height: 44px;
}

.button:hover { background: var(--color-background-hover); }
.button.primary { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.button:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 480px) {
  .dialog-content { padding: 16px; }
}
</style>
