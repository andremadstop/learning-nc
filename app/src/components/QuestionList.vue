<template>
  <div class="question-list">
    <div class="question-list-header">
      <h3>{{ poolName }}</h3>
      <div v-if="!readonly" class="header-actions">
        <NcButton v-if="aiAvailable" @click="showAIGenerator = true" type="secondary">{{ t('learning', 'Generate with AI') }}</NcButton>
        <NcActions v-if="questions.length > 0">
          <NcActionButton @click="exportCsv" close-after-click>{{ t('learning', 'Export CSV') }}</NcActionButton>
          <NcActionButton @click="exportJson" close-after-click>{{ t('learning', 'Export JSON') }}</NcActionButton>
        </NcActions>
        <NcButton @click="showImportDialog = true" type="secondary">{{ t('learning', 'Import') }}</NcButton>
        <NcButton @click="showCreateDialog" type="primary">{{ t('learning', '+ Add Question') }}</NcButton>
      </div>
    </div>

    <NcNoteCard v-if="loadError" type="error">{{ loadError }}</NcNoteCard>

    <NcLoadingIcon v-if="loading" :size="44" class="loading-center" />

    <NcEmptyContent v-else-if="questions.length === 0 && !loadError"
      :name="t('learning', 'No questions yet')"
      :description="!readonly ? t('learning', 'Create your first question or import from CSV/JSON') : t('learning', 'This pool has no questions yet')">
      <template v-if="!readonly" #action>
        <NcButton type="primary" @click="showImportDialog = true">{{ t('learning', 'Import Questions') }}</NcButton>
      </template>
    </NcEmptyContent>

    <div v-else class="question-items">
      <div v-for="(question, index) in questions" :key="question.id" class="question-item">
        <div class="question-header">
          <span class="question-number">Q{{ currentPage * pageSize + index + 1 }}</span>
          <span v-if="question.question_type === 'multi'" class="multi-badge">{{ t('learning', 'Multi') }}</span>
          <span v-if="question.question_type === 'open'" class="open-badge">{{ t('learning', 'Free text') }}</span>
          <span v-if="question.difficulty" class="difficulty-badge" :class="question.difficulty">{{ t('learning', question.difficulty.charAt(0).toUpperCase() + question.difficulty.slice(1)) }}</span>
          <div v-if="!readonly" class="question-actions">
            <NcActions>
              <NcActionButton @click="openTranslationDialog(question)" close-after-click>{{ t('learning', 'Translate') }}</NcActionButton>
              <NcActionButton @click="editQuestion(question)" close-after-click>{{ t('learning', 'Edit') }}</NcActionButton>
              <NcActionButton @click="deleteQuestion(question)" close-after-click>{{ t('learning', 'Delete') }}</NcActionButton>
            </NcActions>
          </div>
        </div>
        <div v-if="question.exam_key || question.chapter_title || question.handbook_title" class="question-meta-row">
          <span v-if="question.exam_key" class="meta-badge exam">{{ question.exam_key }}</span>
          <span v-if="question.handbook_title" class="meta-badge handbook">{{ question.handbook_title }}</span>
          <span v-if="question.chapter_title" class="meta-badge chapter">
            {{ formatChapterLabel(question) }}
          </span>
        </div>
        <!-- Image display -->
        <img v-if="question.image_path" :src="questionImageUrl(question.id)" :alt="question.text" class="question-image" />
        <div class="question-text">{{ question.text }}</div>
        <div v-if="question.question_type === 'open'" class="model-answer-display">
          <span class="model-answer-label">{{ t('learning', 'Model answer') }}:</span>
          {{ question.answers && question.answers.length > 0 ? question.answers[0].text : '' }}
        </div>
        <div v-else class="answers-list">
          <div v-for="answer in question.answers" :key="answer.id" class="answer-item" :class="{ correct: answer.is_correct }">
            <span class="answer-icon">{{ answer.is_correct ? '✓' : '○' }}</span>
            {{ answer.text }}
          </div>
        </div>
        <NcNoteCard v-if="question.explanation" type="warning" class="explanation-card">
          <strong>{{ t('learning', 'Explanation:') }}</strong> {{ question.explanation }}
        </NcNoteCard>
      </div>
    </div>

    <div v-if="totalQuestions > pageSize" class="pagination-bar">
      <NcButton type="tertiary" :disabled="currentPage === 0" @click="prevPage">{{ t('learning', '\u2190 Previous') }}</NcButton>
      <span class="pagination-info">{{ currentPage * pageSize + 1 }}\u2013{{ Math.min((currentPage + 1) * pageSize, totalQuestions) }} / {{ totalQuestions }}</span>
      <NcButton type="tertiary" :disabled="(currentPage + 1) * pageSize >= totalQuestions" @click="nextPage">{{ t('learning', 'Next \u2192') }}</NcButton>
    </div>

    <AccessibleDialog v-if="showDeleteConfirm" :name="t('learning', 'Delete Question')" @closing="showDeleteConfirm = false; questionToDelete = null">
      <p>{{ t('learning', 'Are you sure you want to delete this question? This action cannot be undone.') }}</p>
      <template #actions>
        <NcButton type="tertiary" @click="showDeleteConfirm = false; questionToDelete = null">{{ t('learning', 'Cancel') }}</NcButton>
        <NcButton type="error" @click="confirmDeleteQuestion">{{ t('learning', 'Delete') }}</NcButton>
      </template>
    </AccessibleDialog>

    <QuestionForm v-if="showDialog" :question="editingQuestion" @save="saveQuestion" @close="closeDialog" />
    <TranslationDialog v-if="showTranslationDialog" :question="translationQuestion" @close="closeTranslationDialog" @saved="closeTranslationDialog" />
    <ImportDialog v-if="showImportDialog" :poolId="poolId" @close="showImportDialog = false" @imported="onImported" />
    <AIGenerator v-if="showAIGenerator" :poolId="poolId" @close="showAIGenerator = false" @imported="onImported" />
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js';
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';
import QuestionForm from './QuestionForm.vue';
import TranslationDialog from './TranslationDialog.vue';
import ImportDialog from './ImportDialog.vue';
import AIGenerator from './AIGenerator.vue';
import AccessibleDialog from './AccessibleDialog.vue';

export default {
  name: 'QuestionList',
  components: { AccessibleDialog, NcButton, NcNoteCard, NcEmptyContent, NcActions, NcActionButton, NcLoadingIcon, QuestionForm, TranslationDialog, ImportDialog, AIGenerator },
  props: {
    poolId: { type: Number, required: true },
    poolName: { type: String, required: true },
    readonly: { type: Boolean, default: false }
  },
  data() {
    return { questions: [], loading: false, loadError: null, showDialog: false, showTranslationDialog: false, translationQuestion: null, showImportDialog: false, showAIGenerator: false, aiAvailable: false, editingQuestion: null, currentPage: 0, pageSize: 50, totalQuestions: 0, showDeleteConfirm: false, questionToDelete: null };
  },
  watch: {
    poolId() { this.currentPage = 0; this.loadQuestions(); },
  },
  mounted() { this.loadQuestions(); this.checkAIAvailable(); },
  methods: {
    formatChapterLabel(question) {
      if (!question.chapter_title) {
        return ''
      }
      return question.chapter_order
        ? t('learning', 'Chapter {n}: {title}', { n: question.chapter_order, title: question.chapter_title })
        : question.chapter_title
    },
    async checkAIAvailable() {
      if (this.readonly) return;
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/ai/available'));
        this.aiAvailable = r.data.available === true;
      } catch (e) { /* optional */ }
    },
    questionImageUrl(questionId) {
      return generateUrl('/apps/learning/api/questions/' + questionId + '/image');
    },
    async loadQuestions() {
      this.loading = true; this.loadError = null;
      try {
        const offset = this.currentPage * this.pageSize;
        const response = await axios.get(generateUrl('/apps/learning/api/pools/' + this.poolId + '/questions'), { params: { limit: this.pageSize, offset } });
        const data = response.data;
        if (data && Array.isArray(data.questions)) {
          this.questions = data.questions;
          this.totalQuestions = data.total || data.questions.length;
        } else {
          this.questions = Array.isArray(data) ? data : [];
          this.totalQuestions = this.questions.length;
        }
      } catch (error) {
        this.loadError = t('learning', 'Failed to load questions. Check your connection and try again.');
      } finally { this.loading = false; }
    },
    get totalPages() { return Math.ceil(this.totalQuestions / this.pageSize); },
    prevPage() { if (this.currentPage > 0) { this.currentPage--; this.loadQuestions(); } },
    nextPage() { if ((this.currentPage + 1) * this.pageSize < this.totalQuestions) { this.currentPage++; this.loadQuestions(); } },
    showCreateDialog() { this.editingQuestion = null; this.showDialog = true; },
    openTranslationDialog(question) { this.translationQuestion = question; this.showTranslationDialog = true; },
    closeTranslationDialog() { this.showTranslationDialog = false; this.translationQuestion = null; },
    editQuestion(question) { this.editingQuestion = question; this.showDialog = true; },
    closeDialog() { this.showDialog = false; this.editingQuestion = null; },
    onImported() { this.showImportDialog = false; this.loadQuestions(); },
    async saveQuestion(questionData) {
      try {
        const { imageFile, removeImage, questionType, ...data } = questionData;
        data.questionType = questionType || 'single';
        let questionId;

        if (this.editingQuestion) {
          await axios.put(generateUrl('/apps/learning/api/questions/' + this.editingQuestion.id), data);
          questionId = this.editingQuestion.id;
          showSuccess(t('learning', 'Question updated'));
        } else {
          const response = await axios.post(generateUrl('/apps/learning/api/questions'), { ...data, poolId: this.poolId });
          questionId = response.data.id;
          showSuccess(t('learning', 'Question created'));
        }

        // Handle image upload/removal after question save
        if (imageFile && questionId) {
          const formData = new FormData();
          formData.append('image', imageFile);
          await axios.post(generateUrl('/apps/learning/api/questions/' + questionId + '/image'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
          });
        } else if (removeImage && questionId) {
          await axios.delete(generateUrl('/apps/learning/api/questions/' + questionId + '/image'));
        }

        this.closeDialog(); this.loadQuestions();
      } catch (error) { showError(error.response?.data?.error || t('learning', 'Failed to save question')); }
    },
    exportCsv() {
      window.location.href = generateUrl('/apps/learning/api/pools/' + this.poolId + '/export/csv');
    },
    exportJson() {
      window.location.href = generateUrl('/apps/learning/api/pools/' + this.poolId + '/export/json');
    },
    deleteQuestion(question) {
      this.questionToDelete = question;
      this.showDeleteConfirm = true;
    },
    async confirmDeleteQuestion() {
      if (!this.questionToDelete) return;
      try {
        await axios.delete(generateUrl('/apps/learning/api/questions/' + this.questionToDelete.id));
        showSuccess(t('learning', 'Question deleted'));
        this.showDeleteConfirm = false;
        this.questionToDelete = null;
        this.loadQuestions();
      } catch (error) { showError(error.response?.data?.error || t('learning', 'Failed to delete question')); }
    }
  }
};
</script>

<style scoped>
.question-list-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 16px; }
.question-list-header h3 { flex: 1; margin: 0; font-size: 20px; color: var(--color-main-text); }
.header-actions { display: flex; gap: 10px; }
.question-items { display: grid; grid-template-columns: repeat(auto-fill, minmax(480px, 1fr)); gap: 16px; }
.question-item { border: 1px solid var(--color-border); border-radius: 12px; padding: 20px 24px; background: var(--color-main-background); transition: transform 0.2s, box-shadow 0.2s; }
.question-item:hover { transform: translateY(-2px); box-shadow: 0 4px 12px color-mix(in srgb, var(--color-main-text) 8%, transparent); }
.question-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.question-meta-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.question-number { font-weight: 700; color: var(--color-primary-element); font-size: 13px; background: var(--color-primary-element-light); padding: 2px 8px; border-radius: 4px; }
.multi-badge { padding: 2px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: color-mix(in srgb, var(--color-primary-element) 15%, transparent); color: var(--color-primary-element); border: 1px solid var(--color-primary-element); }
.open-badge { padding: 2px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: color-mix(in srgb, var(--color-warning) 15%, transparent); color: var(--color-warning); border: 1px solid var(--color-warning); }
.meta-badge { padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.meta-badge.exam { background: color-mix(in srgb, var(--color-primary-element) 14%, transparent); color: var(--color-primary-element); }
.meta-badge.handbook { background: color-mix(in srgb, var(--color-success) 10%, transparent); color: var(--color-success); }
.meta-badge.chapter { background: color-mix(in srgb, var(--color-warning) 14%, transparent); color: var(--color-warning); }
.model-answer-display { padding: 10px 14px; border-radius: 8px; background: color-mix(in srgb, var(--color-warning) 8%, var(--color-main-background)); font-size: 13px; line-height: 1.5; margin-bottom: 12px; border: 1px solid color-mix(in srgb, var(--color-warning) 25%, transparent); color: var(--color-main-text); }
.model-answer-label { font-weight: 600; color: var(--color-warning); }
.difficulty-badge { padding: 2px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.difficulty-badge.easy { background: color-mix(in srgb, var(--color-success) 15%, transparent); color: var(--color-success); border: 1px solid var(--color-success); }
.difficulty-badge.medium { background: color-mix(in srgb, var(--color-warning) 15%, transparent); color: var(--color-warning); border: 1px solid var(--color-warning); }
.difficulty-badge.hard { background: color-mix(in srgb, var(--color-error) 15%, transparent); color: var(--color-error); border: 1px solid var(--color-error); }
.question-actions { margin-left: auto; }
.question-image { max-width: 100%; max-height: 200px; border-radius: var(--border-radius-large); border: 1px solid var(--color-border); margin-bottom: 12px; object-fit: contain; }
.question-text { font-size: 15px; line-height: 1.6; margin-bottom: 14px; font-weight: 500; color: var(--color-main-text); }
.answers-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px; margin-bottom: 12px; }
.answer-item { padding: 10px 14px; border-radius: 8px; background: var(--color-background-hover); font-size: 13px; display: flex; align-items: center; gap: 10px; line-height: 1.4; color: var(--color-text-maxcontrast); border: 1px solid transparent; transition: transform 0.15s; }
.answer-item.correct { background: color-mix(in srgb, var(--color-success) 10%, var(--color-main-background)); color: var(--color-success); font-weight: 600; border-color: color-mix(in srgb, var(--color-success) 30%, transparent); }
.answer-icon { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; line-height: 1; }
.correct .answer-icon { background: var(--color-success); color: #fff; }
.answer-item:not(.correct) .answer-icon { background: var(--color-background-dark); color: var(--color-text-maxcontrast); font-size: 10px; }
.explanation-card { margin-top: 8px; }
.loading-center { display: block; margin: 60px auto; }
.pagination-bar { display: flex; justify-content: center; align-items: center; gap: 16px; margin-top: 24px; padding: 12px 0; }
.pagination-info { font-size: 14px; color: var(--color-text-maxcontrast); font-weight: 500; }
@media (max-width: 768px) {
  .question-list-header { flex-direction: column; align-items: stretch; }
  .header-actions { justify-content: flex-end; }
  .question-items { grid-template-columns: 1fr; }
  .answers-list { grid-template-columns: 1fr; }
  .question-item { padding: 14px 16px; }
}

@media (prefers-reduced-motion: reduce) {
  .question-item:hover { transform: none; }
}
</style>
