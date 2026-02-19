<template>
  <div class="leitner-mode">
    <div v-if="!initialized && !initError" class="leitner-init">
      <h3>{{ t('learning', 'Leitner System - Spaced Repetition') }}</h3>
      <p>{{ t('learning', 'Initialize this pool for spaced repetition learning') }}</p>
      <div class="init-actions">
        <NcButton type="primary" @click="initialize" :disabled="initializing">{{ initializing ? t('learning', 'Initializing...') : t('learning', 'Initialize Leitner System') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>

    <div v-else-if="initError" class="leitner-init">
      <NcNoteCard type="error">{{ initError }}</NcNoteCard>
      <div class="init-actions">
        <NcButton type="primary" @click="checkInitialized">{{ t('learning', 'Retry') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>

    <div v-else-if="!started" class="leitner-dashboard">
      <NcNoteCard v-if="stats.due_count > 0" type="warning" class="due-banner">
        <strong>{{ t('learning', '{n} questions due for review', { n: stats.due_count }) }}</strong>
        <NcButton type="primary" @click="startReview" class="due-start-btn">
          {{ t('learning', 'Start Review') }}
        </NcButton>
      </NcNoteCard>
      <NcNoteCard v-else type="success" class="due-banner">
        <strong>{{ t('learning', 'All caught up!') }}</strong><br/><small>{{ t('learning', 'No questions due for review right now') }}</small>
      </NcNoteCard>

      <div class="stats-row">
        <div class="stat-card"><div class="stat-value">{{ stats.total }}</div><div class="stat-label">{{ t('learning', 'Total Cards') }}</div></div>
        <div class="stat-card"><div class="stat-value">{{ stats.accuracy }}%</div><div class="stat-label">{{ t('learning', 'Accuracy') }}</div></div>
        <div class="stat-card"><div class="stat-value">{{ stats.mastery_percentage }}%</div><div class="stat-label">{{ t('learning', 'Mastered') }}</div></div>
        <div class="stat-card"><div class="stat-value">{{ stats.total_answered }}</div><div class="stat-label">{{ t('learning', 'Reviews Done') }}</div></div>
      </div>

      <h4 class="section-title">{{ t('learning', 'Leitner Boxes') }}</h4>
      <div class="box-grid">
        <div v-for="i in 5" :key="i" class="box-card" :class="['box-' + i]">
          <div class="box-header">
            <div class="box-number">Box {{ i }}</div>
            <div class="box-count">{{ stats['box_' + i] || 0 }}</div>
          </div>
          <div class="box-label">{{ boxLabels[i] }}</div>
          <NcProgressBar :value="boxPercentage(i)" size="small" />
        </div>
      </div>

      <div class="mastery-section">
        <div class="mastery-header"><span>{{ t('learning', 'Overall Progress') }}</span><span class="mastery-pct">{{ stats.mastery_percentage }}%</span></div>
        <NcProgressBar :value="stats.mastery_percentage" />
      </div>

      <div class="action-buttons">
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back to Pool') }}</NcButton>
      </div>
    </div>

    <div v-else-if="!showResults" class="leitner-review">
      <div class="review-header">
        <div class="review-counter">{{ currentIndex + 1 }} / {{ dueQuestions.length }}</div>
        <NcProgressBar :value="reviewProgress" />
      </div>
      <div v-if="currentItem" class="review-card">
        <div class="review-box-indicator">Box {{ currentItem.box }} &rarr; {{ answered ? (lastAnswer ? 'Box ' + lastMoveTarget : 'Box 1') : '?' }}</div>
        <div class="question-text">{{ currentItem.text }}</div>
        <div v-if="isCurrentMulti" class="multi-hint">{{ t('learning', 'Select all correct answers') }}</div>
        <div v-if="!answered" class="answer-options">
          <template v-if="isCurrentMulti">
            <button
              v-for="answer in currentItem.answers"
              :key="answer.id"
              @click="toggleMultiAnswer(answer.id)"
              class="answer-btn"
              :class="{ 'answer-selected': selectedAnswerIds.includes(answer.id) }"
              :disabled="submitting"
            >{{ answer.text }}</button>
            <div class="multi-submit-area">
              <NcButton type="primary" @click="submitMultiAnswer" :disabled="submitting || selectedAnswerIds.length === 0">
                {{ t('learning', 'Submit Answer') }}
              </NcButton>
            </div>
          </template>
          <template v-else>
            <button v-for="answer in currentItem.answers" :key="answer.id" @click="submitAnswer(answer)" class="answer-btn" :disabled="submitting">{{ answer.text }}</button>
          </template>
        </div>
        <div v-else class="answer-feedback">
          <NcNoteCard :type="lastAnswer ? 'success' : 'error'">{{ lastAnswer ? t('learning', 'Correct!') : t('learning', 'Incorrect') }}</NcNoteCard>
          <div class="answer-buttons-review">
            <div v-for="answer in currentItem.answers" :key="'fb-' + answer.id"
                 class="answer-btn-review"
                 :class="{
                   'answer-user-selected': isCurrentMulti ? lastSelectedAnswerIds.includes(answer.id) : lastSelectedAnswerId === answer.id,
                   'answer-correct': answer.is_correct,
                   'answer-wrong-selected': (isCurrentMulti ? lastSelectedAnswerIds.includes(answer.id) : lastSelectedAnswerId === answer.id) && !answer.is_correct
                 }">
              {{ answer.text }}
            </div>
          </div>
          <div class="correct-answer-display">
            <template v-if="getCorrectAnswer()">
              <strong>{{ lastCorrectAnswerTexts.length > 1 ? t('learning', 'Correct answers:') : t('learning', 'Correct answer:') }}</strong>
              {{ getCorrectAnswer() }}
            </template>
            <em v-else>{{ t('learning', 'Correct answer hidden during active exam') }}</em>
          </div>
          <NcNoteCard v-if="currentItem.explanation" type="warning">{{ currentItem.explanation }}</NcNoteCard>
          <NcButton type="primary" wide @click="nextQuestion" class="next-btn">{{ currentIndex < dueQuestions.length - 1 ? t('learning', 'Next Question \u2192') : t('learning', 'See Results') }}</NcButton>
        </div>
      </div>
    </div>

    <div v-else class="review-complete">
      <h3>{{ t('learning', 'Review Complete!') }}</h3>
      <div class="session-stats">
        <div class="session-stat correct"><div class="session-stat-value">{{ sessionCorrect }}</div><div class="session-stat-label">{{ t('learning', 'Correct') }}</div></div>
        <div class="session-stat incorrect"><div class="session-stat-value">{{ sessionIncorrect }}</div><div class="session-stat-label">{{ t('learning', 'Incorrect') }}</div></div>
        <div class="session-stat accuracy"><div class="session-stat-value">{{ sessionAccuracy }}%</div><div class="session-stat-label">{{ t('learning', 'Accuracy') }}</div></div>
      </div>
      <NcButton type="primary" @click="finishReview">{{ t('learning', 'Back to Dashboard') }}</NcButton>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';

export default {
  name: 'LeitnerMode',
  components: { NcButton, NcNoteCard, NcProgressBar },
  props: { poolId: { type: Number, required: true } },
  data() {
    return {
      initialized: false, initializing: false, initError: null,
      stats: { total: 0, due_count: 0, accuracy: 0, mastery_percentage: 0, total_answered: 0 },
      dueQuestions: [], started: false, currentIndex: 0, answered: false, submitting: false,
      lastAnswer: false, lastMoveTarget: 0, lastCorrectAnswerText: '', lastCorrectAnswerTexts: [], showResults: false, sessionCorrect: 0, sessionIncorrect: 0,
      selectedAnswerIds: [],
      lastSelectedAnswerId: null,
      lastSelectedAnswerIds: [],
      boxLabels: { 1: t('learning', 'New / Reset'), 2: t('learning', 'After 1 day'), 3: t('learning', 'After 3 days'), 4: t('learning', 'After 7 days'), 5: t('learning', 'Mastered (14d)') }
    };
  },
  computed: {
    currentItem() { return this.dueQuestions[this.currentIndex] || null; },
    isCurrentMulti() { return this.currentItem && this.currentItem.question_type === 'multi'; },
    reviewProgress() { return ((this.currentIndex + (this.answered ? 1 : 0)) / this.dueQuestions.length) * 100; },
    sessionAccuracy() { const total = this.sessionCorrect + this.sessionIncorrect; return total > 0 ? Math.round(this.sessionCorrect / total * 100) : 0; }
  },
  mounted() { this.checkInitialized(); },
  methods: {
    async checkInitialized() {
      this.initError = null;
      try { const r = await axios.get(generateUrl('/apps/learning/api/leitner/stats'), { params: { poolId: this.poolId } }); this.stats = r.data; this.initialized = this.stats.total > 0; }
      catch (e) { this.initError = t('learning', 'Failed to load Leitner stats.'); }
    },
    async initialize() {
      this.initializing = true;
      try { await axios.post(generateUrl('/apps/learning/api/leitner/initialize'), { poolId: this.poolId }); showSuccess(t('learning', 'Leitner system initialized')); await this.checkInitialized(); }
      catch (e) { showError(e.response?.data?.error || t('learning', 'Failed to initialize')); }
      finally { this.initializing = false; }
    },
    async startReview() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/leitner/due'), { params: { poolId: this.poolId, limit: 20 } });
        this.dueQuestions = r.data;
        if (this.dueQuestions.length > 0) { this.started = true; this.currentIndex = 0; this.answered = false; this.sessionCorrect = 0; this.sessionIncorrect = 0; }
      } catch (e) { showError(t('learning', 'Failed to load review questions')); }
    },
    toggleMultiAnswer(answerId) {
      var idx = this.selectedAnswerIds.indexOf(answerId);
      if (idx >= 0) {
        this.selectedAnswerIds.splice(idx, 1);
      } else {
        this.selectedAnswerIds.push(answerId);
      }
    },
    async submitMultiAnswer() {
      this.submitting = true;
      this.lastSelectedAnswerIds = this.selectedAnswerIds.slice();
      try {
        var r = await axios.post(generateUrl('/apps/learning/api/leitner/answer'), {
          itemId: this.currentItem.id,
          answerIds: this.selectedAnswerIds
        });
        this.lastAnswer = r.data.correct; this.lastMoveTarget = r.data.new_box; this.answered = true;
        this.lastCorrectAnswerText = r.data.correct_answer_text || '';
        this.lastCorrectAnswerTexts = r.data.correct_answer_texts || [this.lastCorrectAnswerText];
        if (r.data.correct) this.sessionCorrect++; else this.sessionIncorrect++;
      } catch (e) { showError(t('learning', 'Failed to record answer')); }
      finally { this.submitting = false; }
    },
    async submitAnswer(answer) {
      this.submitting = true;
      this.lastSelectedAnswerId = answer.id;
      try {
        var r = await axios.post(generateUrl('/apps/learning/api/leitner/answer'), { itemId: this.currentItem.id, answerId: answer.id });
        this.lastAnswer = r.data.correct; this.lastMoveTarget = r.data.new_box; this.answered = true;
        this.lastCorrectAnswerText = r.data.correct_answer_text || '';
        this.lastCorrectAnswerTexts = r.data.correct_answer_texts || [this.lastCorrectAnswerText];
        if (r.data.correct) this.sessionCorrect++; else this.sessionIncorrect++;
      } catch (e) { showError(t('learning', 'Failed to record answer')); }
      finally { this.submitting = false; }
    },
    getCorrectAnswer() {
      if (this.lastCorrectAnswerTexts.length > 1) {
        return this.lastCorrectAnswerTexts.join(', ');
      }
      return this.lastCorrectAnswerText || '';
    },
    nextQuestion() {
      if (this.currentIndex < this.dueQuestions.length - 1) { this.currentIndex++; this.answered = false; this.selectedAnswerIds = []; this.lastSelectedAnswerId = null; this.lastSelectedAnswerIds = []; }
      else { this.showResults = true; }
    },
    boxPercentage(n) { return this.stats.total === 0 ? 0 : Math.round((this.stats['box_' + n] || 0) / this.stats.total * 100); },
    finishReview() { this.started = false; this.currentIndex = 0; this.answered = false; this.showResults = false; this.checkInitialized(); }
  }
};
</script>

<style scoped>
.leitner-init { text-align: center; padding: 60px 20px; }
.leitner-init h3 { font-size: 24px; margin-bottom: 12px; color: var(--color-main-text); }
.leitner-init p { color: var(--color-text-maxcontrast); margin-bottom: 24px; }
.init-actions { display: flex; justify-content: center; gap: 8px; }
.due-banner { margin-bottom: 24px; }
.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
.stat-card { text-align: center; padding: 16px 8px; background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.stat-value { font-size: 28px; font-weight: 700; color: var(--color-primary-element); }
.stat-label { font-size: 12px; color: var(--color-text-maxcontrast); margin-top: 4px; }
.section-title { font-size: 16px; font-weight: 600; margin-bottom: 12px; color: var(--color-main-text); }
.box-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 24px; }
.box-card { padding: 16px; border-radius: var(--border-radius-large); border: 2px solid var(--color-border); background: var(--color-main-background); transition: transform 0.2s, box-shadow 0.2s; }
.box-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.box-card.box-1 { border-color: var(--color-error); }
.box-card.box-2 { border-color: var(--color-warning); }
.box-card.box-3 { border-color: var(--color-primary-element); }
.box-card.box-4 { border-color: var(--color-primary-element-light); }
.box-card.box-5 { border-color: var(--color-success); }
.box-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.box-number { font-size: 12px; font-weight: 600; color: var(--color-main-text); }
.box-count { font-size: 24px; font-weight: 700; color: var(--color-main-text); }
.box-label { font-size: 13px; font-weight: 600; color: var(--color-text-maxcontrast); margin-bottom: 8px; }
.mastery-section { margin-bottom: 24px; }
.mastery-header { display: flex; justify-content: space-between; margin-bottom: 8px; font-weight: 500; color: var(--color-main-text); }
.mastery-pct { color: var(--color-primary-element); font-weight: 700; }
.action-buttons { text-align: center; }
.review-header { margin-bottom: 24px; }
.review-counter { text-align: center; font-size: 16px; font-weight: 600; margin-bottom: 8px; color: var(--color-primary-element); }
.review-card { max-width: 700px; margin: 0 auto; padding: 32px; border: 2px solid var(--color-border); border-radius: 12px; background: var(--color-main-background); }
.review-box-indicator { font-size: 12px; color: var(--color-text-maxcontrast); margin-bottom: 16px; }
.question-text { font-size: 20px; line-height: 1.6; margin-bottom: 24px; font-weight: 500; color: var(--color-main-text); }
.answer-options { display: grid; gap: 10px; }
.answer-btn { padding: 14px 16px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-main-background); cursor: pointer; text-align: left; font-size: 15px; transition: all 0.2s; min-height: 52px; color: var(--color-main-text); }
.answer-btn:hover:not(:disabled) { border-color: var(--color-primary-element); background: var(--color-primary-element-light); }
.answer-btn:disabled { opacity: 0.7; cursor: wait; }
.answer-btn.answer-selected { border-color: var(--color-primary-element); background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background)); color: var(--color-primary-element); font-weight: 600; }
.multi-hint { text-align: center; font-size: 14px; font-weight: 600; color: var(--color-primary-element); margin-bottom: 16px; padding: 8px; background: color-mix(in srgb, var(--color-primary-element) 8%, transparent); border-radius: 8px; }
.multi-submit-area { grid-column: 1 / -1; text-align: center; margin-top: 8px; }
.answer-feedback { margin-top: 20px; }
.correct-answer-display { padding: 12px; background: var(--color-background-hover); border-radius: var(--border-radius); margin-bottom: 12px; color: var(--color-main-text); }
.next-btn { margin-top: 16px; }
.review-complete { text-align: center; padding: 40px 20px; }
.review-complete h3 { font-size: 28px; margin-bottom: 32px; color: var(--color-main-text); }
.session-stats { display: flex; justify-content: center; gap: 32px; margin-bottom: 32px; }
.session-stat { text-align: center; }
.session-stat-value { font-size: 36px; font-weight: 700; }
.session-stat-label { font-size: 13px; color: var(--color-text-maxcontrast); margin-top: 4px; }
.session-stat.correct .session-stat-value { color: var(--color-success); }
.session-stat.incorrect .session-stat-value { color: var(--color-error); }
.session-stat.accuracy .session-stat-value { color: var(--color-primary-element); }
@media (max-width: 768px) {
  .box-grid { grid-template-columns: repeat(3, 1fr); }
  .stats-row { grid-template-columns: repeat(2, 1fr); }
  .session-stats { gap: 16px; }
  .session-stat-value { font-size: 28px; }
  .review-card { padding: 20px; }
}
@media (max-width: 480px) {
  .box-grid { grid-template-columns: repeat(2, 1fr); }
  .question-text { font-size: 17px; }
  .review-card { padding: 16px; }
  .leitner-init { padding: 30px 12px; }
}
.due-start-btn { margin-top: 8px; }
.answer-buttons-review { display: grid; gap: 8px; margin-bottom: 12px; }
.answer-btn-review { padding: 10px 14px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); font-size: 14px; color: var(--color-main-text); background: var(--color-main-background); }
.answer-btn-review.answer-correct { border-color: var(--color-success); background: color-mix(in srgb, var(--color-success) 10%, var(--color-main-background)); }
.answer-btn-review.answer-user-selected { border-color: var(--color-primary-element); }
.answer-btn-review.answer-wrong-selected { border-color: var(--color-error); background: color-mix(in srgb, var(--color-error) 10%, var(--color-main-background)); }
</style>