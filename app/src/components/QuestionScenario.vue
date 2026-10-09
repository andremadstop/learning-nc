<template>
  <section
    v-if="text"
    class="question-scenario"
    :class="{ 'question-scenario--compact': compact }"
    :aria-label="t('learning', 'Szenario')"
  >
    <div class="question-scenario__label" aria-hidden="true">{{ t('learning', 'Szenario') }}</div>
    <div class="question-scenario__text">{{ text }}</div>
  </section>
</template>

<script>
import { questionScenario } from '../utils/questionScenario.js';

/**
 * Scenario / info text shown before a question (Codeberg #10).
 * Plain text with line breaks preserved — no HTML, no markdown.
 */
export default {
  name: 'QuestionScenario',
  props: {
    question: { type: Object, default: null },
    compact: { type: Boolean, default: false },
  },
  computed: {
    text() {
      return questionScenario(this.question);
    },
  },
};
</script>

<style scoped>
.question-scenario {
  margin: 0 0 16px;
  padding: 12px 16px;
  border-inline-start: 4px solid var(--color-primary-element);
  border-radius: var(--border-radius-large);
  background: var(--color-background-hover);
  color: var(--color-main-text);
  text-align: start;
}
.question-scenario__label {
  margin-bottom: 6px;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-maxcontrast);
}
.question-scenario__text {
  font-size: 15px;
  line-height: 1.55;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.question-scenario--compact {
  margin: 4px 0 8px;
  padding: 8px 12px;
}
.question-scenario--compact .question-scenario__text {
  font-size: 13px;
}
</style>
