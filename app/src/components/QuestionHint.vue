<template>
  <div v-if="text" class="question-hint">
    <NcButton variant="tertiary"
              size="small"
              :aria-expanded="open ? 'true' : 'false'"
              :aria-controls="panelId"
              @click="open = !open">
      <template #icon>
        <span aria-hidden="true">💡</span>
      </template>
      {{ open ? t('learning', 'Hinweis ausblenden') : t('learning', 'Hinweis anzeigen') }}
    </NcButton>
    <p v-show="open" :id="panelId" class="question-hint__text" role="note">{{ text }}</p>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton';
import { questionHint } from '../utils/richFeedback.js';

let nextId = 0;

/**
 * GitHub #66: the hint of a question, behind a button, before the learner answers. The server
 * never sends one in an exam attempt; the parent shows this only while the question is open.
 * Plain text with line breaks — no HTML.
 */
export default {
  name: 'QuestionHint',
  components: { NcButton },
  props: {
    question: { type: Object, default: null },
  },
  data() {
    nextId += 1;
    return { open: false, panelId: 'question-hint-' + nextId };
  },
  computed: {
    text() {
      return questionHint(this.question);
    },
  },
  watch: {
    // A new question starts with its hint closed.
    'question.id'() {
      this.open = false;
    },
  },
};
</script>

<style scoped>
.question-hint { margin: 0 0 12px; text-align: start; }
.question-hint__text {
  margin: 6px 0 0;
  padding: 10px 14px;
  border-inline-start: 4px solid var(--color-warning);
  border-radius: var(--border-radius-large);
  background: var(--color-background-hover);
  color: var(--color-main-text);
  white-space: pre-line;
  overflow-wrap: anywhere;
}
</style>
