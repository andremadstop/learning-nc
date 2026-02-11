<template>
  <div class="leitner-mode">
    <div v-if="!initialized" class="leitner-init">
      <h3>Leitner System - Spaced Repetition</h3>
      <p>Initialize this pool for spaced repetition learning</p>
      <button @click="initialize" class="button primary" :disabled="initializing">
        {{ initializing ? 'Initializing...' : 'Initialize Leitner System' }}
      </button>
      <button @click="$emit('back')" class="button">Back</button>
    </div>

    <div v-else-if="!started" class="leitner-stats">
      <h3>Leitner Progress</h3>
      <div class="box-grid">
        <div v-for="i in 5" :key="i" class="box-card" :class="'box-' + i">
          <div class="box-number">Box {{ i }}</div>
          <div class="box-count">{{ stats['box_' + i] || 0 }}</div>
          <div class="box-label">{{ boxLabels[i] }}</div>
        </div>
      </div>
      <div class="mastery-section">
        <div class="mastery-bar">
          <div class="mastery-fill" :style="{ width: stats.mastery_percentage + '%' }"></div>
        </div>
        <p>{{ stats.mastery_percentage }}% Mastered</p>
      </div>
      <div class="action-buttons">
        <button @click="startReview" class="button primary" :disabled="dueCount === 0">
          Review {{ dueCount }} Due Questions
        </button>
        <button @click="$emit('back')" class="button">Back</button>
      </div>
    </div>

    <div v-else-if="!showResults" class="leitner-review">
      <div class="review-counter">{{ currentIndex + 1 }} / {{ dueQuestions.length }}</div>
      
      <div v-if="currentItem" class="review-card">
        <div class="question-text">{{ currentItem.text }}</div>
        
        <div v-if="!answered" class="review-actions">
          <p>Do you know the answer?</p>
          <button @click="revealAnswer" class="button primary">Show Answer</button>
        </div>

        <div v-else class="answer-section">
          <div class="correct-answer">
            <strong>Answer:</strong> {{ getCorrectAnswer() }}
          </div>
          <div v-if="currentItem.explanation" class="explanation">
            {{ currentItem.explanation }}
          </div>
          <div class="self-grade">
            <p>Did you get it right?</p>
            <button @click="grade(true)" class="button success">✓ Correct</button>
            <button @click="grade(false)" class="button danger">✗ Incorrect</button>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="review-complete">
      <h3>Review Complete!</h3>
      <p>You reviewed {{ dueQuestions.length }} questions</p>
      <button @click="finishReview" class="button primary">Back to Stats</button>
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
      stats: {},
      dueQuestions: [],
      dueCount: 0,
      started: false,
      currentIndex: 0,
      answered: false,
      showResults: false,
      boxLabels: {
        1: 'New',
        2: '1 day',
        3: '3 days',
        4: '7 days',
        5: 'Mastered'
      }
    };
  },
  computed: {
    currentItem() {
      return this.dueQuestions[this.currentIndex] || null;
    }
  },
  mounted() {
    this.checkInitialized();
  },
  methods: {
    async checkInitialized() {
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/leitner/stats'),
          { params: { poolId: this.poolId } }
        );
        this.stats = response.data;
        this.initialized = this.stats.total > 0;
        
        if (this.initialized) {
          await this.loadDue();
        }
      } catch (error) {
        console.error(error);
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
        showError('Failed to initialize');
        console.error(error);
      } finally {
        this.initializing = false;
      }
    },
    async loadDue() {
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/leitner/due'),
          { params: { poolId: this.poolId, limit: 10 } }
        );
        this.dueQuestions = response.data;
        this.dueCount = this.dueQuestions.length;
      } catch (error) {
        console.error(error);
      }
    },
    startReview() {
      if (this.dueCount > 0) {
        this.started = true;
      }
    },
    revealAnswer() {
      this.answered = true;
    },
    async grade(correct) {
      try {
        await axios.post(
          generateUrl('/apps/learning/api/leitner/answer'),
          { itemId: this.currentItem.id, correct }
        );
        
        if (this.currentIndex < this.dueQuestions.length - 1) {
          this.currentIndex++;
          this.answered = false;
        } else {
          this.showResults = true;
        }
      } catch (error) {
        showError('Failed to record answer');
        console.error(error);
      }
    },
    getCorrectAnswer() {
      // Parse answers from current item (would need to join with answers table)
      return 'See question details for full answer';
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
.leitner-init, .leitner-stats, .review-complete {
  text-align: center;
  padding: 40px 20px;
}

.box-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 12px;
  max-width: 800px;
  margin: 0 auto 32px;
}

.box-card {
  padding: 20px;
  border-radius: 8px;
  border: 2px solid;
  text-align: center;
}

.box-card.box-1 { border-color: #ef4444; background: #fee2e2; }
.box-card.box-2 { border-color: #f59e0b; background: #fef3c7; }
.box-card.box-3 { border-color: #3b82f6; background: #dbeafe; }
.box-card.box-4 { border-color: #8b5cf6; background: #ede9fe; }
.box-card.box-5 { border-color: #10b981; background: #d1fae5; }

.box-number {
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 8px;
}

.box-count {
  font-size: 32px;
  font-weight: 700;
  margin-bottom: 4px;
}

.box-label {
  font-size: 11px;
  color: var(--color-text-lighter);
}

.mastery-section {
  max-width: 600px;
  margin: 0 auto 32px;
}

.mastery-bar {
  height: 24px;
  background: var(--color-background-hover);
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 8px;
}

.mastery-fill {
  height: 100%;
  background: linear-gradient(90deg, #10b981, #059669);
  transition: width 0.5s;
}

.action-buttons button {
  margin: 0 8px;
}

.review-counter {
  text-align: center;
  font-size: 18px;
  font-weight: 600;
  margin-bottom: 24px;
  color: var(--color-primary);
}

.review-card {
  max-width: 700px;
  margin: 0 auto;
  padding: 32px;
  border: 2px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-main-background);
}

.question-text {
  font-size: 20px;
  line-height: 1.6;
  margin-bottom: 32px;
}

.review-actions {
  text-align: center;
}

.answer-section {
  margin-top: 24px;
}

.correct-answer {
  padding: 16px;
  background: #d1fae5;
  border: 2px solid #10b981;
  border-radius: 8px;
  margin-bottom: 16px;
}

.explanation {
  padding: 12px;
  background: #fef3c7;
  border-radius: 6px;
  margin-bottom: 24px;
}

.self-grade {
  text-align: center;
}

.self-grade p {
  margin-bottom: 16px;
  font-weight: 500;
}

.self-grade button {
  margin: 0 8px;
}

.button.success {
  background: #10b981;
  color: white;
  border-color: #10b981;
}

.button.danger {
  background: #ef4444;
  color: white;
  border-color: #ef4444;
}
</style>
