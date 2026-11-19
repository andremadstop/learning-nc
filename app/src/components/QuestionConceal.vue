<template>
  <div class="qconceal"
       :class="{ 'qconceal--hidden': hidden, 'qconceal--still': still, 'qconceal--shown': uncovered, 'qconceal--flow': hadIntro }"
       :tabindex="hidden ? 0 : (uncovered ? -1 : null)"
       :role="hidden ? 'button' : null"
       :aria-expanded="hidden ? 'false' : null"
       :aria-label="hidden ? t('learning', 'Frage, ausgeblendet bis zum Ende des Einstiegs – Antippen oder Daraufzeigen deckt sie auf') : null"
       @pointermove="onPointerMove"
       @pointerleave="cancelHover"
       @focus="onFocus"
       @click="onClick"
       @keydown.enter.space="onKey"
       @pointerdown.capture="primeSounds"
       @click.capture="primeSounds"
       @keydown.capture="primeSounds">
    <p v-if="hidden" class="qconceal__veil" aria-hidden="true">
      {{ t('learning', 'Die Frage erscheint nach dem Einstieg – oder sofort, wenn du hierher zeigst oder tippst.') }}
    </p>
    <div class="qconceal__content" :inert="hidden || null" :aria-hidden="hidden ? 'true' : null">
      <slot />
    </div>
  </div>
</template>

<script>
import { soundsOn, useOptionalQuestionMediaPrefsStore } from '../stores/questionMediaPrefsStore.js';
import { useOptionalA11yStore } from '../stores/a11yStore.js';
import { unlockAnswerSounds } from '../utils/answerSounds.js';

// How long the pointer has to rest on the hidden area before it uncovers the question: a mouse on
// its way to the player above crosses the area and must not uncover it in passing.
export const HOVER_DWELL_MS = 500;

/**
 * GitHub #66 (Andre 18.11.: "die Frage erst nach dem Intro einblenden", "kein Knopf, hovern
 * muss reichen"). While the player says a spoken intro should be heard first (`conceal`), the
 * question and its answers keep their place but are invisible and out of reach (`inert`: no
 * Tab stop, no screen-reader text, no tap on a hidden answer). The area itself uncovers them:
 * hovering it (resting on it for HOVER_DWELL_MS, so crossing it on the way to the player does not
 * count; any movement inside counts, so a pointer already inside after "Nächste Frage" works too),
 * focusing it with the keyboard (it is a Tab stop with a name) or tapping it.
 * No visible button. Once uncovered, the question stays for good. Without an intro this is a
 * plain wrapper, so every question without media looks and behaves as before.
 */
export default {
  name: 'QuestionConceal',
  props: {
    // the player's `conceal` event: a spoken intro is to be heard first
    conceal: { type: Boolean, default: false },
    // the question shown; a new one starts covered again if its intro asks for it
    questionKey: { type: [Number, String], default: null },
  },
  data() {
    return {
      userRevealed: false,
      // the question was hidden and is now shown: it glides in
      uncovered: false,
      // this question had a spoken intro: its feedback fades in (questions without one as before)
      hadIntro: false,
      reducedMotion: false,
      motionQuery: null,
      prefs: useOptionalQuestionMediaPrefsStore(),
      a11y: useOptionalA11yStore(),
      hoverTimer: null,
    };
  },
  computed: {
    hidden() {
      return this.conceal && !this.userRevealed;
    },
    still() {
      return this.reducedMotion || this.a11y?.animationsEnabled === false;
    },
  },
  watch: {
    questionKey() {
      this.cancelHover();
      this.userRevealed = false;
      this.uncovered = false;
      this.hadIntro = this.conceal;
    },
    conceal: {
      immediate: true,
      handler(now) {
        if (now) {
          this.hadIntro = true;
        }
      },
    },
    hidden(now, before) {
      if (!now && before) {
        this.uncovered = true;
      }
    },
  },
  mounted() {
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
      this.motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
      this.reducedMotion = !!this.motionQuery.matches;
      this.motionQuery.addEventListener?.('change', this.onMotionChange);
    }
  },
  beforeUnmount() {
    this.cancelHover();
    this.motionQuery?.removeEventListener?.('change', this.onMotionChange);
  },
  methods: {
    onMotionChange(event) {
      this.reducedMotion = !!event.matches;
    },
    reveal() {
      this.cancelHover();
      if (this.hidden) {
        this.userRevealed = true;
      }
    },
    /**
     * Hover intent instead of mouseenter: the area stays in place from one question to the next,
     * so a pointer resting inside it after "Nächste Frage" never "enters" it again. Touch is the
     * tap's (a finger scrolling over the area is no hover, and no leave follows it).
     */
    onPointerMove(event) {
      if (!this.hidden || this.hoverTimer !== null || event?.pointerType === 'touch') {
        return;
      }
      this.hoverTimer = setTimeout(() => {
        this.hoverTimer = null;
        this.reveal();
      }, HOVER_DWELL_MS);
    },
    cancelHover() {
      if (this.hoverTimer !== null) {
        clearTimeout(this.hoverTimer);
        this.hoverTimer = null;
      }
    },
    /**
     * Only a press on the hidden area itself. While hidden its content is inert, so a real tap
     * there targets the area; a click that bubbles up from inside started on the question before
     * (the "Nächste Frage" button, a rating button): the browser renders the next question between
     * that button's listener and this one, so it arrives here hidden and would uncover it at once.
     */
    onClick(event) {
      if (event?.target === this.$el) {
        this.reveal();
      }
    },
    onFocus(event) {
      // only the area's own focus, not a focus that bubbles from inside (there is none while hidden)
      if (event.target === this.$el) {
        this.reveal();
      }
    },
    onKey(event) {
      // the area's own key, not one bubbling from a control of the question before (see onClick)
      if (this.hidden && event.target === this.$el) {
        event.preventDefault();
        this.reveal();
      }
    },
    /**
     * The answer tone may only start from a gesture in Safari, and the result comes after a
     * request: a press or key inside the question opens the audio output beforehand. On touch
     * only the click counts as a gesture (pointerdown does for a mouse only), so both; the
     * capture phase runs before the answer button's own handler.
     */
    primeSounds() {
      if (soundsOn(this.prefs)) {
        unlockAnswerSounds();
      }
    },
  },
};
</script>

<style scoped>
.qconceal { position: relative; border-radius: var(--border-radius-large); }
.qconceal:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 4px; }
.qconceal:focus:not(:focus-visible) { outline: none; }
.qconceal__content { transition: opacity .45s ease, transform .45s ease, filter .45s ease; }
.qconceal--hidden { cursor: pointer; }
/* Hidden but in place: the space is kept, so uncovering moves nothing. A faint dashed frame hints
   at what is waiting. */
.qconceal--hidden .qconceal__content {
  opacity: 0;
  transform: translateY(14px);
  filter: blur(6px);
  user-select: none;
}
.qconceal--hidden::before {
  content: '';
  position: absolute;
  inset: 0;
  border: 1px dashed var(--color-border-dark);
  border-radius: var(--border-radius-large);
  background: var(--color-background-hover);
  opacity: .55;
  pointer-events: none;
}
.qconceal__veil {
  position: absolute;
  inset-block-start: 24px;
  inset-inline: 16px;
  z-index: 1;
  margin: 0;
  text-align: center;
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  pointer-events: none;
}
/* What appears after answering (the marked options, the explanation of the chosen one) fades in,
   for a question with a spoken intro; every other question shows its feedback as before. */
.qconceal--flow :deep(.answer-feedback) { animation: qconceal-rise .4s ease both; }
@keyframes qconceal-rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

.qconceal--still .qconceal__content { transition: opacity .2s linear; transform: none; }
.qconceal--still.qconceal--hidden .qconceal__content { transform: none; }
.qconceal--still :deep(.answer-feedback) { animation: none; }
@media (prefers-reduced-motion: reduce) {
  .qconceal__content { transition: opacity .2s linear; }
  .qconceal--hidden .qconceal__content { transform: none; }
  .qconceal :deep(.answer-feedback) { animation: none; }
}
</style>
