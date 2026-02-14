<template>
  <div class="swipe-mode">
    <div v-if="!session && !loadError" class="swipe-start">
      <h3>{{ t('learning', 'Swipe Review') }}</h3>
      <p v-if="totalQuestions > 0">{{ t('learning', 'Tap an answer \u2014 the card swipes automatically') }}</p>
      <NcEmptyContent v-else :name="t('learning', 'No questions')" :description="t('learning', 'No questions available for training')" />
      <div class="start-actions">
        <NcButton v-if="totalQuestions > 0" type="primary" @click="startSession" :disabled="starting">{{ starting ? t('learning', 'Starting...') : t('learning', 'Start Swipe Review') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>

    <div v-else-if="loadError" class="swipe-start">
      <NcNoteCard type="error">{{ loadError }}</NcNoteCard>
      <div class="start-actions">
        <NcButton type="primary" @click="startSession">{{ t('learning', 'Retry') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>

    <div v-else-if="!showResults" class="swipe-active">
      <div class="progress-area">
        <div class="progress-text">{{ currentIndex + 1 }} / {{ questions.length }}</div>
        <NcProgressBar :value="progressPercent" />
      </div>

      <div class="card-stack">
        <div
          v-if="currentQuestion"
          class="swipe-card"
          :class="cardAnimClass"
        >
          <div v-if="showFeedback" class="feedback-overlay" :class="isCorrect ? 'feedback-correct' : 'feedback-incorrect'">
            <span class="feedback-icon">{{ isCorrect ? '\u2713' : '\u2717' }}</span>
            <span class="feedback-label">{{ isCorrect ? t('learning', 'Correct!') : t('learning', 'Wrong') }}</span>
          </div>
          <div class="card-content">
            <img v-if="currentQuestion.image_path" :src="questionImageUrl(currentQuestion.id)" alt="" class="question-image" />
            <p class="question-text">{{ currentQuestion.text }}</p>
            <div v-if="showFeedback" class="explanation-area">
              <p><strong>{{ t('learning', 'Correct:') }}</strong> {{ correctAnswerText }}</p>
              <p v-if="currentQuestion.explanation" class="explanation-text">{{ currentQuestion.explanation }}</p>
            </div>
          </div>
        </div>
      </div>

      <div v-if="currentQuestion" class="answer-grid">
        <button
          v-for="answer in currentQuestion.answers"
          :key="answer.id"
          class="answer-btn"
          :class="{
            'answer-selected': selectedAnswerId === answer.id,
            'answer-correct': showFeedback && answer.id === correctAnswerId,
            'answer-wrong': showFeedback && selectedAnswerId === answer.id && answer.id !== correctAnswerId
          }"
          :disabled="animating"
          @click="selectAnswer(answer)"
        >
          {{ answer.text }}
        </button>
      </div>
    </div>

    <div v-else class="swipe-results">
      <h3>{{ t('learning', 'Session Complete!') }}</h3>
      <div class="score-area">
        <div class="score-circle">
          <span class="score-number">{{ results ? results.score_percentage : 0 }}%</span>
        </div>
        <p class="score-detail" v-if="results">{{ results.correct_answers }} out of {{ results.total_questions }} correct</p>
      </div>
      <div class="start-actions">
        <NcButton type="primary" @click="restartSession">{{ t('learning', 'Swipe Again') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js';
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError } from '@nextcloud/dialogs';

export default {
  name: 'SwipeMode',
  components: { NcButton, NcNoteCard, NcProgressBar, NcEmptyContent },
  props: {
    poolId: { type: Number, required: true },
    totalQuestions: { type: Number, required: true }
  },
  data() {
    return {
      session: null,
      questions: [],
      currentIndex: 0,
      selectedAnswerId: null,
      correctAnswerId: null,
      animating: false,
      showFeedback: false,
      isCorrect: false,
      correctAnswerText: '',
      showResults: false,
      results: null,
      starting: false,
      loadError: null,
      cardAnimClass: ''
    };
  },
  computed: {
    currentQuestion() { return this.questions[this.currentIndex] || null; },
    progressPercent() { return this.questions.length > 0 ? Math.round(((this.currentIndex + (this.showFeedback ? 1 : 0)) / this.questions.length) * 100) : 0; }
  },
  methods: {
    questionImageUrl(id) {
      return generateUrl('/apps/learning/api/questions/' + id + '/image');
    },
    async startSession() {
      this.starting = true;
      this.loadError = null;
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/training/start'), { poolId: this.poolId });
        this.session = r.data.session_id;
        this.questions = r.data.questions;
        this.currentIndex = 0;
        this.showResults = false;
        this.results = null;
        this.animating = false;
        this.showFeedback = false;
        this.selectedAnswerId = null;
        this.correctAnswerId = null;
        this.cardAnimClass = 'card-enter';
      } catch (e) {
        this.loadError = e.response?.data?.error || t('learning', 'Failed to start session');
        showError(t('learning', 'Failed to start swipe session'));
      } finally {
        this.starting = false;
      }
    },

    async selectAnswer(answer) {
      if (this.animating) return;
      this.animating = true;
      this.selectedAnswerId = answer.id;

      const question = this.currentQuestion;

      try {
        // Get correct answer info from server response
        const r = await axios.post(generateUrl('/apps/learning/api/training/answer'), {
          sessionId: this.session,
          questionId: question.id,
          answerId: answer.id
        });
        this.isCorrect = r.data.is_correct;
        this.correctAnswerId = r.data.correct_answer_id;
        this.correctAnswerText = r.data.correct_answer_text || '';
      } catch (e) {
        showError(t('learning', 'Failed to submit answer'));
        this.isCorrect = false;
        this.correctAnswerText = '';
        this.correctAnswerId = null;
      }

      this.showFeedback = true;

      // Animate card out after brief feedback
      setTimeout(() => {
        this.cardAnimClass = this.isCorrect ? 'card-exit-right' : 'card-exit-left';
      }, 600);

      // Advance to next card
      setTimeout(() => {
        this.showFeedback = false;
        this.selectedAnswerId = null;
        this.correctAnswerId = null;
        this.cardAnimClass = '';
        this.currentIndex++;

        if (this.currentIndex < this.questions.length) {
          this.$nextTick(() => {
            this.cardAnimClass = 'card-enter';
            this.animating = false;
          });
        } else {
          this.completeSession();
        }
      }, 1100);
    },

    async completeSession() {
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/training/complete'), { sessionId: this.session });
        this.results = r.data;
      } catch (e) {
        showError(t('learning', 'Failed to complete session'));
        this.results = { score_percentage: 0, correct_answers: 0, total_questions: this.questions.length };
      }
      this.showResults = true;
    },

    restartSession() {
      this.session = null;
      this.questions = [];
      this.currentIndex = 0;
      this.showResults = false;
      this.results = null;
      this.startSession();
    }
  }
};
</script>

<style scoped>
.swipe-mode { max-width: 600px; margin: 0 auto; }

.swipe-start { text-align: center; padding: 60px 20px; }
.swipe-start h3 { font-size: 28px; font-weight: 700; margin-bottom: 12px; color: var(--color-main-text); }
.swipe-start p { font-size: 16px; color: var(--color-text-maxcontrast); margin-bottom: 24px; }
.start-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }

.swipe-active { width: 100%; }

.progress-area { margin-bottom: 20px; }
.progress-text { text-align: center; font-size: 15px; font-weight: 600; margin-bottom: 8px; color: var(--color-primary-element); }

.card-stack { position: relative; width: 100%; min-height: 280px; display: flex; justify-content: center; align-items: center; margin-bottom: 20px; }

.swipe-card {
  width: 100%;
  min-height: 260px;
  background: var(--color-main-background);
  border: 2px solid var(--color-border);
  border-radius: 16px;
  padding: 28px 24px;
  box-shadow: 0 4px 16px rgba(0,0,0,0.08);
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  text-align: center;
  position: relative;
  overflow: hidden;
  box-sizing: border-box;
}

.card-content { width: 100%; z-index: 1; }

.question-image { max-width: 100%; max-height: 180px; border-radius: 10px; border: 1px solid var(--color-border); margin-bottom: 12px; object-fit: contain; }
.question-text {
  font-size: 20px;
  font-weight: 500;
  line-height: 1.6;
  margin-bottom: 16px;
  color: var(--color-main-text);
}

.explanation-area {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--color-border);
  text-align: left;
  font-size: 14px;
  color: var(--color-main-text);
  line-height: 1.5;
}
.explanation-text { color: var(--color-text-maxcontrast); margin-top: 4px; }

/* Feedback overlay */
.feedback-overlay {
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  z-index: 5;
  pointer-events: none;
}
.feedback-correct { background: color-mix(in srgb, var(--color-success) 20%, transparent); }
.feedback-incorrect { background: color-mix(in srgb, var(--color-error) 20%, transparent); }
.feedback-icon { font-size: 56px; font-weight: 700; }
.feedback-correct .feedback-icon { color: var(--color-success); }
.feedback-incorrect .feedback-icon { color: var(--color-error); }
.feedback-label { font-size: 18px; font-weight: 600; margin-top: 4px; }
.feedback-correct .feedback-label { color: var(--color-success); }
.feedback-incorrect .feedback-label { color: var(--color-error); }

/* Answer buttons */
.answer-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

.answer-btn {
  padding: 14px 16px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius-large);
  background: var(--color-main-background);
  cursor: pointer;
  text-align: center;
  font-size: 14px;
  transition: all 0.15s;
  min-height: 52px;
  color: var(--color-main-text);
  line-height: 1.4;
  word-break: break-word;
}
.answer-btn:hover:not(:disabled) { border-color: var(--color-primary-element); background: var(--color-background-hover); }
.answer-btn:disabled { opacity: 0.7; cursor: default; }
.answer-btn.answer-correct { border-color: var(--color-success); background: color-mix(in srgb, var(--color-success) 12%, var(--color-main-background)); color: var(--color-success); font-weight: 600; }
.answer-btn.answer-wrong { border-color: var(--color-error); background: color-mix(in srgb, var(--color-error) 12%, var(--color-main-background)); color: var(--color-error); }

/* Results */
.swipe-results { text-align: center; padding: 60px 20px; }
.swipe-results h3 { font-size: 28px; font-weight: 700; margin-bottom: 32px; color: var(--color-main-text); }
.score-area { margin-bottom: 32px; }
.score-circle {
  width: 160px; height: 160px;
  border-radius: 50%;
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 20px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.15);
}
.score-number { font-size: 42px; font-weight: 700; }
.score-detail { font-size: 16px; color: var(--color-text-maxcontrast); }

/* Card animations */
.card-enter { animation: cardIn 0.3s ease-out; }
.card-exit-right { animation: flyRight 0.4s ease-out forwards; }
.card-exit-left { animation: flyLeft 0.4s ease-out forwards; }

@keyframes cardIn {
  from { transform: translateY(30px) scale(0.95); opacity: 0; }
  to { transform: translateY(0) scale(1); opacity: 1; }
}
@keyframes flyRight {
  to { transform: translateX(150%) rotate(20deg); opacity: 0; }
}
@keyframes flyLeft {
  to { transform: translateX(-150%) rotate(-20deg); opacity: 0; }
}

/* Responsive */
@media (max-width: 480px) {
  .swipe-start { padding: 40px 12px; }
  .swipe-card { padding: 20px 16px; min-height: 220px; }
  .question-text { font-size: 17px; }
  .answer-grid { grid-template-columns: 1fr; }
  .score-circle { width: 130px; height: 130px; }
  .score-number { font-size: 36px; }
}
</style>