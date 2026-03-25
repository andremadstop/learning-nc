<template>
  <div class="smart-queue">
    <div v-if="loading" class="sq-loading">
      <NcLoadingIcon :size="44" />
    </div>

    <div v-else-if="!started && items.length === 0" class="sq-empty">
      <h3>{{ mode === 'remediation' ? t('learning', 'No trouble spots!') : t('learning', 'All caught up!') }}</h3>
      <p>{{ mode === 'remediation' ? t('learning', 'You have no problem questions right now.') : t('learning', 'No questions due. Open a pool and start the Leitner system to begin spaced repetition.') }}</p>
      <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back to Pools') }}</NcButton>
    </div>

    <div v-else-if="!started" class="sq-ready">
      <h3>{{ mode === 'remediation' ? t('learning', 'Trouble Spots') : t('learning', 'Smart Queue') }}</h3>
      <p>{{ mode === 'remediation' ? t('learning', '{n} problem questions to practice', { n: items.length }) : t('learning', '{n} questions due — sorted by priority: most urgent first.', { n: items.length }) }}</p>
      <NcButton type="primary" @click="started = true">{{ t('learning', 'Start Review') }}</NcButton>
      <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
    </div>

    <div v-else-if="!showResults" class="sq-review">
      <div class="review-header">
        <div class="review-counter">{{ currentIndex + 1 }} / {{ items.length }}</div>
        <NcProgressBar :value="reviewProgress" />
      </div>
      <div v-if="currentItem" class="review-card">
        <QuestionLanguageSwitcher v-model="questionLanguage" :question="currentItem" />
        <div class="pool-badge">{{ currentItem.pool_name }}</div>
        <div class="review-box-indicator">{{ t('learning', 'Box {n}', { n: currentItem.box }) }}</div>
        <NcNoteCard v-if="currentIndex === 0 && !hintDismissed('box-movement')" type="info" class="onboarding-hint">
          {{ t('learning', 'Correct → next box (less often). Wrong → back to Box 1.') }}
          <NcButton type="tertiary" @click="dismissHint('box-movement')">{{ t('learning', 'Got it') }}</NcButton>
        </NcNoteCard>
        <div class="question-text">{{ currentItem.text }}</div>
        <div v-if="isCurrentMulti" class="multi-hint">{{ t('learning', 'Select all correct answers') }}</div>
        <div v-if="!answered && isOpenQuestion" class="open-answer-area">
          <textarea v-model="openAnswer" :placeholder="t('learning', 'Type your answer...')" rows="3" class="nc-input open-textarea" :disabled="submitting"></textarea>
          <NcButton type="primary" @click="submitOpenAnswer" :disabled="submitting || !openAnswer.trim()">
            {{ t('learning', 'Submit Answer') }}
          </NcButton>
        </div>
        <div v-else-if="!answered" class="answer-options">
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
        <div v-else-if="answered && isOpenQuestion" class="answer-feedback">
          <NcNoteCard :type="lastCorrect ? 'success' : 'error'">{{ lastCorrect ? t('learning', 'Correct!') : t('learning', 'Incorrect') }}</NcNoteCard>
          <div class="open-answer-review">
            <div class="open-review-row"><strong>{{ t('learning', 'Your answer') }}:</strong> {{ lastOpenAnswer }}</div>
            <div class="open-review-row"><strong>{{ t('learning', 'Model answer') }}:</strong> {{ displayCorrectAnswerTexts.length > 0 ? displayCorrectAnswerTexts[0] : '' }}</div>
          </div>
          <NcNoteCard v-if="currentItem.explanation" type="warning">{{ currentItem.explanation }}</NcNoteCard>
          <NcNoteCard v-if="currentItem.note_visible && currentItem.instructor_note" type="info">
            <strong>{{ t('learning', 'Note:') }}</strong> {{ currentItem.instructor_note }}
          </NcNoteCard>
          <NcButton type="primary" wide @click="nextQuestion" class="next-btn">{{ currentIndex < items.length - 1 ? t('learning', 'Next Question \u2192') : t('learning', 'See Results') }}</NcButton>
        </div>
        <div v-else class="answer-feedback">
          <NcNoteCard :type="lastCorrect ? 'success' : 'error'">{{ lastCorrect ? t('learning', 'Correct!') : t('learning', 'Incorrect') }}</NcNoteCard>
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
            <template v-if="displayCorrectAnswerTexts.length > 0">
              <strong>{{ displayCorrectAnswerTexts.length > 1 ? t('learning', 'Correct answers:') : t('learning', 'Correct answer:') }}</strong>
              {{ displayCorrectAnswerTexts.join(', ') }}
            </template>
          </div>
          <NcNoteCard v-if="currentItem.explanation" type="warning">{{ currentItem.explanation }}</NcNoteCard>
          <NcNoteCard v-if="currentItem.note_visible && currentItem.instructor_note" type="info">
            <strong>{{ t('learning', 'Note:') }}</strong> {{ currentItem.instructor_note }}
          </NcNoteCard>
          <NcButton type="primary" wide @click="nextQuestion" class="next-btn">{{ currentIndex < items.length - 1 ? t('learning', 'Next Question →') : t('learning', 'See Results') }}</NcButton>
        </div>
      </div>
    </div>

    <div v-else class="sq-results">
      <h3>{{ t('learning', 'Review Complete!') }}</h3>
      <div class="session-stats">
        <div class="session-stat correct"><div class="session-stat-value">{{ sessionCorrect }}</div><div class="session-stat-label">{{ t('learning', 'Correct') }}</div></div>
        <div class="session-stat incorrect"><div class="session-stat-value">{{ sessionIncorrect }}</div><div class="session-stat-label">{{ t('learning', 'Incorrect') }}</div></div>
        <div class="session-stat accuracy"><div class="session-stat-value">{{ sessionAccuracy }}%</div><div class="session-stat-label">{{ t('learning', 'Accuracy') }}</div></div>
      </div>

      <div v-if="Object.keys(poolBreakdown).length > 1" class="pool-breakdown">
        <h4>{{ t('learning', 'By Pool') }}</h4>
        <div v-for="(stats, poolName) in poolBreakdown" :key="poolName" class="pool-breakdown-row">
          <span class="pool-breakdown-name">{{ poolName }}</span>
          <span class="pool-breakdown-stats">{{ stats.correct }}/{{ stats.total }} {{ t('learning', 'correct') }}</span>
        </div>
      </div>

      <div class="result-actions">
        <NcButton type="primary" @click="$emit('back')">{{ t('learning', 'Done') }}</NcButton>
      </div>
      <BadgeUnlock :badges="newBadges" />
      <LevelUpOverlay :levelBefore="levelBefore" :levelAfter="levelAfter" />
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js';
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError } from '@nextcloud/dialogs';
import { celebrateMastery } from '../confetti.js';
import BadgeUnlock from './BadgeUnlock.vue';
import LevelUpOverlay from './LevelUpOverlay.vue';
import QuestionLanguageSwitcher from './QuestionLanguageSwitcher.vue';
import hintMixin from '../hintMixin.js';

export default {
  name: 'SmartQueue',
  components: { NcButton, NcNoteCard, NcProgressBar, NcLoadingIcon, BadgeUnlock, LevelUpOverlay, QuestionLanguageSwitcher },
  mixins: [hintMixin],
  props: {
    mode: { type: String, default: 'queue', validator: v => ['queue', 'remediation'].includes(v) },
    contentLanguage: { type: String, default: '' },
  },
  data() {
    return {
      loading: false,
      items: [],
      started: false,
      currentIndex: 0,
      answered: false,
      submitting: false,
      lastCorrect: false,
      lastCorrectAnswerTexts: [],
      selectedAnswerIds: [],
      lastSelectedAnswerId: null,
      lastSelectedAnswerIds: [],
      openAnswer: '',
      lastOpenAnswer: '',
      showResults: false,
      sessionCorrect: 0,
      sessionIncorrect: 0,
      poolBreakdown: {},
      newBadges: [],
      levelBefore: 0,
      levelAfter: 0,
      questionLanguage: this.contentLanguage || '',
    };
  },
  computed: {
    currentItem() { return this.items[this.currentIndex] || null; },
    isCurrentMulti() { return this.currentItem && this.currentItem.question_type === 'multi'; },
    isOpenQuestion() { return this.currentItem && this.currentItem.question_type === 'open'; },
    reviewProgress() { return ((this.currentIndex + (this.answered ? 1 : 0)) / this.items.length) * 100; },
    sessionAccuracy() { const total = this.sessionCorrect + this.sessionIncorrect; return total > 0 ? Math.round(this.sessionCorrect / total * 100) : 0; },
    effectiveContentLanguage() { return this.questionLanguage || ''; },
    displayCorrectAnswerTexts() {
      if (this.currentItem && Array.isArray(this.currentItem.answers)) {
        const texts = this.currentItem.answers
          .filter(answer => answer.is_correct)
          .map(answer => answer.text)
          .filter(Boolean);
        if (texts.length > 0) {
          return texts;
        }
      }
      return Array.isArray(this.lastCorrectAnswerTexts) ? this.lastCorrectAnswerTexts.filter(Boolean) : [];
    },
  },
  mounted() {
    this.loadQueue();
  },
  watch: {
    contentLanguage(newLang, oldLang) {
      if (!this.questionLanguage || this.questionLanguage === oldLang) {
        this.questionLanguage = newLang || '';
      }
    },
    questionLanguage() {
      this.refreshQueueForLanguage();
    },
  },
  methods: {
    queueParams() {
      const params = { limit: 30 };
      if (this.effectiveContentLanguage) {
        params.lang = this.effectiveContentLanguage;
      }
      return params;
    },
    mergeFutureItems(translatedItems) {
      if (!Array.isArray(translatedItems) || translatedItems.length === 0) {
        return this.items;
      }
      return translatedItems;
    },
    async refreshQueueForLanguage() {
      if (this.showResults || this.items.length === 0) {
        return;
      }
      try {
        const endpoint = this.mode === 'remediation' ? '/apps/learning/api/leitner/remediation' : '/apps/learning/api/leitner/queue';
        const r = await axios.get(generateUrl(endpoint), { params: this.queueParams() });
        if (Array.isArray(r.data)) {
          this.items = this.mergeFutureItems(r.data);
          this.lastCorrectAnswerTexts = this.displayCorrectAnswerTexts;
        }
      } catch (e) {
        // Language refresh is best-effort only.
      }
    },
    async loadQueue() {
      this.loading = true;
      try {
        const endpoint = this.mode === 'remediation' ? '/apps/learning/api/leitner/remediation' : '/apps/learning/api/leitner/queue';
        const r = await axios.get(generateUrl(endpoint), { params: this.queueParams() });
        this.items = r.data;
        if (this.items.length > 0) {
          this.started = true;
        }
      } catch (e) {
        showError(t('learning', 'Failed to load smart queue'));
      } finally {
        this.loading = false;
      }
    },
    async submitOpenAnswer() {
      this.submitting = true;
      this.lastOpenAnswer = this.openAnswer;
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/leitner/answer'), {
          itemId: this.currentItem.id,
          answerText: this.openAnswer,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.handleAnswerResponse(r.data);
      } catch (e) {
        showError(t('learning', 'Failed to submit answer'));
      } finally {
        this.submitting = false;
      }
    },
    toggleMultiAnswer(answerId) {
      const idx = this.selectedAnswerIds.indexOf(answerId);
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
        const r = await axios.post(generateUrl('/apps/learning/api/leitner/answer'), {
          itemId: this.currentItem.id,
          answerIds: this.selectedAnswerIds,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.handleAnswerResponse(r.data);
      } catch (e) {
        showError(t('learning', 'Failed to record answer'));
      } finally {
        this.submitting = false;
      }
    },
    async submitAnswer(answer) {
      this.submitting = true;
      this.lastSelectedAnswerId = answer.id;
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/leitner/answer'), {
          itemId: this.currentItem.id,
          answerId: answer.id,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.handleAnswerResponse(r.data);
      } catch (e) {
        showError(t('learning', 'Failed to record answer'));
      } finally {
        this.submitting = false;
      }
    },
    handleAnswerResponse(data) {
      this.lastCorrect = data.correct;
      this.lastCorrectAnswerTexts = data.correct_answer_texts || [data.correct_answer_text || ''];
      this.answered = true;
      if (data.correct) this.sessionCorrect++; else this.sessionIncorrect++;
      if (data.new_box === 5) { celebrateMastery(); }
      if (data.newly_earned_badges && data.newly_earned_badges.length > 0) {
        this.newBadges = [...this.newBadges, ...data.newly_earned_badges];
      }
      if (data.level_before && data.level_after) {
        this.levelBefore = data.level_before;
        this.levelAfter = data.level_after;
      }

      // Track per-pool stats
      const poolName = this.currentItem.pool_name || 'Unknown';
      if (!this.poolBreakdown[poolName]) {
        this.poolBreakdown[poolName] = { correct: 0, total: 0 };
      }
      this.poolBreakdown[poolName].total++;
      if (data.correct) this.poolBreakdown[poolName].correct++;
    },
    nextQuestion() {
      if (this.currentIndex < this.items.length - 1) {
        this.currentIndex++;
        this.answered = false;
        this.selectedAnswerIds = [];
        this.lastSelectedAnswerId = null;
        this.lastSelectedAnswerIds = [];
        this.openAnswer = '';
        this.lastOpenAnswer = '';
      } else {
        this.showResults = true;
      }
    },
  },
};
</script>

<style scoped>
.smart-queue { max-width: 700px; margin: 0 auto; }
.sq-loading { text-align: center; padding: 60px 20px; }
.sq-empty { text-align: center; padding: 60px 20px; }
.sq-empty h3 { font-size: 24px; margin-bottom: 12px; color: var(--color-main-text); }
.sq-empty p { color: var(--color-text-maxcontrast); margin-bottom: 24px; }
.sq-ready { text-align: center; padding: 60px 20px; }
.sq-ready h3 { font-size: 24px; margin-bottom: 12px; color: var(--color-main-text); }
.sq-ready p { color: var(--color-text-maxcontrast); margin-bottom: 24px; }

.review-header { margin-bottom: 24px; }
.review-counter { text-align: center; font-size: 16px; font-weight: 600; margin-bottom: 8px; color: var(--color-primary-element); }

.review-card { position: relative; padding: 60px 32px 32px; border: 2px solid var(--color-border); border-radius: 12px; background: var(--color-main-background); }
.pool-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; background: var(--color-primary-element); color: var(--color-primary-element-text); margin-bottom: 12px; }
.review-box-indicator { font-size: 12px; color: var(--color-text-maxcontrast); margin-bottom: 16px; }
.question-text { font-size: 20px; line-height: 1.6; margin-bottom: 24px; font-weight: 500; color: var(--color-main-text); }
.multi-hint { text-align: center; font-size: 14px; font-weight: 600; color: var(--color-primary-element); margin-bottom: 16px; padding: 8px; background: color-mix(in srgb, var(--color-primary-element) 8%, transparent); border-radius: 8px; }
.answer-options { display: grid; gap: 10px; }
.answer-btn { padding: 14px 16px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-main-background); cursor: pointer; text-align: left; font-size: 15px; transition: all 0.2s; min-height: 52px; color: var(--color-main-text); }
.answer-btn:hover:not(:disabled) { border-color: var(--color-primary-element); background: var(--color-primary-element-light); }
.answer-btn:disabled { opacity: 0.7; cursor: wait; }
.answer-btn.answer-selected { border-color: var(--color-primary-element); background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background)); color: var(--color-primary-element); font-weight: 600; }
.multi-submit-area { grid-column: 1 / -1; text-align: center; margin-top: 8px; }

.answer-feedback { margin-top: 20px; }
.correct-answer-display { padding: 12px; background: var(--color-background-hover); border-radius: var(--border-radius); margin-bottom: 12px; color: var(--color-main-text); }
.next-btn { margin-top: 16px; }

.answer-buttons-review { display: grid; gap: 8px; margin-bottom: 12px; }
.answer-btn-review { padding: 10px 14px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); font-size: 14px; color: var(--color-main-text); background: var(--color-main-background); }
.answer-btn-review.answer-correct { border-color: var(--color-success); background: color-mix(in srgb, var(--color-success) 10%, var(--color-main-background)); }
.answer-btn-review.answer-user-selected { border-color: var(--color-primary-element); }
.answer-btn-review.answer-wrong-selected { border-color: var(--color-error); background: color-mix(in srgb, var(--color-error) 10%, var(--color-main-background)); }

.sq-results { text-align: center; padding: 40px 20px; }
.sq-results h3 { font-size: 28px; margin-bottom: 32px; color: var(--color-main-text); }
.session-stats { display: flex; justify-content: center; gap: 32px; margin-bottom: 32px; }
.session-stat { text-align: center; }
.session-stat-value { font-size: 36px; font-weight: 700; }
.session-stat-label { font-size: 13px; color: var(--color-text-maxcontrast); margin-top: 4px; }
.session-stat.correct .session-stat-value { color: var(--color-success); }
.session-stat.incorrect .session-stat-value { color: var(--color-error); }
.session-stat.accuracy .session-stat-value { color: var(--color-primary-element); }

.pool-breakdown { margin-bottom: 24px; text-align: left; max-width: 400px; margin-left: auto; margin-right: auto; }
.pool-breakdown h4 { font-size: 14px; font-weight: 600; color: var(--color-text-maxcontrast); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
.pool-breakdown-row { display: flex; justify-content: space-between; padding: 8px 12px; border-radius: 8px; background: var(--color-background-hover); margin-bottom: 4px; }
.pool-breakdown-name { font-weight: 500; color: var(--color-main-text); }
.pool-breakdown-stats { color: var(--color-text-maxcontrast); font-size: 13px; }
.result-actions { display: flex; justify-content: center; gap: 14px; }
.onboarding-hint { margin-bottom: 12px; }
.open-answer-area { display: flex; flex-direction: column; gap: 12px; align-items: center; }
.open-textarea { width: 100%; padding: 14px; font-size: 15px; border: 2px solid var(--color-border); border-radius: 12px; resize: vertical; min-height: 80px; box-sizing: border-box; }
.open-textarea:focus { border-color: var(--color-primary-element); outline: none; }
.open-answer-review { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
.open-review-row { padding: 10px 14px; border-radius: 8px; background: var(--color-background-hover); font-size: 14px; line-height: 1.5; }

@media (max-width: 768px) {
  .review-card { padding: 56px 20px 20px; }
  .question-text { font-size: 17px; }
  .session-stats { gap: 16px; }
  .session-stat-value { font-size: 28px; }
}
</style>
