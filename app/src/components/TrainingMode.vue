<template>
  <div class="training-mode">
    <div v-if="!session && !loadError" class="training-start">
      <h3>Start Training Session</h3>
      <p v-if="totalQuestions > 0">Test your knowledge with {{ totalQuestions }} questions</p>
      <p v-else class="empty-hint">No questions available for training</p>
      <div class="start-actions">
        <button v-if="totalQuestions > 0" @click="startTraining" class="button primary" :disabled="starting">
          {{ starting ? 'Starting...' : 'Start Training' }}
        </button>
        <button @click="$emit('back')" class="button">Back</button>
      </div>
    </div>

    <!-- Error state -->
    <div v-else-if="loadError" class="training-start">
      <div class="error-banner">
        <span>{{ loadError }}</span>
        <button @click="startTraining" class="button">Retry</button>
      </div>
      <button @click="$emit('back')" class="button" style="margin-top: 16px">Back</button>
    </div>

    <div v-else-if="!showResults" class="training-active">
      <div class="progress-bar">
        <div class="progress-fill" :style="{ width: progress + '%' }"></div>
      </div>
      <div class="question-counter">
        Question {{ currentIndex + 1 }} of {{ questions.length }}
      </div>

      <div v-if="currentQuestion" class="question-card">
        <div class="question-text">{{ currentQuestion.text }}</div>

        <div v-if="!answered" class="answers-grid">
          <button
            v-for="answer in currentQuestion.answers"
            :key="answer.id"
            @click="submitAnswer(answer.id)"
            class="answer-btn"
            :disabled="submitting"
          >
            {{ answer.text }}
          </button>
        </div>

        <div v-else class="answer-feedback">
          <div :class="['feedback-banner', isCorrect ? 'correct' : 'incorrect']">
            <span class="feedback-icon">{{ isCorrect ? '✓' : '✗' }}</span>
            {{ isCorrect ? 'Correct!' : 'Incorrect' }}
          </div>

          <div class="correct-answer">
            <strong>Correct answer:</strong>
            {{ correctAnswerText }}
          </div>

          <div v-if="currentQuestion.explanation" class="explanation">
            <strong>Explanation:</strong> {{ currentQuestion.explanation }}
          </div>

          <button @click="nextQuestion" class="button primary next-btn">
            {{ currentIndex < questions.length - 1 ? 'Next Question →' : 'See Results' }}
          </button>
        </div>
      </div>
    </div>

    <div v-else class="training-results">
      <h3>Training Complete!</h3>
      <div class="score-display">
        <div class="score-circle">
          <span class="score-number">{{ results.score_percentage }}%</span>
        </div>
        <p>{{ results.correct_answers }} out of {{ results.total_questions }} correct</p>
      </div>
      <div class="result-actions">
        <button @click="restartTraining" class="button primary">Train Again</button>
        <button @click="$emit('back')" class="button">Back to Questions</button>
      </div>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError } from '@nextcloud/dialogs';

export default {
  name: 'TrainingMode',
  props: {
    poolId: { type: Number, required: true },
    totalQuestions: { type: Number, required: true }
  },
  data() {
    return {
      session: null,
      questions: [],
      currentIndex: 0,
      answered: false,
      submitting: false,
      isCorrect: false,
      correctAnswerText: '',
      showResults: false,
      results: null,
      starting: false,
      loadError: null
    };
  },
  computed: {
    currentQuestion() {
      return this.questions[this.currentIndex] || null;
    },
    progress() {
      return ((this.currentIndex + 1) / this.questions.length) * 100;
    }
  },
  methods: {
    async startTraining() {
      this.starting = true;
      this.loadError = null;
      try {
        const response = await axios.post(
          generateUrl('/apps/learning/api/training/start'),
          { poolId: this.poolId }
        );
        this.session = response.data.session_id;
        this.questions = response.data.questions;
      } catch (error) {
        this.loadError = error.response?.data?.error || 'Failed to start training. Please try again.';
      } finally {
        this.starting = false;
      }
    },
    async submitAnswer(answerId) {
      this.submitting = true;
      try {
        const response = await axios.post(
          generateUrl('/apps/learning/api/training/answer'),
          {
            sessionId: this.session,
            questionId: this.currentQuestion.id,
            answerId: answerId
          }
        );

        this.isCorrect = response.data.is_correct;
        this.answered = true;

        const correctAnswer = this.currentQuestion.answers.find(a => a.is_correct);
        this.correctAnswerText = correctAnswer ? correctAnswer.text : '';
      } catch (error) {
        showError('Failed to submit answer');
      } finally {
        this.submitting = false;
      }
    },
    async nextQuestion() {
      if (this.currentIndex < this.questions.length - 1) {
        this.currentIndex++;
        this.answered = false;
      } else {
        await this.completeSession();
      }
    },
    async completeSession() {
      try {
        const response = await axios.post(
          generateUrl('/apps/learning/api/training/complete'),
          { sessionId: this.session }
        );
        this.results = response.data;
        this.showResults = true;
      } catch (error) {
        showError('Failed to complete session');
      }
    },
    restartTraining() {
      this.session = null;
      this.questions = [];
      this.currentIndex = 0;
      this.answered = false;
      this.showResults = false;
      this.results = null;
      this.loadError = null;
      this.startTraining();
    }
  }
};
</script>

<style scoped>
.training-mode {
  max-width: 900px;
  margin: 0 auto;
}

.training-start {
  text-align: center;
  padding: 80px 20px;
}

.training-start h3 { font-size: 28px; margin-bottom: 12px; font-weight: 700; }
.training-start p { font-size: 16px; color: var(--color-text-lighter); margin-bottom: 28px; }
.empty-hint { font-style: italic; }

.start-actions {
  display: flex;
  justify-content: center;
  gap: 12px;
  flex-wrap: wrap;
}

.progress-bar {
  height: 6px;
  background: var(--color-background-hover);
  border-radius: 3px;
  margin-bottom: 12px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--color-primary), #00a0f0);
  transition: width 0.3s;
  border-radius: 3px;
}

.question-counter {
  text-align: center;
  font-size: 14px;
  color: var(--color-text-lighter);
  margin-bottom: 28px;
  font-weight: 500;
}

.question-card {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 16px;
  padding: 32px;
  box-shadow: 0 2px 12px rgba(0,0,0,0.04);
}

.question-text {
  font-size: 20px;
  font-weight: 500;
  margin-bottom: 28px;
  line-height: 1.6;
}

.answers-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 12px;
}

.answer-btn {
  padding: 16px 20px;
  border: 2px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-main-background);
  cursor: pointer;
  text-align: left;
  font-size: 15px;
  transition: all 0.15s;
  min-height: 56px;
  line-height: 1.5;
}

.answer-btn:hover:not(:disabled) {
  border-color: var(--color-primary);
  background: rgba(0,130,201,0.04);
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.answer-btn:disabled { opacity: 0.7; cursor: wait; }

.answer-feedback { margin-top: 28px; }

.feedback-banner {
  padding: 18px 24px;
  border-radius: 12px;
  font-size: 18px;
  font-weight: 600;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 12px;
}

.feedback-banner.correct { background: #d1fae5; color: #065f46; border: 2px solid #10b981; }
.feedback-banner.incorrect { background: #fee2e2; color: #991b1b; border: 2px solid #ef4444; }
.feedback-icon { font-size: 24px; }

.correct-answer {
  padding: 14px 18px;
  background: var(--color-background-hover);
  border-radius: 10px;
  margin-bottom: 12px;
  font-size: 14px;
  line-height: 1.5;
}

.explanation {
  padding: 14px 18px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 10px;
  margin-bottom: 20px;
  font-size: 14px;
  line-height: 1.5;
}

.next-btn {
  width: 100%;
  padding: 16px;
  font-size: 16px;
  border-radius: 12px;
}

.training-results {
  text-align: center;
  padding: 60px 20px;
}

.training-results h3 { font-size: 32px; margin-bottom: 36px; font-weight: 700; }
.score-display { margin-bottom: 36px; }

.score-circle {
  width: 180px;
  height: 180px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--color-primary), #0060a0);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 20px;
  box-shadow: 0 8px 24px rgba(0, 100, 180, 0.3);
}

.score-number { font-size: 48px; font-weight: 700; }

.score-display p { font-size: 16px; color: var(--color-text-lighter); }

.result-actions { display: flex; justify-content: center; gap: 14px; flex-wrap: wrap; }

.error-banner {
  padding: 12px 20px;
  background: #fee2e2;
  border: 1px solid #fca5a5;
  border-radius: 10px;
  color: #991b1b;
  display: flex;
  align-items: center;
  gap: 12px;
}

.error-banner button { margin-left: auto; }

.button {
  padding: 12px 24px;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-main-background);
  cursor: pointer;
  font-size: 14px;
  min-height: 44px;
  font-weight: 500;
}

.button:hover { background: var(--color-background-hover); }
.button.primary { background: var(--color-primary); color: white; border-color: var(--color-primary); }

@media (max-width: 768px) {
  .training-start { padding: 40px 16px; }
  .question-card { padding: 20px; border-radius: 12px; }
  .question-text { font-size: 17px; }
  .answers-grid { grid-template-columns: 1fr; }
  .score-circle { width: 140px; height: 140px; }
  .score-number { font-size: 36px; }
  .answer-btn { padding: 14px 16px; }
}
</style>
