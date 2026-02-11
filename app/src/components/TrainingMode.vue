<template>
  <div class="training-mode">
    <div v-if="!session" class="training-start">
      <h3>Start Training Session</h3>
      <p>Test your knowledge with {{ totalQuestions }} questions</p>
      <button @click="startTraining" class="button primary" :disabled="starting">
        {{ starting ? 'Starting...' : 'Start Training' }}
      </button>
      <button @click="$emit('back')" class="button">Back</button>
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

          <button @click="nextQuestion" class="button primary">
            {{ currentIndex < questions.length - 1 ? 'Next Question' : 'See Results' }}
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
      isCorrect: false,
      correctAnswerText: '',
      showResults: false,
      results: null,
      starting: false
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
      try {
        const response = await axios.post(
          generateUrl('/apps/learning/api/training/start'),
          { poolId: this.poolId }
        );
        this.session = response.data.session_id;
        this.questions = response.data.questions;
      } catch (error) {
        showError('Failed to start training');
        console.error(error);
      } finally {
        this.starting = false;
      }
    },
    async submitAnswer(answerId) {
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
        console.error(error);
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
        console.error(error);
      }
    },
    restartTraining() {
      this.session = null;
      this.questions = [];
      this.currentIndex = 0;
      this.answered = false;
      this.showResults = false;
      this.results = null;
      this.startTraining();
    }
  }
};
</script>

<style scoped>
.training-start {
  text-align: center;
  padding: 60px 20px;
}

.training-start h3 {
  font-size: 24px;
  margin-bottom: 12px;
}

.training-start p {
  font-size: 16px;
  color: var(--color-text-lighter);
  margin-bottom: 24px;
}

.training-start button {
  margin: 0 8px;
}

.progress-bar {
  height: 8px;
  background: var(--color-background-hover);
  border-radius: 4px;
  margin-bottom: 16px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: var(--color-primary);
  transition: width 0.3s;
}

.question-counter {
  text-align: center;
  font-size: 14px;
  color: var(--color-text-lighter);
  margin-bottom: 24px;
}

.question-card {
  max-width: 800px;
  margin: 0 auto;
}

.question-text {
  font-size: 20px;
  font-weight: 500;
  margin-bottom: 24px;
  line-height: 1.5;
}

.answers-grid {
  display: grid;
  gap: 12px;
}

.answer-btn {
  padding: 16px;
  border: 2px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-main-background);
  cursor: pointer;
  text-align: left;
  font-size: 15px;
  transition: all 0.2s;
}

.answer-btn:hover {
  border-color: var(--color-primary);
  background: var(--color-background-hover);
}

.answer-feedback {
  margin-top: 24px;
}

.feedback-banner {
  padding: 16px;
  border-radius: 8px;
  font-size: 18px;
  font-weight: 600;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 12px;
}

.feedback-banner.correct {
  background: #d1fae5;
  color: #065f46;
  border: 2px solid #10b981;
}

.feedback-banner.incorrect {
  background: #fee2e2;
  color: #991b1b;
  border: 2px solid #ef4444;
}

.feedback-icon {
  font-size: 24px;
}

.correct-answer {
  padding: 12px;
  background: var(--color-background-hover);
  border-radius: 6px;
  margin-bottom: 12px;
}

.explanation {
  padding: 12px;
  background: #fef3c7;
  border-radius: 6px;
  margin-bottom: 16px;
}

.training-results {
  text-align: center;
  padding: 40px 20px;
}

.training-results h3 {
  font-size: 28px;
  margin-bottom: 32px;
}

.score-display {
  margin-bottom: 32px;
}

.score-circle {
  width: 200px;
  height: 200px;
  border-radius: 50%;
  background: var(--color-primary);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.score-number {
  font-size: 48px;
  font-weight: 700;
}

.result-actions button {
  margin: 0 8px;
}
</style>
