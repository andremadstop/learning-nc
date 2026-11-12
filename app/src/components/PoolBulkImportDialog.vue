<template>
  <!-- No closing while a request runs: a dialog closed during the real import would drop its
       'imported' event (Vue ignores emits of an unmounted component), and the list would not
       show pools that were created — an invitation to import the same file twice. -->
  <AccessibleDialog :name="t('learning', 'Import pools')" size="large" :no-close="busy" @closing="close">
    <!-- Step 1: the file. Nothing is sent before "Check file": the endpoint allows 5 requests
         a minute, and the preview counts as one. -->
    <template v-if="step === 'input'">
      <NcNoteCard type="info" class="pool-import__help">
        {{ t('learning', 'Creates every pool of a JSON file together with its questions, in the format of "occ learning:import-pool-json": {"_meta": …, "pools": [{"name": …, "questions": […]}, …]}. A list of questions or a pool export becomes one pool named after the file.') }}
      </NcNoteCard>

      <div
        class="pool-import__dropzone"
        :class="{ 'pool-import__dropzone--active': dragging }"
        role="button"
        tabindex="0"
        :aria-label="t('learning', 'Drop a JSON file here — or click to browse')"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="handleDrop"
        @click="browse"
        @keydown.enter.prevent="browse"
        @keydown.space.prevent="browse">
        <input ref="fileInput" type="file" accept=".json,application/json" class="pool-import__file-input" @change="handleFile">
        <div v-if="fileName" class="pool-import__file">
          <strong>{{ fileName }}</strong>
          <small>({{ Math.round(jsonData.length / 1024) }} KB)</small>
        </div>
        <div v-else class="pool-import__hint">
          {{ t('learning', 'Drop a JSON file here — or click to browse') }}
        </div>
      </div>
      <div v-if="fileName" class="pool-import__file-actions">
        <NcButton type="button" variant="tertiary" @click="clearFile">{{ t('learning', 'Clear') }}</NcButton>
      </div>
      <template v-else>
        <p class="pool-import__or">{{ t('learning', '— or paste below —') }}</p>
        <label for="pool-import-json" class="hidden-visually">{{ t('learning', 'Paste JSON data here...') }}</label>
        <textarea
          id="pool-import-json"
          v-model="jsonData"
          class="pool-import__textarea"
          rows="8"
          :placeholder="t('learning', 'Paste JSON data here...')" />
      </template>
    </template>

    <!-- Step 2: the preview. Only what the server's dry run answered; the client checks nothing. -->
    <div v-if="step === 'preview' && preview" class="pool-import__preview">
      <p ref="previewHeading" class="pool-import__summary" tabindex="-1">
        <strong>{{ t('learning', '{importable} of {total} questions can be imported, in {pools} pools', { importable: preview.total_importable, total: preview.total_questions, pools: preview.total_pools }) }}</strong>
      </p>
      <p class="pool-import__note">{{ t('learning', 'Nothing has been saved yet. The preview shows what the server would import.') }}</p>
      <table class="pool-import__table">
        <thead>
          <tr>
            <th scope="col">{{ t('learning', 'Pool') }}</th>
            <th scope="col">{{ t('learning', 'Importable / total') }}</th>
            <th scope="col">{{ t('learning', 'Messages') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="pool in preview.pools" :key="'p' + pool.index" class="pool-import__row" :class="{ 'pool-import__row--error': pool.errors.length > 0 }">
            <td class="pool-import__name">{{ poolLabel(pool) }}</td>
            <td class="pool-import__count">{{ pool.importable }} / {{ pool.questions_total }}</td>
            <td><PoolImportMessages :pool="pool" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Step 3: the result, per pool as the server reports it. -->
    <div v-if="step === 'result' && result" class="pool-import__result">
      <NcNoteCard :type="result.created_pools > 0 ? 'success' : 'error'">
        <span ref="resultHeading" tabindex="-1" class="pool-import__result-heading">
          <strong v-if="result.created_pools > 0">{{ t('learning', 'Pools created: {pools} · questions imported: {questions}', { pools: result.created_pools, questions: result.imported_questions }) }}</strong>
          <strong v-else>{{ t('learning', 'No pool was created.') }}</strong>
        </span>
      </NcNoteCard>
      <table class="pool-import__table">
        <thead>
          <tr>
            <th scope="col">{{ t('learning', 'Pool') }}</th>
            <th scope="col">{{ t('learning', 'Status') }}</th>
            <th scope="col">{{ t('learning', 'Imported / total') }}</th>
            <th scope="col">{{ t('learning', 'Messages') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="pool in result.pools" :key="'r' + pool.index" class="pool-import__row" :class="{ 'pool-import__row--error': pool.id === null }">
            <td class="pool-import__name">{{ poolLabel(pool) }}</td>
            <td class="pool-import__status">{{ pool.id !== null ? t('learning', 'Created') : t('learning', 'Not created') }}</td>
            <td class="pool-import__count">{{ pool.imported }} / {{ pool.questions_total }}</td>
            <td><PoolImportMessages :pool="pool" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <NcNoteCard v-if="error" type="error" class="pool-import__error" role="alert">
      {{ error }}
    </NcNoteCard>

    <template #actions>
      <template v-if="step === 'input'">
        <NcButton type="button" variant="tertiary" @click="close">{{ t('learning', 'Cancel') }}</NcButton>
        <NcButton type="button" variant="primary" class="pool-import__check" :disabled="busy || !jsonData.trim()" @click="checkFile">
          {{ busy ? t('learning', 'Checking...') : t('learning', 'Check file') }}
        </NcButton>
      </template>
      <template v-else-if="step === 'preview'">
        <NcButton type="button" variant="tertiary" :disabled="busy" @click="backToInput">{{ t('learning', 'Back') }}</NcButton>
        <NcButton type="button" variant="primary" class="pool-import__import" :disabled="busy || outcomeUnknown" @click="runImport">
          {{ busy ? t('learning', 'Importing...') : t('learning', 'Import pools ({n})', { n: preview.total_pools }) }}
        </NcButton>
      </template>
      <NcButton v-else type="button" variant="primary" @click="close">{{ t('learning', 'Done') }}</NcButton>
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
import PoolImportMessages from './PoolImportMessages.vue';

export const POOL_IMPORT_URL = '/apps/learning/api/pools/import';

/** A pool entry of the server's answer with the lists the template reads always present. */
function normalizePool(pool) {
  const p = pool && typeof pool === 'object' ? pool : {};
  return {
    ...p,
    id: typeof p.id === 'number' ? p.id : null,
    name: typeof p.name === 'string' ? p.name : '',
    errors: Array.isArray(p.errors) ? p.errors.map(String) : [],
    warnings: Array.isArray(p.warnings) ? p.warnings.map(String) : [],
  };
}

export default {
  name: 'PoolBulkImportDialog',
  components: {
    AccessibleDialog,
    NcButton,
    NcNoteCard,
    PoolImportMessages,
  },
  emits: ['close', 'imported', 'maybe-imported'],
  data() {
    return {
      step: 'input',
      jsonData: '',
      fileName: '',
      dragging: false,
      busy: false,
      error: '',
      preview: null,
      result: null,
      // The exact request the preview was made for; the import sends this, never newer input.
      checked: null,
      // The real import failed without an answer per pool (no response, 5xx, gateway timeout):
      // pools may exist already. The same input is not offered for import again until "Back".
      outcomeUnknown: false,
    };
  },
  watch: {
    // The buttons of the previous step are gone: put the focus on what the new step says, so
    // keyboard and screen-reader users land on the preview or the result, not on the page.
    step(step) {
      const target = step === 'preview' ? 'previewHeading' : step === 'result' ? 'resultHeading' : null;
      if (!target) return;
      this.$nextTick(() => {
        const el = this.$refs[target];
        if (el && typeof el.focus === 'function') el.focus();
      });
    },
    jsonData() {
      // Changed input is unchecked input: no preview of something else, no import of it.
      if (this.checked && this.jsonData !== this.checked.jsonData) {
        this.checked = null;
        this.preview = null;
        this.outcomeUnknown = false;
        if (this.step === 'preview') this.step = 'input';
      }
      if (this.step === 'input') this.error = '';
    },
  },
  methods: {
    close() {
      if (this.busy) return;
      this.$emit('close');
    },
    browse() {
      this.$refs.fileInput?.click();
    },
    handleFile(event) {
      const file = event.target.files && event.target.files[0];
      if (file) this.loadFile(file);
    },
    handleDrop(event) {
      this.dragging = false;
      const file = event.dataTransfer && event.dataTransfer.files[0];
      if (file) this.loadFile(file);
    },
    loadFile(file) {
      const reader = new FileReader();
      reader.onload = (ev) => {
        this.fileName = file.name;
        this.jsonData = String(ev.target.result || '');
      };
      reader.onerror = () => {
        this.error = t('learning', 'Could not read uploaded file');
      };
      reader.readAsText(file);
    },
    clearFile() {
      this.fileName = '';
      this.jsonData = '';
      this.error = '';
      if (this.$refs.fileInput) this.$refs.fileInput.value = '';
    },
    backToInput() {
      this.step = 'input';
      this.error = '';
      this.outcomeUnknown = false;
    },
    poolLabel(pool) {
      return pool.name !== '' ? pool.name : t('learning', 'Pool #{n}', { n: pool.index });
    },
    request(dryRun) {
      return axios.post(generateUrl(POOL_IMPORT_URL), {
        jsonData: this.checked.jsonData,
        file_name: this.checked.fileName,
        dry_run: dryRun,
      });
    },
    async checkFile() {
      if (this.busy || !this.jsonData.trim()) return;
      this.busy = true;
      this.error = '';
      this.checked = { jsonData: this.jsonData, fileName: this.fileName };
      try {
        const response = await this.request(true);
        const data = response.data || {};
        if (!Array.isArray(data.pools)) {
          this.checked = null;
          this.error = t('learning', 'Import failed. Check your data format.');
          return;
        }
        this.preview = {
          pools: data.pools.map(normalizePool),
          total_pools: Number(data.total_pools) || data.pools.length,
          total_questions: Number(data.total_questions) || 0,
          total_importable: Number(data.total_importable) || 0,
        };
        this.step = 'preview';
      } catch (e) {
        this.checked = null;
        this.error = this.errorMessage(e);
      } finally {
        this.busy = false;
      }
    },
    async runImport() {
      if (this.busy || !this.checked) return;
      this.busy = true;
      this.error = '';
      try {
        const response = await this.request(false);
        this.showResult(response.data);
      } catch (e) {
        const data = e && e.response ? e.response.data : null;
        const status = e && e.response ? e.response.status : 0;
        if (data && Array.isArray(data.pools)) {
          // 400 with the per-pool answer: nothing was created, and the reasons are per pool.
          this.showResult(data);
        } else if (!status || status >= 500) {
          // No answer, or the server or a gateway failed (504 after a long import): the pools
          // may have been created, each in its own transaction. No unchanged retry — that would
          // create them twice — and the list is reloaded so the instructor can look.
          this.outcomeUnknown = true;
          this.error = t('learning', 'The result of the import is unknown: some pools may already have been created. Check the pool list before importing again.');
          this.$emit('maybe-imported');
        } else {
          // 4xx without a per-pool answer (rate limit, size, rights): nothing was written.
          this.error = this.errorMessage(e);
        }
      } finally {
        this.busy = false;
      }
    },
    showResult(data) {
      const body = data || {};
      const pools = Array.isArray(body.pools) ? body.pools.map(normalizePool) : [];
      this.result = {
        pools,
        created_pools: Number(body.created_pools) || 0,
        imported_questions: Number(body.imported_questions) || 0,
      };
      this.step = 'result';
      if (this.result.created_pools > 0) {
        showSuccess(t('learning', 'Pools created: {pools} · questions imported: {questions}', { pools: this.result.created_pools, questions: this.result.imported_questions }));
        this.$emit('imported', this.result);
      }
    },
    /** The server's own message where it sent one (already translated), else one per status. */
    errorMessage(e) {
      const response = e && e.response;
      if (!response) {
        return t('learning', 'The server could not be reached. Check your connection and try again.');
      }
      const status = response.status;
      const serverMessage = response.data && typeof response.data.error === 'string' ? response.data.error : '';
      if (status === 429) {
        return t('learning', 'Too many imports in a short time. Please wait a minute and try again.');
      }
      if (status === 413) {
        // Only a proxy or PHP itself answers 413, with an HTML page; the app sends 400.
        return t('learning', 'The file is too large for the server.');
      }
      if (status === 403) {
        return serverMessage || t('learning', 'Only instructors can import pools');
      }
      return serverMessage || t('learning', 'Import failed. Check your data format.');
    },
  },
};
</script>

<style scoped>
.pool-import__help { margin-bottom: 16px; word-break: break-word; }
.pool-import__dropzone { border: 2px dashed var(--color-border); border-radius: var(--border-radius-large); padding: 24px; text-align: center; cursor: pointer; background: var(--color-background-hover); }
.pool-import__dropzone:hover, .pool-import__dropzone:focus-visible, .pool-import__dropzone--active { border-color: var(--color-primary-element); background: var(--color-primary-element-light); }
.pool-import__file-input { display: none; }
.pool-import__hint { color: var(--color-text-maxcontrast); font-size: 14px; }
.pool-import__file small { color: var(--color-text-maxcontrast); margin-inline-start: 8px; }
.pool-import__file-actions { display: flex; gap: 8px; margin-top: 8px; }
.pool-import__or { text-align: center; font-size: 12px; color: var(--color-text-maxcontrast); margin: 8px 0; }
.pool-import__textarea { width: 100%; box-sizing: border-box; font-family: monospace; font-size: 13px; padding: 10px 12px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-main-background); color: var(--color-main-text); resize: vertical; }
.pool-import__textarea:focus { border-color: var(--color-primary-element); outline: none; }
.pool-import__summary { margin: 0 0 4px; }
.pool-import__summary:focus, .pool-import__result-heading:focus { outline: none; }
.pool-import__note { margin: 0 0 12px; font-size: 13px; color: var(--color-text-maxcontrast); }
.pool-import__table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
.pool-import__table th, .pool-import__table td { text-align: start; padding: 6px 8px; border-bottom: 1px solid var(--color-border); vertical-align: top; }
.pool-import__table th { font-weight: 600; }
.pool-import__count { white-space: nowrap; }
.pool-import__name { word-break: break-word; }
.pool-import__row--error .pool-import__name { border-inline-start: 3px solid var(--color-error); }
.pool-import__error { margin-top: 12px; }
</style>
