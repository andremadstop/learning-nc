<template>
  <div class="question-list">
    <div class="question-list-header">
      <button @click="$emit('back')" class="button">
        <span class="icon-view-previous"></span> Back to Pools
      </button>
      <h3>{{ poolName }}</h3>
      <button @click="showCreateDialog" class="button primary">
        <span class="icon-add"></span> Add Question
      </button>
    </div>

    <div v-if="loading" class="loading-indicator">
      <span class="icon-loading-small"></span> Loading questions...
    </div>

    <div v-else-if="questions.length === 0" class="empty-content">
      <div class="icon-comment"></div>
      <h3>No questions yet</h3>
      <p>Create your first question to start learning</p>
    </div>

    <div v-else class="question-items">
      <div v-for="(question, index) in questions" :key="question.id" class="question-item">
        <div class="question-header">
          <span class="question-number">Q{{ index + 1 }}</span>
          <span v-if="question.difficulty" class="difficulty-badge" :class="question.difficulty">
            {{ question.difficulty }}
          </span>
          <div class="question-actions">
            <button @click="editQuestion(question)" class="icon-rename" title="Edit"></button>
            <button @click="deleteQuestion(question)" class="icon-delete" title="Delete"></button>
          </div>
        </div>
        <div class="question-text">{{ question.text }}</div>
        <div class="answers-list">
          <div v-for="answer in question.answers" :key="answer.id" 
               class="answer-item" :class="{ correct: answer.is_correct }">
            <span class="answer-icon">{{ answer.is_correct ? '✓' : '○' }}</span>
            {{ answer.text }}
          </div>
        </div>
        <div v-if="question.explanation" class="question-explanation">
          <strong>Explanation:</strong> {{ question.explanation }}
        </div>
      </div>
    </div>

    <QuestionForm
      v-if="showDialog"
      :question="editingQuestion"
      @save="saveQuestion"
      @close="closeDialog"
    />
  </div>
</template>

<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';
import QuestionForm from './QuestionForm.vue';

export default {
  name: 'QuestionList',
  components: { QuestionForm },
  props: {
    poolId: {
      type: Number,
      required: true
    },
    poolName: {
      type: String,
      required: true
    }
  },
  data() {
    return {
      questions: [],
      loading: false,
      showDialog: false,
      editingQuestion: null
    };
  },
  mounted() {
    this.loadQuestions();
  },
  methods: {
    async loadQuestions() {
      this.loading = true;
      try {
        const response = await axios.get(
          generateUrl(`/apps/learning/api/pools/${this.poolId}/questions`)
        );
        this.questions = response.data;
      } catch (error) {
        showError('Failed to load questions');
        console.error(error);
      } finally {
        this.loading = false;
      }
    },
    showCreateDialog() {
      this.editingQuestion = null;
      this.showDialog = true;
    },
    editQuestion(question) {
      this.editingQuestion = question;
      this.showDialog = true;
    },
    closeDialog() {
      this.showDialog = false;
      this.editingQuestion = null;
    },
    async saveQuestion(questionData) {
      try {
        if (this.editingQuestion) {
          await axios.put(
            generateUrl(`/apps/learning/api/questions/${this.editingQuestion.id}`),
            questionData
          );
          showSuccess('Question updated successfully');
        } else {
          await axios.post(
            generateUrl('/apps/learning/api/questions'),
            { ...questionData, poolId: this.poolId }
          );
          showSuccess('Question created successfully');
        }
        this.closeDialog();
        this.loadQuestions();
      } catch (error) {
        showError('Failed to save question');
        console.error(error);
      }
    },
    async deleteQuestion(question) {
      if (!confirm(`Are you sure you want to delete this question?`)) {
        return;
      }
      try {
        await axios.delete(generateUrl(`/apps/learning/api/questions/${question.id}`));
        showSuccess('Question deleted successfully');
        this.loadQuestions();
      } catch (error) {
        showError('Failed to delete question');
        console.error(error);
      }
    }
  }
};
</script>

<style scoped>
.question-list-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  gap: 12px;
}

.question-list-header h3 {
  flex: 1;
  text-align: center;
  margin: 0;
  font-size: 18px;
}

.question-items {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.question-item {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 16px;
  background: var(--color-main-background);
}

.question-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.question-number {
  font-weight: 600;
  color: var(--color-primary);
  font-size: 14px;
}

.difficulty-badge {
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
}

.difficulty-badge.easy { background: #10b981; color: white; }
.difficulty-badge.medium { background: #f59e0b; color: white; }
.difficulty-badge.hard { background: #ef4444; color: white; }

.question-actions {
  margin-left: auto;
  display: flex;
  gap: 4px;
}

.question-actions button {
  background: none;
  border: none;
  cursor: pointer;
  opacity: 0.6;
  padding: 4px;
}

.question-actions button:hover {
  opacity: 1;
}

.question-text {
  font-size: 15px;
  line-height: 1.5;
  margin-bottom: 12px;
  font-weight: 500;
}

.answers-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 12px;
}

.answer-item {
  padding: 8px 12px;
  border-radius: 6px;
  background: var(--color-background-hover);
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.answer-item.correct {
  background: #d1fae5;
  border: 1px solid #10b981;
  font-weight: 500;
}

.answer-icon {
  font-weight: bold;
  color: var(--color-primary);
}

.answer-item.correct .answer-icon {
  color: #10b981;
}

.question-explanation {
  padding: 12px;
  background: #fef3c7;
  border-radius: 6px;
  font-size: 13px;
  line-height: 1.4;
}

.question-explanation strong {
  color: #92400e;
}
</style>
