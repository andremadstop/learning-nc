<template>
  <div class="question-list">
    <div class="question-list-header">
      <h3>{{ poolName }}</h3>
      <div v-if="!readonly" class="header-actions">
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
          <span class="question-number">Q{{ index + 1 }}</span>
          <span v-if="question.difficulty" class="difficulty-badge" :class="question.difficulty">{{ question.difficulty }}</span>
          <div v-if="!readonly" class="question-actions">
            <NcActions>
              <NcActionButton @click="editQuestion(question)" close-after-click>{{ t('learning', 'Edit') }}</NcActionButton>
              <NcActionButton @click="deleteQuestion(question)" close-after-click>{{ t('learning', 'Delete') }}</NcActionButton>
            </NcActions>
          </div>
        </div>
        <!-- Image display -->
        <img v-if="question.image_path" :src="questionImageUrl(question.id)" :alt="'Image for: ' + question.text" class="question-image" />
        <div class="question-text">{{ question.text }}</div>
        <div class="answers-list">
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

    <QuestionForm v-if="showDialog" :question="editingQuestion" @save="saveQuestion" @close="closeDialog" />
    <ImportDialog v-if="showImportDialog" :poolId="poolId" @close="showImportDialog = false" @imported="onImported" />
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
import ImportDialog from './ImportDialog.vue';

export default {
  name: 'QuestionList',
  components: { NcButton, NcNoteCard, NcEmptyContent, NcActions, NcActionButton, NcLoadingIcon, QuestionForm, ImportDialog },
  props: {
    poolId: { type: Number, required: true },
    poolName: { type: String, required: true },
    readonly: { type: Boolean, default: false }
  },
  data() {
    return { questions: [], loading: false, loadError: null, showDialog: false, showImportDialog: false, editingQuestion: null };
  },
  watch: {
    poolId() { this.loadQuestions(); },
  },
  mounted() { this.loadQuestions(); },
  methods: {
    questionImageUrl(questionId) {
      return generateUrl('/apps/learning/api/questions/' + questionId + '/image');
    },
    async loadQuestions() {
      this.loading = true; this.loadError = null;
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/pools/' + this.poolId + '/questions'));
        this.questions = response.data;
      } catch (error) {
        this.loadError = t('learning', 'Failed to load questions. Check your connection and try again.');
      } finally { this.loading = false; }
    },
    showCreateDialog() { this.editingQuestion = null; this.showDialog = true; },
    editQuestion(question) { this.editingQuestion = question; this.showDialog = true; },
    closeDialog() { this.showDialog = false; this.editingQuestion = null; },
    onImported() { this.showImportDialog = false; this.loadQuestions(); },
    async saveQuestion(questionData) {
      try {
        const { imageFile, removeImage, ...data } = questionData;
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
    async deleteQuestion(question) {
      if (!confirm(t('learning', 'Delete this question?'))) return;
      try {
        await axios.delete(generateUrl('/apps/learning/api/questions/' + question.id));
        showSuccess(t('learning', 'Question deleted')); this.loadQuestions();
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
.question-item { border: 1px solid var(--color-border); border-radius: 12px; padding: 20px 24px; background: var(--color-main-background); transition: box-shadow 0.15s; }
.question-item:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.question-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.question-number { font-weight: 700; color: var(--color-primary-element); font-size: 13px; background: var(--color-primary-element-light); padding: 2px 8px; border-radius: 4px; }
.difficulty-badge { padding: 2px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.difficulty-badge.easy { background: color-mix(in srgb, var(--color-success) 15%, transparent); color: var(--color-success); border: 1px solid var(--color-success); }
.difficulty-badge.medium { background: color-mix(in srgb, var(--color-warning) 15%, transparent); color: var(--color-warning); border: 1px solid var(--color-warning); }
.difficulty-badge.hard { background: color-mix(in srgb, var(--color-error) 15%, transparent); color: var(--color-error); border: 1px solid var(--color-error); }
.question-actions { margin-left: auto; }
.question-image { max-width: 100%; max-height: 200px; border-radius: var(--border-radius-large); border: 1px solid var(--color-border); margin-bottom: 12px; object-fit: contain; }
.question-text { font-size: 15px; line-height: 1.6; margin-bottom: 14px; font-weight: 500; color: var(--color-main-text); }
.answers-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px; margin-bottom: 12px; }
.answer-item { padding: 8px 14px; border-radius: 8px; background: var(--color-background-hover); font-size: 13px; display: flex; align-items: flex-start; gap: 8px; line-height: 1.4; color: var(--color-main-text); }
.answer-item.correct { background: color-mix(in srgb, var(--color-success) 12%, var(--color-main-background)); color: var(--color-success); font-weight: 600; border: 1px solid var(--color-success); }
.answer-icon { font-weight: bold; flex-shrink: 0; margin-top: 1px; }
.explanation-card { margin-top: 8px; }
.loading-center { display: block; margin: 60px auto; }
@media (max-width: 768px) {
  .question-list-header { flex-direction: column; align-items: stretch; }
  .header-actions { justify-content: flex-end; }
  .question-items { grid-template-columns: 1fr; }
  .answers-list { grid-template-columns: 1fr; }
  .question-item { padding: 14px 16px; }
}
</style>
