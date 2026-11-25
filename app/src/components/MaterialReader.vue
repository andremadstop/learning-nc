<template>
  <AccessibleDialog
    :name="fileName"
    size="large"
    class="material-reader-dialog"
    @closing="$emit('close')">
    <p v-if="state === 'loading'" class="material-reader__status" role="status">
      {{ t('learning', 'Material wird geladen …') }}
    </p>
    <p v-else-if="state === 'error'" class="material-reader__status material-reader__status--error" role="alert">
      {{ t('learning', 'Das Material konnte nicht geladen werden.') }}
    </p>
    <p v-else-if="state === 'too_large'" class="material-reader__status" role="status">
      {{ t('learning', 'Diese Datei ist zu groß für die Leseansicht. Öffne sie als Text.') }}
    </p>
    <!-- GitHub #110: sanitized by renderMaterialMarkdown() (markdown-it html:false + DOMPurify) -->
    <!-- eslint-disable-next-line vue/no-v-html -->
    <article v-else class="material-reader__body" dir="auto" data-test="material-reader-body" v-html="html" />
    <template #actions>
      <a
        class="material-reader__raw"
        :href="url"
        target="_blank"
        rel="noopener noreferrer"
        data-test="material-reader-raw">{{ t('learning', 'Als Text öffnen') }}</a>
      <NcButton variant="primary" @click="$emit('close')">
        {{ t('learning', 'Close') }}
      </NcButton>
    </template>
  </AccessibleDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import NcButton from '@nextcloud/vue/components/NcButton'
import { translate as t } from '@nextcloud/l10n'
import AccessibleDialog from './AccessibleDialog.vue'
import { renderMaterialMarkdown, tooLargeToRender } from '../utils/materialMarkdown.js'

/**
 * GitHub #110: reads a Markdown course material inside Learning. The file comes from the same
 * download route as before (text/plain + nosniff, and the route records the open of a required
 * document), forced to text so a file whose content happens to be JSON is not parsed. The
 * rendering is sanitized in renderMaterialMarkdown().
 */
export default {
  name: 'MaterialReader',
  components: { AccessibleDialog, NcButton },
  props: {
    url: { type: String, required: true },
    fileName: { type: String, default: '' },
  },
  emits: ['close', 'loaded'],
  data() {
    return { state: 'loading', html: '' }
  },
  watch: {
    url() {
      this.load()
    },
  },
  mounted() {
    this.load()
  },
  methods: {
    t,
    async load() {
      this.state = 'loading'
      this.html = ''
      const url = this.url
      try {
        const response = await axios.get(url, { responseType: 'text', transformResponse: [(data) => data] })
        if (url !== this.url) return
        // the request recorded the open of a required document: the caller reads its state
        // again, also when rendering fails below
        this.$emit('loaded')
        const text = typeof response.data === 'string' ? response.data : ''
        if (tooLargeToRender(text)) {
          this.state = 'too_large'
          return
        }
        try {
          this.html = renderMaterialMarkdown(text)
          this.state = 'ready'
        } catch (e) {
          this.state = 'error'
        }
      } catch (e) {
        if (url === this.url) this.state = 'error'
      }
    },
  },
}
</script>

<style scoped>
.material-reader__status { margin: 12px 0; }
.material-reader__status--error { color: var(--color-error-text, #b00020); font-weight: 600; }
.material-reader__raw { margin-inline-end: auto; align-self: center; text-decoration: underline; }
.material-reader__body {
  line-height: 1.6;
  overflow-wrap: anywhere;
  max-width: 75ch;
}
.material-reader__body :deep(h1) { font-size: 1.6em; font-weight: 700; margin: 0.6em 0 0.4em; }
/* the theme gives h1 a lighter weight than h2; one heading scale, heaviest first */
.material-reader__body :deep(h2),
.material-reader__body :deep(h3) { font-weight: 700; }
.material-reader__body :deep(h2) { font-size: 1.35em; margin: 1em 0 0.4em; }
.material-reader__body :deep(h3) { font-size: 1.15em; margin: 1em 0 0.3em; }
.material-reader__body :deep(p),
.material-reader__body :deep(ul),
.material-reader__body :deep(ol),
.material-reader__body :deep(blockquote),
.material-reader__body :deep(pre) { margin: 0 0 0.8em; }
.material-reader__body :deep(ul),
.material-reader__body :deep(ol) { padding-inline-start: 1.5em; }
.material-reader__body :deep(ul) { list-style: disc; }
.material-reader__body :deep(ol) { list-style: decimal; }
.material-reader__body :deep(blockquote) {
  border-inline-start: 4px solid var(--color-border-dark, #999);
  padding-inline-start: 12px;
  color: var(--color-text-maxcontrast, #6b6b6b);
}
.material-reader__body :deep(code) {
  font-family: var(--font-face-monospace, monospace);
  background: var(--color-background-dark, #f0f0f0);
  border-radius: 4px;
  padding: 0 4px;
}
.material-reader__body :deep(pre) {
  overflow-x: auto;
  background: var(--color-background-dark, #f0f0f0);
  border-radius: var(--border-radius-large, 8px);
  padding: 12px;
}
.material-reader__body :deep(pre code) { padding: 0; background: none; }
.material-reader__body :deep(table) {
  display: block;
  overflow-x: auto;
  border-collapse: collapse;
  margin: 0 0 1em;
}
.material-reader__body :deep(th),
.material-reader__body :deep(td) {
  border: 1px solid var(--color-border-dark, #999);
  padding: 6px 10px;
  text-align: start;
}
.material-reader__body :deep(th) { background: var(--color-background-dark, #f0f0f0); font-weight: 600; }
.material-reader__body :deep(img) { max-width: 100%; height: auto; }
.material-reader__body :deep(a) { color: var(--color-primary-element, #00679e); text-decoration: underline; }
.material-reader__body :deep(hr) { border: 0; border-top: 1px solid var(--color-border, #ddd); margin: 1.2em 0; }
</style>
