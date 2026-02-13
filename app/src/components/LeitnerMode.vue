<template>
  <div class="leitner-mode">
    <!-- Initialize Screen -->
    <div v-if="!initialized && !initError" class="leitner-init">
      <h3>Leitner System - Spaced Repetition</h3>
      <p>Initialize this pool for spaced repetition learning</p>
      <button @click="initialize" class="button primary" :disabled="initializing">
        {{ initializing ? 'Initializing...' : 'Initialize Leitner System' }}
      </button>
      <button @click="$emit('back')" class="button">Back</button>
    </div>

    <!-- Error during init check -->
    <div v-else-if="initError" class="leitner-init">
      <div class="error-banner">
        <span>{{ initError }}</span>
        <button @click="checkInitialized" class="button">Retry</button>
      </div>
      <button @click="$emit('back')" class="button" style="margin-top: 16px">Back</button>
    </div>

    <!-- Stats & Box View -->
    <div v-else-if="!started" class="leitner-dashboard">
      <!-- Due Banner -->
      <div v-if="stats.due_count > 0" class="due-banner" @click="startReview">
        <span class="due-icon">📚</span>
        <div class="due-text">
          <strong>{{ stats.due_count }} questions due for review</strong>
          <span>Click to start your review session</span>
        </div>
        <button class="button primary">Start Review</button>
      </div>
      <div v-else class="due-banner all-done">
        <span class="due-icon">✅</span>
        <div class="due-text">
          <strong>All caught up!</strong>
          <span>No questions due for review right now</span>
        </div>
      </div>

      <!-- Stats Row -->
      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-value">{{ stats.total }}</div>
          <div class="stat-label">Total Cards</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ stats.accuracy }}%</div>
          <div class="stat-label">Accuracy</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ stats.mastery_percentage }}%</div>
          <div class="stat-label">Mastered</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ stats.total_answered }}</div>
          <div class="stat-label">Reviews Done</div>
        </div>
      </div>

      <!-- Box View -->
      <h4 class="section-title">Leitner Boxes</h4>
      <div class="box-grid">
        <div v-for="i in 5" :key="i" class="box-card" :class="['box-' + i]">
          <div class="box-header">
            <div class="box-number">Box {{ i }}</div>
            <div class="box-count">{{ stats['box_' + i] || 0 }}</div>
          </div>
          <div class="box-label">{{ boxLabels[i] }}</div>
          <div class="box-bar">
            <div class="box-bar-fill" :style="{ width: boxPercentage(i) + '%' }"></div>
          </div>
        </div>
      </div>

      <!-- Mastery Progress -->
      <div class="mastery-section">
        <div class="mastery-header">
          <span>Overall Progress</span>
          <span class="mastery-pct">{{ stats.mastery_percentage }}%</span>
        </div>
        <div class="mastery-bar">
          <div class="mastery-fill" :style="{ width: stats.mastery_percentage + '%' }"></div>
        </div>
      </div>

      <div class="action-buttons">
        <button @click="$emit('back')" class="button">Back to Pool</button>
      </div>
    </div>

    <!-- Review Mode -->
    <div v-else-if="!showResults" class="leitner-review">
      <div class="review-header">
        <div class="review-counter">{{ currentIndex + 1 }} / {{ dueQuestions.length }}</div>
        <div class="review-progress-bar">
          <div class="review-progress-fill" :style="{ width: reviewProgress + '%' }"></div>
        </div>
      </div>

      <div v-if="currentItem" class="review-card">
        <div class="review-box-indicator">
          Box {{ currentItem.box }} → {{ answered ? (lastAnswer ? 'Box ' + lastMoveTarget : 'Box 1') : '?' }}
        </div>
        <div class="question-text">{{ currentItem.text }}</div>

        <div v-if="!answered" class="answer-options">
          <button v-for="answer in currentItem.answers" :key="answer.id"
                  @click="submitAnswer(answer)"
                  class="answer-btn"
                  :disabled="submitting">
            {{ answer.text }}
          </button>
        </div>

        <div v-else class="answer-feedback">
          <div :class="['feedback-banner', lastAnswer ? 'correct' : 'incorrect']">
            {{ lastAnswer ? '✓ Correct!' : '✗ Incorrect' }}
          </div>

          <div class="correct-answer-display">
            <strong>Correct answer:</strong> {{ getCorrectAnswer() }}
          </div>

          <div v-if="currentItem.explanation" class="explanation">
            {{ currentItem.explanation }}
          </div>

          <button @click="nextQuestion" class="button primary next-btn">
            {{ currentIndex < dueQuestions.length - 1 ? 'Next Question →' : 'See Results' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Review Complete -->
    <div v-else class="review-complete">
      <h3>Review Complete!</h3>
      <div class="session-stats">
        <div class="session-stat correct">
          <div class="session-stat-value">{{ sessionCorrect }}</div>
          <div class="session-stat-label">Correct</div>
        </div>
        <div class="session-stat incorrect">
          <div class="session-stat-value">{{ sessionIncorrect }}</div>
          <div class="session-stat-label">Incorrect</div>
        </div>
        <div class="session-stat accuracy">
          <div class="session-stat-value">{{ sessionAccuracy }}%</div>
          <div class="session-stat-label">Accuracy</div>
        </div>
      </div>
      <button @click="finishReview" class="button primary">Back to Dashboard</button>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';

export default {
  name: 'LeitnerMode',
  props: {
    poolId: { type: Number, required: true }
  },
  data() {
    return {
      initialized: false,
      initializing: false,
      initError: null,
      stats: { total: 0, due_count: 0, accuracy: 0, mastery_percentage: 0, total_answered: 0 },
      dueQuestions: [],
      started: false,
      currentIndex: 0,
      answered: false,
      submitting: false,
      lastAnswer: false,
      lastMoveTarget: 0,
      showResults: false,
      sessionCorrect: 0,
      sessionIncorrect: 0,
      boxLabels: {
        1: 'New / Reset',
        2: 'After 1 day',
        3: 'After 3 days',
        4: 'After 7 days',
        5: 'Mastered (14d)'
      }
    };
  },
  computed: {
    currentItem() {
      return this.dueQuestions[this.currentIndex] || null;
    },
    reviewProgress() {
      return ((this.currentIndex + (this.answered ? 1 : 0)) / this.dueQuestions.length) * 100;
    },
    sessionAccuracy() {
      const total = this.sessionCorrect + this.sessionIncorrect;
      return total > 0 ? Math.round(this.sessionCorrect / total * 100) : 0;
    }
  },
  mounted() {
    this.checkInitialized();
  },
  methods: {
    async checkInitialized() {
      this.initError = null;
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/leitner/stats'),
          { params: { poolId: this.poolId } }
        );
        this.stats = response.data;
        this.initialized = this.stats.total > 0;
      } catch (error) {
        this.initError = 'Failed to load Leitner stats. Please try again.';
      }
    },
    async initialize() {
      this.initializing = true;
      try {
        await axios.post(
          generateUrl('/apps/learning/api/leitner/initialize'),
          { poolId: this.poolId }
        );
        showSuccess('Leitner system initialized');
        await this.checkInitialized();
      } catch (error) {
        showError(error.response?.data?.error || 'Failed to initialize');
      } finally {
        this.initializing = false;
      }
    },
    async startReview() {
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/leitner/due'),
          { params: { poolId: this.poolId, limit: 20 } }
        );
        this.dueQuestions = response.data;
        if (this.dueQuestions.length > 0) {
          this.started = true;
          this.currentIndex = 0;
          this.answered = false;
          this.submitting = false;
          this.sessionCorrect = 0;
          this.sessionIncorrect = 0;
        }
      } catch (error) {
        showError('Failed to load review questions');
      }
    },
    async submitAnswer(answer) {
      this.submitting = true;
      const isCorrect = answer.is_correct === true || answer.is_correct === 't' || answer.is_correct === '1';
      try {
        const response = await axios.post(
          generateUrl('/apps/learning/api/leitner/answer'),
          { itemId: this.currentItem.id, correct: isCorrect }
        );
        this.lastAnswer = isCorrect;
        this.lastMoveTarget = response.data.new_box;
        this.answered = true;
        if (isCorrect) {
          this.sessionCorrect++;
        } else {
          this.sessionIncorrect++;
        }
      } catch (error) {
        showError('Failed to record answer');
      } finally {
        this.submitting = false;
      }
    },
    getCorrectAnswer() {
      if (!this.currentItem || !this.currentItem.answers) return '';
      const correct = this.currentItem.answers.find(
        a => a.is_correct === true || a.is_correct === 't' || a.is_correct === '1'
      );
      return correct ? correct.text : '';
    },
    nextQuestion() {
      if (this.currentIndex < this.dueQuestions.length - 1) {
        this.currentIndex++;
        this.answered = false;
      } else {
        this.showResults = true;
      }
    },
    boxPercentage(boxNum) {
      if (this.stats.total === 0) return 0;
      return Math.round((this.stats['box_' + boxNum] || 0) / this.stats.total * 100);
    },
    finishReview() {
      this.started = false;
      this.currentIndex = 0;
      this.answered = false;
      this.showResults = false;
      this.checkInitialized();
    }
  }
};
</script>

<style scoped>
.leitner-init { text-align: center; padding: 60px 20px; }
.leitner-init h3 { font-size: 24px; margin-bottom: 12px; }
.leitner-init p { color: var(--color-text-lighter); margin-bottom: 24px; }
.leitner-init .button { margin: 0 8px; }

/* Due Banner */
.due-banner {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px 20px;
  background: linear-gradient(135deg, #dbeafe, #ede9fe);
  border: 2px solid #3b82f6;
  border-radius: 12px;
  margin-bottom: 24px;
  cursor: pointer;
  transition: transform 0.2s, box-shadow 0.2s;
}

.due-banner:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2); }

.due-banner.all-done {
  background: linear-gradient(135deg, #d1fae5, #ecfdf5);
  border-color: #10b981;
  cursor: default;
}

.due-banner.all-done:hover { transform: none; box-shadow: none; }
.due-icon { font-size: 32px; }
.due-text { flex: 1; }
.due-text strong { display: block; font-size: 16px; }
.due-text span { font-size: 13px; color: var(--color-text-lighter); }

/* Stats Row */
.stats-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 24px;
}

.stat-card {
  text-align: center;
  padding: 16px 8px;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.stat-value { font-size: 28px; font-weight: 700; color: var(--color-primary); }
.stat-label { font-size: 12px; color: var(--color-text-lighter); margin-top: 4px; }
.section-title { font-size: 16px; font-weight: 600; margin-bottom: 12px; }

/* Box Grid */
.box-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 12px;
  margin-bottom: 24px;
}

.box-card {
  padding: 16px;
  border-radius: 8px;
  border: 2px solid;
  transition: transform 0.2s, box-shadow 0.2s;
}

.box-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); }
.box-card.box-1 { border-color: #ef4444; background: #fef2f2; }
.box-card.box-2 { border-color: #f59e0b; background: #fffbeb; }
.box-card.box-3 { border-color: #3b82f6; background: #eff6ff; }
.box-card.box-4 { border-color: #8b5cf6; background: #f5f3ff; }
.box-card.box-5 { border-color: #10b981; background: #ecfdf5; }

.box-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.box-number { font-size: 12px; font-weight: 600; }
.box-count { font-size: 24px; font-weight: 700; }
.box-label { font-size: 11px; color: var(--color-text-lighter); margin-bottom: 8px; }

.box-bar { height: 4px; background: rgba(0,0,0,0.1); border-radius: 2px; overflow: hidden; }
.box-bar-fill { height: 100%; transition: width 0.5s; }
.box-1 .box-bar-fill { background: #ef4444; }
.box-2 .box-bar-fill { background: #f59e0b; }
.box-3 .box-bar-fill { background: #3b82f6; }
.box-4 .box-bar-fill { background: #8b5cf6; }
.box-5 .box-bar-fill { background: #10b981; }

/* Mastery */
.mastery-section { margin-bottom: 24px; }
.mastery-header { display: flex; justify-content: space-between; margin-bottom: 8px; font-weight: 500; }
.mastery-pct { color: var(--color-primary); font-weight: 700; }
.mastery-bar { height: 12px; background: var(--color-background-hover); border-radius: 6px; overflow: hidden; }
.mastery-fill { height: 100%; background: linear-gradient(90deg, #10b981, #059669); border-radius: 6px; transition: width 0.5s; }
.action-buttons { text-align: center; }

/* Review Mode */
.review-header { margin-bottom: 24px; }
.review-counter { text-align: center; font-size: 16px; font-weight: 600; margin-bottom: 8px; color: var(--color-primary); }
.review-progress-bar { height: 6px; background: var(--color-background-hover); border-radius: 3px; overflow: hidden; }
.review-progress-fill { height: 100%; background: var(--color-primary); transition: width 0.3s; }

.review-card {
  max-width: 700px;
  margin: 0 auto;
  padding: 32px;
  border: 2px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-main-background);
}

.review-box-indicator { font-size: 12px; color: var(--color-text-lighter); margin-bottom: 16px; }

.question-text { font-size: 20px; line-height: 1.6; margin-bottom: 24px; font-weight: 500; }
.answer-options { display: grid; gap: 10px; }

.answer-btn {
  padding: 14px 16px;
  border: 2px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-main-background);
  cursor: pointer;
  text-align: left;
  font-size: 15px;
  transition: all 0.2s;
  min-height: 52px;
}

.answer-btn:hover:not(:disabled) { border-color: var(--color-primary); background: var(--color-background-hover); }
.answer-btn:disabled { opacity: 0.7; cursor: wait; }

.answer-feedback { margin-top: 20px; }

.feedback-banner {
  padding: 14px;
  border-radius: 8px;
  font-size: 18px;
  font-weight: 600;
  margin-bottom: 16px;
  text-align: center;
}

.feedback-banner.correct { background: #d1fae5; color: #065f46; border: 2px solid #10b981; }
.feedback-banner.incorrect { background: #fee2e2; color: #991b1b; border: 2px solid #ef4444; }

.correct-answer-display { padding: 12px; background: var(--color-background-hover); border-radius: 6px; margin-bottom: 12px; }
.explanation { padding: 12px; background: #fef3c7; border-radius: 6px; margin-bottom: 16px; }
.next-btn { width: 100%; padding: 14px; font-size: 16px; }

/* Review Complete */
.review-complete { text-align: center; padding: 40px 20px; }
.review-complete h3 { font-size: 28px; margin-bottom: 32px; }

.session-stats { display: flex; justify-content: center; gap: 32px; margin-bottom: 32px; }
.session-stat { text-align: center; }
.session-stat-value { font-size: 36px; font-weight: 700; }
.session-stat-label { font-size: 13px; color: var(--color-text-lighter); margin-top: 4px; }
.session-stat.correct .session-stat-value { color: #10b981; }
.session-stat.incorrect .session-stat-value { color: #ef4444; }
.session-stat.accuracy .session-stat-value { color: var(--color-primary); }

/* Error */
.error-banner {
  padding: 12px 16px;
  background: #fee2e2;
  border: 1px solid #fca5a5;
  border-radius: 8px;
  color: #991b1b;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.error-banner button { margin-left: auto; }

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

/* Responsive */
@media (max-width: 768px) {
  .box-grid { grid-template-columns: repeat(3, 1fr); }
  .stats-row { grid-template-columns: repeat(2, 1fr); }
  .session-stats { gap: 16px; }
  .session-stat-value { font-size: 28px; }
  .review-card { padding: 20px; }
}

@media (max-width: 480px) {
  .box-grid { grid-template-columns: repeat(2, 1fr); }
  .due-banner { flex-direction: column; text-align: center; }
  .question-text { font-size: 17px; }
  .review-card { padding: 16px; }
  .leitner-init { padding: 30px 12px; }
}
</style>
