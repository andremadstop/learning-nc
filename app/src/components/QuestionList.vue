<template>
  <div class="question-list">
    <div class="question-list-header">
      <h3>{{ poolName }}</h3>
      <div v-if="!readonly" class="header-actions">
        <button @click="showImportDialog = true" class="button">📥 Import</button>
        <button @click="showCreateDialog" class="button primary">+ Add Question</button>
      </div>
    </div>

    <!-- Error state -->
    <div v-if="loadError" class="error-banner">
      <span>{{ loadError }}</span>
      <button @click="loadQuestions" class="button">Retry</button>
    </div>

    <div v-if="loading" class="loading-indicator">
      Loading questions...
    </div>

    <div v-else-if="questions.length === 0 && !loadError" class="empty-content">
      <h3>No questions yet</h3>
      <p v-if="!readonly">Create your first question or import from CSV/JSON</p>
      <p v-else>This pool has no questions yet</p>
      <button v-if="!readonly" @click="showImportDialog = true" class="button primary" style="margin-top: 16px">
        📥 Import Questions
      </button>
    </div>

    <div v-else class="question-items">
      <div v-for="(question, index) in questions" :key="question.id" class="question-item">
        <div class="question-header">
          <span class="question-number">Q{{ index + 1 }}</span>
          <span v-if="question.difficulty" class="difficulty-badge" :class="question.difficulty">
            {{ question.difficulty }}
          </span>
          <div v-if="!readonly" class="question-actions">
            <button @click="editQuestion(question)" class="action-btn" title="Edit">✏️</button>
            <button @click="deleteQuestion(question)" class="action-btn delete" title="Delete">🗑️</button>
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

    <ImportDialog
      v-if="showImportDialog"
      :poolId="poolId"
      @close="showImportDialog = false"
      @imported="onImported"
    />
  </div>
</template>

<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';
import QuestionForm from './QuestionForm.vue';
import ImportDialog from './ImportDialog.vue';

export default {
  name: 'QuestionList',
  components: { QuestionForm, ImportDialog },
  props: {
    poolId: { type: Number, required: true },
    poolName: { type: String, required: true },
    readonly: { type: Boolean, default: false }
  },
  data() {
    return {
      questions: [],
      loading: false,
      loadError: null,
      showDialog: false,
      showImportDialog: false,
      editingQuestion: null
    };
  },
  mounted() {
    this.loadQuestions();
  },
  methods: {
    async loadQuestions() {
      this.loading = true;
      this.loadError = null;
      try {
        const response = await axios.get(
          generateUrl(`/apps/learning/api/pools/${this.poolId}/questions`)
        );
        this.questions = response.data;
      } catch (error) {
        this.loadError = 'Failed to load questions. Check your connection and try again.';
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
    onImported(count) {
      this.showImportDialog = false;
      this.loadQuestions();
    },
    async saveQuestion(questionData) {
      try {
        if (this.editingQuestion) {
          await axios.put(
            generateUrl(`/apps/learning/api/questions/${this.editingQuestion.id}`),
            questionData
          );
          showSuccess('Question updated');
        } else {
          await axios.post(
            generateUrl('/apps/learning/api/questions'),
            { ...questionData, poolId: this.poolId }
          );
          showSuccess('Question created');
        }
        this.closeDialog();
        this.loadQuestions();
      } catch (error) {
        const msg = error.response?.data?.error || 'Failed to save question';
        showError(msg);
      }
    },
    async deleteQuestion(question) {
      if (!confirm('Delete this question? This cannot be undone.')) return;
      try {
        await axios.delete(generateUrl(`/apps/learning/api/questions/${question.id}`));
        showSuccess('Question deleted');
        this.loadQuestions();
      } catch (error) {
        const msg = error.response?.data?.error || 'Failed to delete question';
        showError(msg);
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

.question-list-header h3 { flex: 1; margin: 0; font-size: 18px; }
.header-actions { display: flex; gap: 8px; }

.question-items { display: flex; flex-direction: column; gap: 16px; }

.question-item {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 16px;
  background: var(--color-main-background);
}

.question-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.question-number { font-weight: 600; color: var(--color-primary); font-size: 14px; }

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

.question-actions { margin-left: auto; display: flex; gap: 4px; }

.action-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 8px;
  border-radius: 4px;
  font-size: 16px;
  transition: background 0.2s;
}

.action-btn:hover { background: var(--color-background-hover); }
.action-btn.delete:hover { background: #fee2e2; }

.question-text { font-size: 15px; line-height: 1.5; margin-bottom: 12px; font-weight: 500; }
.answers-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }

.answer-item {
  padding: 8px 12px;
  border-radius: 6px;
  background: var(--color-background-hover);
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.answer-item.correct { background: #d1fae5; border: 1px solid #10b981; font-weight: 500; }
.answer-icon { font-weight: bold; color: var(--color-primary); }
.answer-item.correct .answer-icon { color: #10b981; }

.question-explanation {
  padding: 12px;
  background: #fef3c7;
  border-radius: 6px;
  font-size: 13px;
  line-height: 1.4;
}

.question-explanation strong { color: #92400e; }

.loading-indicator { text-align: center; padding: 40px; color: var(--color-text-lighter); }
.empty-content { text-align: center; padding: 60px 20px; color: var(--color-text-lighter); }

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

.error-banner button { margin-left: auto; white-space: nowrap; }

.button {
  padding: 8px 16px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-main-background);
  cursor: pointer;
  font-size: 14px;
  min-height: 44px;
  white-space: nowrap;
}

.button:hover { background: var(--color-background-hover); }
.button.primary { background: var(--color-primary); color: white; border-color: var(--color-primary); }

@media (max-width: 600px) {
  .question-list-header { flex-direction: column; align-items: stretch; }
  .header-actions { justify-content: flex-end; }
  .question-item { padding: 12px; }
}
</style>
