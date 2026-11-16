<template>
  <section v-if="visible" class="rich-feedback" :aria-label="t('learning', 'Feedback zu den Antworten')">
    <ul v-if="options.length" class="rich-feedback__options">
      <li v-for="option in options"
          :key="option.answerId"
          class="rich-feedback__option"
          :class="option.isCorrect ? 'rich-feedback__option--correct' : 'rich-feedback__option--wrong'">
        <div class="rich-feedback__head">
          <span class="rich-feedback__verdict">{{ option.isCorrect ? t('learning', 'Richtig') : t('learning', 'Falsch') }}</span>
          <span v-if="option.selected" class="rich-feedback__chosen">{{ t('learning', 'Deine Wahl') }}</span>
          <span v-else class="rich-feedback__chosen">{{ t('learning', 'Richtige Antwort') }}</span>
          <span class="rich-feedback__answer">{{ option.text }}</span>
        </div>
        <p v-if="option.why" class="rich-feedback__text">{{ option.why }}</p>
        <p v-if="option.misconception && !option.isCorrect" class="rich-feedback__text rich-feedback__misconception">
          <strong>{{ t('learning', 'Denkfehler:') }}</strong> {{ option.misconception }}
        </p>
      </li>
    </ul>
    <p v-if="backgroundFact" class="rich-feedback__fact">
      <strong>{{ t('learning', 'Hintergrund:') }}</strong> {{ backgroundFact }}
    </p>
    <p v-if="keyTakeaway" class="rich-feedback__takeaway">
      <strong>{{ t('learning', 'Merksatz:') }}</strong> {{ keyTakeaway }}
    </p>
  </section>
</template>

<script>
import { optionFeedbackToShow } from '../utils/richFeedback.js';

/**
 * GitHub #66: what a learner reads after answering — feedback for the chosen option(s), and for
 * the right one(s) when the answer was wrong; background fact and key takeaway. Shown in
 * training, Leitner, Smart Queue and the review of a practice exam. The server sends
 * `rich_feedback` only where the solution may be revealed (never in or after a counting exam).
 * Plain text, line breaks kept.
 */
export default {
  name: 'RichFeedbackPanel',
  props: {
    // `rich_feedback` of the answer response or review entry
    rich: { type: Object, default: null },
    // the answers as shown, for their text and order
    answers: { type: Array, default: () => [] },
    selectedIds: { type: Array, default: () => [] },
  },
  computed: {
    // From what is rendered, not from the payload: option feedback that names no shown answer
    // (only for the wrong options, answered right) must not leave an empty named region.
    visible() {
      return this.options.length > 0 || this.backgroundFact !== '' || this.keyTakeaway !== '';
    },
    options() {
      return optionFeedbackToShow(this.rich, this.answers, this.selectedIds);
    },
    backgroundFact() {
      return (this.rich?.background_fact || '').trim();
    },
    keyTakeaway() {
      return (this.rich?.key_takeaway || '').trim();
    },
  },
};
</script>

<style scoped>
.rich-feedback { margin: 12px 0; text-align: start; }
.rich-feedback__options { list-style: none; margin: 0 0 8px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.rich-feedback__option {
  padding: 10px 14px;
  border-inline-start: 4px solid var(--color-border-dark);
  border-radius: var(--border-radius-large);
  background: var(--color-background-hover);
  color: var(--color-main-text);
}
.rich-feedback__option--correct { border-inline-start-color: var(--color-element-success, var(--color-success-text)); }
.rich-feedback__option--wrong { border-inline-start-color: var(--color-element-error, var(--color-error-text)); }
.rich-feedback__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 10px; }
.rich-feedback__verdict { font-weight: 700; }
.rich-feedback__option--correct .rich-feedback__verdict { color: var(--color-success-text); }
.rich-feedback__option--wrong .rich-feedback__verdict { color: var(--color-error-text); }
.rich-feedback__chosen { font-size: 12px; color: var(--color-text-maxcontrast); }
.rich-feedback__answer { font-weight: 500; overflow-wrap: anywhere; }
.rich-feedback__text, .rich-feedback__fact, .rich-feedback__takeaway {
  margin: 6px 0 0;
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.rich-feedback__misconception { color: var(--color-text-maxcontrast); }
.rich-feedback__takeaway {
  padding: 8px 12px;
  border-radius: var(--border-radius-large);
  background: var(--color-primary-element-light);
  color: var(--color-primary-element-light-text);
}
</style>
