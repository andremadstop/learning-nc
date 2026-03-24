<template>
  <div class="leitner-mode">
    <div v-if="!initialized && !initError" class="leitner-init">
      <h3>{{ t('learning', 'Leitner System - Spaced Repetition') }}</h3>
      <p>{{ t('learning', 'Initialize this pool for spaced repetition learning') }}</p>
      <NcNoteCard v-if="!hintDismissed('leitner-init')" type="info" class="onboarding-hint">
        {{ t('learning', 'Cards start in Box 1. Correct answer → next box (reviewed less often). Wrong → back to Box 1. This way you practice difficult cards more often.') }}
        <NcButton type="tertiary" @click="dismissHint('leitner-init')">{{ t('learning', 'Got it') }}</NcButton>
      </NcNoteCard>
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
        <div v-if="streak.current_streak > 0" class="stat-card streak-card" :title="t('learning', 'Longest: {n} days', { n: streak.longest_streak })"><div class="stat-value streak-value">{{ streak.current_streak }}</div><div class="stat-label"><span class="streak-flames"><span v-for="i in Math.min(streak.current_streak, 5)" :key="i" class="streak-flame" :style="{ animationDelay: (i * 0.12) + 's' }">&#x1F525;</span></span> {{ t('learning', 'Day Streak') }}</div></div>
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
        <QuestionLanguageSwitcher v-model="questionLanguage" />
        <div class="review-box-indicator">{{ t('learning', 'Box') }} {{ currentItem.box }} &rarr; {{ answered ? (lastAnswer ? t('learning', 'Box') + ' ' + lastMoveTarget : t('learning', 'Box') + ' 1') : '?' }}</div>
        <div class="question-text">{{ currentItem.text }}</div>
        <div v-if="isCurrentMulti" class="multi-hint">{{ t('learning', 'Select all correct answers') }}</div>

        <!-- PBQ block -->
        <div v-if="!answered && isPbq" class="pbq-answer-area">
          <PbqRenderer
            :question="currentItem"
            :disabled="submitting"
            @submit="submitPbqAnswer"
            @skip="nextQuestion"
          />
        </div>
        <div v-else-if="answered && isPbq" class="answer-feedback">
          <NcNoteCard :type="lastAnswer ? 'success' : 'error'">
            {{ lastAnswer ? t('learning', 'Correct!') : t('learning', 'Incorrect') }}
            — {{ pbqPoints }}/{{ pbqMaxPoints }} {{ t('learning', 'points') }}
          </NcNoteCard>
          <NcNoteCard v-if="currentItem.explanation" type="warning">
            <strong>{{ t('learning', 'Explanation:') }}</strong> {{ currentItem.explanation }}
          </NcNoteCard>
          <NcButton type="primary" wide @click="nextQuestion" class="next-btn">
            {{ currentIndex < dueQuestions.length - 1 ? t('learning', 'Next Question \u2192') : t('learning', 'See Results') }}
          </NcButton>
        </div>

        <div v-else-if="!answered && isOpenQuestion" class="open-answer-area">
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
          <NcNoteCard :type="lastAnswer ? 'success' : 'error'">{{ lastAnswer ? t('learning', 'Correct!') : t('learning', 'Incorrect') }}</NcNoteCard>
          <div class="open-answer-review">
            <div class="open-review-row"><strong>{{ t('learning', 'Your answer') }}:</strong> {{ lastOpenAnswer }}</div>
            <div class="open-review-row"><strong>{{ t('learning', 'Model answer') }}:</strong> {{ displayCorrectAnswerTexts.length > 0 ? displayCorrectAnswerTexts[0] : '' }}</div>
          </div>
          <NcNoteCard v-if="currentItem.explanation" type="warning">{{ currentItem.explanation }}</NcNoteCard>
          <NcNoteCard v-if="currentItem.note_visible && currentItem.instructor_note" type="info">
            <strong>{{ t('learning', 'Note:') }}</strong> {{ currentItem.instructor_note }}
          </NcNoteCard>
          <div v-if="aiAvailable" class="ai-explain-row">
            <NcButton v-if="!explainTaskId && !explainText" type="tertiary" :disabled="explainLoading" @click="requestExplain">
              {{ explainLoading ? t('learning', 'Thinking...') : t('learning', '💡 Explain this') }}
            </NcButton>
            <div v-if="explainText" class="ai-explain-box">{{ explainText }}</div>
          </div>
          <NcButton type="primary" wide @click="nextQuestion" class="next-btn">{{ currentIndex < dueQuestions.length - 1 ? t('learning', 'Next Question \u2192') : t('learning', 'See Results') }}</NcButton>
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
              <strong>{{ displayCorrectAnswerTexts.length > 1 ? t('learning', 'Correct answers:') : t('learning', 'Correct answer:') }}</strong>
              {{ getCorrectAnswer() }}
            </template>
            <em v-else>{{ t('learning', 'Correct answer hidden during active exam') }}</em>
          </div>
          <NcNoteCard v-if="currentItem.explanation" type="warning">{{ currentItem.explanation }}</NcNoteCard>
          <NcNoteCard v-if="currentItem.note_visible && currentItem.instructor_note" type="info">
            <strong>{{ t('learning', 'Note:') }}</strong> {{ currentItem.instructor_note }}
          </NcNoteCard>
          <div v-if="aiAvailable" class="ai-explain-row">
            <NcButton v-if="!explainTaskId && !explainText" type="tertiary" :disabled="explainLoading" @click="requestExplain">
              {{ explainLoading ? t('learning', 'Thinking...') : t('learning', '💡 Explain this') }}
            </NcButton>
            <div v-if="explainText" class="ai-explain-box">{{ explainText }}</div>
          </div>
          <NcButton type="primary" wide @click="nextQuestion" class="next-btn">{{ currentIndex < dueQuestions.length - 1 ? t('learning', 'Next Question \u2192') : t('learning', 'See Results') }}</NcButton>
        </div>
      </div>
    </div>

    <div v-else class="review-complete">
      <h3>{{ t('learning', 'Review Complete!') }}</h3>
      <div v-if="streak.current_streak > 0" class="streak-banner"><span class="streak-flames"><span v-for="i in Math.min(streak.current_streak, 7)" :key="i" class="streak-flame" :style="{ animationDelay: (i * 0.1) + 's' }">&#x1F525;</span></span> {{ t('learning', 'Day {n}!', { n: streak.current_streak }) }}</div>
      <div class="session-stats">
        <div class="session-stat correct"><div class="session-stat-value">{{ sessionCorrect }}</div><div class="session-stat-label">{{ t('learning', 'Correct') }}</div></div>
        <div class="session-stat incorrect"><div class="session-stat-value">{{ sessionIncorrect }}</div><div class="session-stat-label">{{ t('learning', 'Incorrect') }}</div></div>
        <div class="session-stat accuracy"><div class="session-stat-value" ref="leitnerAccuracy">{{ sessionAccuracy }}%</div><div class="session-stat-label">{{ t('learning', 'Accuracy') }}</div></div>
      </div>
      <NcButton type="primary" @click="finishReview">{{ t('learning', 'Back to Dashboard') }}</NcButton>
      <BadgeUnlock :badges="newBadges" />
      <LevelUpOverlay :levelBefore="levelBefore" :levelAfter="levelAfter" />
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
import { celebrateMastery, celebrateStreak, isStreakMilestone } from '../confetti.js';
import { countUp } from '../countUp.js';
import BadgeUnlock from './BadgeUnlock.vue';
import LevelUpOverlay from './LevelUpOverlay.vue';
import PbqRenderer from './PbqRenderer.vue';
import QuestionLanguageSwitcher from './QuestionLanguageSwitcher.vue';
import hintMixin from '../hintMixin.js';

export default {
  name: 'LeitnerMode',
  components: { NcButton, NcNoteCard, NcProgressBar, BadgeUnlock, LevelUpOverlay, PbqRenderer, QuestionLanguageSwitcher },
  mixins: [hintMixin],
  props: {
    poolId: { type: Number, required: true },
    courseId: { type: Number, default: null },
    contentLanguage: { type: String, default: '' },
  },
  data() {
    return {
      initialized: false, initializing: false, initError: null,
      stats: { total: 0, due_count: 0, accuracy: 0, mastery_percentage: 0, total_answered: 0 },
      dueQuestions: [], started: false, currentIndex: 0, answered: false, submitting: false,
      lastAnswer: false, lastMoveTarget: 0, lastCorrectAnswerText: '', lastCorrectAnswerTexts: [], showResults: false, sessionCorrect: 0, sessionIncorrect: 0,
      selectedAnswerIds: [],
      lastSelectedAnswerId: null,
      lastSelectedAnswerIds: [],
      openAnswer: '',
      lastOpenAnswer: '',
      pbqPoints: 0, pbqMaxPoints: 1,
      streak: { current_streak: 0, longest_streak: 0, is_active_today: false },
      newBadges: [],
      levelBefore: 0,
      levelAfter: 0,
      boxLabels: { 1: t('learning', 'New — review daily'), 2: t('learning', 'Learning — after 1 day'), 3: t('learning', 'Familiar — after 3 days'), 4: t('learning', 'Good — after 7 days'), 5: t('learning', 'Mastered — after 14 days') },
      // AI explain
      aiAvailable: false,
      explainLoading: false,
      explainTaskId: null,
      explainText: '',
      explainPollTimer: null,
      questionLanguage: this.contentLanguage || '',
    };
  },
  computed: {
    currentItem() { return this.dueQuestions[this.currentIndex] || null; },
    isCurrentMulti() { return this.currentItem && this.currentItem.question_type === 'multi'; },
    isOpenQuestion() { return this.currentItem && this.currentItem.question_type === 'open'; },
    isPbq() { return this.currentItem && this.currentItem.question_type === 'pbq'; },
    reviewProgress() { return ((this.currentIndex + (this.answered ? 1 : 0)) / this.dueQuestions.length) * 100; },
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
    this.checkInitialized();
    this.fetchStreak();
    this.checkAiAvailable();
    this.emitVirtuProf('leitner-first-start');
  },
  watch: {
    contentLanguage(newLang, oldLang) {
      if (!this.questionLanguage || this.questionLanguage === oldLang) {
        this.questionLanguage = newLang || '';
      }
    },
    questionLanguage() {
      this.refreshDueQuestionsForLanguage();
    },
    currentItem: {
      handler(q) {
        if (!q) return;
        this.$root.$emit('virtuprof:context', {
          poolId: this.poolId,
          courseId: this.courseId,
          questionContext: {
            questionText: q.text || '',
            answers: (q.answers || []).map(a => a.text || a),
            correctAnswerIndex: this.getCorrectAnswerIndex(q),
            explanation: q.explanation || null,
            questionId: q.question_id || q.id || null,
          },
        });
      },
      immediate: true,
    },
  },
  beforeDestroy() {
    this.$root.$emit('virtuprof:context', { questionContext: null });
  },
  methods: {
    getCorrectAnswerIndex(q) {
      if (!q || !q.answers) return null;
      const idx = q.answers.findIndex(a => a.is_correct || a.correct);
      return idx >= 0 ? idx : null;
    },
    emitVirtuProf(triggerId, context = {}) {
      this.$root.$emit('virtuprof:trigger', triggerId, context);
    },
    badgeDisplayName(badge) {
      return badge?.badge_name || badge?.name || badge?.title || t('learning', 'New badge');
    },
    appendNewBadges(badges) {
      if (!Array.isArray(badges) || badges.length === 0) {
        return;
      }
      this.newBadges = [...this.newBadges, ...badges];
      this.emitVirtuProf('badge-earned', { badgeName: this.badgeDisplayName(badges[0]) });
    },
    dueParams() {
      const params = { poolId: this.poolId, limit: 20 };
      if (this.courseId) {
        params.courseId = this.courseId;
      }
      if (this.effectiveContentLanguage) {
        params.lang = this.effectiveContentLanguage;
      }
      return params;
    },
    mergeFutureDueQuestions(translatedQuestions) {
      if (!Array.isArray(translatedQuestions) || translatedQuestions.length === 0) {
        return this.dueQuestions;
      }
      return translatedQuestions;
    },
    async refreshDueQuestionsForLanguage() {
      if (!this.started || this.showResults || this.dueQuestions.length === 0) {
        return;
      }
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/leitner/due'), { params: this.dueParams() });
        if (Array.isArray(r.data)) {
          this.dueQuestions = this.mergeFutureDueQuestions(r.data);
          this.lastCorrectAnswerTexts = this.displayCorrectAnswerTexts;
        }
      } catch (e) {
        // Language refresh is best-effort only.
      }
    },
    async checkInitialized() {
      this.initError = null;
      try {
        const params = { poolId: this.poolId }
        if (this.courseId) {
          params.courseId = this.courseId
        }
        const r = await axios.get(generateUrl('/apps/learning/api/leitner/stats'), { params })
        this.stats = r.data
        this.initialized = this.stats.total > 0
      }
      catch (e) { this.initError = t('learning', 'Failed to load Leitner stats.'); }
    },
    async initialize() {
      this.initializing = true;
      try {
        const payload = { poolId: this.poolId }
        if (this.courseId) {
          payload.courseId = this.courseId
        }
        await axios.post(generateUrl('/apps/learning/api/leitner/initialize'), payload)
        showSuccess(t('learning', 'Leitner system initialized'))
        await this.checkInitialized()
      }
      catch (e) { showError(e.response?.data?.error || t('learning', 'Failed to initialize')); }
      finally { this.initializing = false; }
    },
    async startReview() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/leitner/due'), { params: this.dueParams() });
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
    async submitOpenAnswer() {
      this.submitting = true;
      this.lastOpenAnswer = this.openAnswer;
      try {
        var r = await axios.post(generateUrl('/apps/learning/api/leitner/answer'), {
          itemId: this.currentItem.id,
          answerText: this.openAnswer,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.lastAnswer = r.data.correct; this.lastMoveTarget = r.data.new_box; this.answered = true;
        this.lastCorrectAnswerText = r.data.correct_answer_text || '';
        this.lastCorrectAnswerTexts = r.data.correct_answer_texts || [this.lastCorrectAnswerText];
        if (r.data.correct) this.sessionCorrect++; else this.sessionIncorrect++;
        if (r.data.new_box === 5) { celebrateMastery(); }
        this.appendNewBadges(r.data.newly_earned_badges);
        if (r.data.level_before && r.data.level_after) { this.levelBefore = r.data.level_before; this.levelAfter = r.data.level_after; }
      } catch (e) { showError(t('learning', 'Failed to submit answer')); }
      finally { this.submitting = false; }
    },
    async submitPbqAnswer(pbqAnswers) {
      this.submitting = true;
      try {
        var r = await axios.post(generateUrl('/apps/learning/api/leitner/answer'), {
          itemId: this.currentItem.id,
          pbqAnswers,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.pbqPoints = r.data.pbq_points ?? 0;
        this.pbqMaxPoints = r.data.pbq_max_points ?? 1;
        this.lastAnswer = r.data.correct; this.lastMoveTarget = r.data.new_box; this.answered = true;
        if (r.data.correct) this.sessionCorrect++; else this.sessionIncorrect++;
        if (r.data.new_box === 5) { celebrateMastery(); }
        this.appendNewBadges(r.data.newly_earned_badges);
        if (r.data.level_before && r.data.level_after) { this.levelBefore = r.data.level_before; this.levelAfter = r.data.level_after; }
      } catch (e) { showError(t('learning', 'Failed to submit answer')); }
      finally { this.submitting = false; }
    },
    async submitMultiAnswer() {
      this.submitting = true;
      this.lastSelectedAnswerIds = this.selectedAnswerIds.slice();
      try {
        var r = await axios.post(generateUrl('/apps/learning/api/leitner/answer'), {
          itemId: this.currentItem.id,
          answerIds: this.selectedAnswerIds,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.lastAnswer = r.data.correct; this.lastMoveTarget = r.data.new_box; this.answered = true;
        this.lastCorrectAnswerText = r.data.correct_answer_text || '';
        this.lastCorrectAnswerTexts = r.data.correct_answer_texts || [this.lastCorrectAnswerText];
        if (r.data.correct) this.sessionCorrect++; else this.sessionIncorrect++;
        if (r.data.new_box === 5) { celebrateMastery(); }
        this.appendNewBadges(r.data.newly_earned_badges);
        if (r.data.level_before && r.data.level_after) { this.levelBefore = r.data.level_before; this.levelAfter = r.data.level_after; }
      } catch (e) { showError(t('learning', 'Failed to record answer')); }
      finally { this.submitting = false; }
    },
    async submitAnswer(answer) {
      this.submitting = true;
      this.lastSelectedAnswerId = answer.id;
      try {
        var r = await axios.post(generateUrl('/apps/learning/api/leitner/answer'), {
          itemId: this.currentItem.id,
          answerId: answer.id,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.lastAnswer = r.data.correct; this.lastMoveTarget = r.data.new_box; this.answered = true;
        this.lastCorrectAnswerText = r.data.correct_answer_text || '';
        this.lastCorrectAnswerTexts = r.data.correct_answer_texts || [this.lastCorrectAnswerText];
        if (r.data.correct) this.sessionCorrect++; else this.sessionIncorrect++;
        if (r.data.new_box === 5) { celebrateMastery(); }
        this.appendNewBadges(r.data.newly_earned_badges);
        if (r.data.level_before && r.data.level_after) { this.levelBefore = r.data.level_before; this.levelAfter = r.data.level_after; }
      } catch (e) { showError(t('learning', 'Failed to record answer')); }
      finally { this.submitting = false; }
    },
    getCorrectAnswer() {
      if (this.displayCorrectAnswerTexts.length > 1) {
        return this.displayCorrectAnswerTexts.join(', ');
      }
      return this.displayCorrectAnswerTexts[0] || this.lastCorrectAnswerText || '';
    },
    async checkAiAvailable() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/ai/available'));
        this.aiAvailable = !!r.data.available;
      } catch (e) { this.aiAvailable = false; }
    },
    async requestExplain() {
      if (!this.currentItem) return;
      this.explainLoading = true;
      this.explainText = '';
      this.explainTaskId = null;
      clearInterval(this.explainPollTimer);
      try {
        const payload = { questionId: this.currentItem.question_id || this.currentItem.id };
        if (this.isOpenQuestion) payload.answerText = this.lastOpenAnswer;
        else if (this.isCurrentMulti) payload.selectedAnswerIds = this.lastSelectedAnswerIds;
        else if (this.lastSelectedAnswerId) payload.selectedAnswerId = this.lastSelectedAnswerId;
        const r = await axios.post(generateUrl('/apps/learning/api/ai/explain'), payload);
        this.explainTaskId = r.data.taskId;
        this.pollExplain();
      } catch (e) {
        this.explainLoading = false;
      }
    },
    pollExplain() {
      if (!this.explainTaskId) return;
      this.explainPollTimer = setInterval(async () => {
        try {
          const r = await axios.get(generateUrl('/apps/learning/api/ai/status/{taskId}', { taskId: this.explainTaskId }));
          if (r.data.status === 'completed') {
            clearInterval(this.explainPollTimer);
            const questions = r.data.questions || [];
            // For explain, the output text is in the raw task output
            this.explainText = r.data.output || (questions.length > 0 ? JSON.stringify(questions[0]) : t('learning', 'No explanation available'));
            this.explainLoading = false;
          } else if (r.data.status === 'failed') {
            clearInterval(this.explainPollTimer);
            this.explainLoading = false;
          }
        } catch (e) {
          clearInterval(this.explainPollTimer);
          this.explainLoading = false;
        }
      }, 1500);
    },
    async nextQuestion() {
      clearInterval(this.explainPollTimer);
      this.explainTaskId = null; this.explainText = ''; this.explainLoading = false;
      if (this.currentIndex < this.dueQuestions.length - 1) { this.currentIndex++; this.answered = false; this.selectedAnswerIds = []; this.lastSelectedAnswerId = null; this.lastSelectedAnswerIds = []; this.openAnswer = ''; this.lastOpenAnswer = ''; }
      else {
        const oldStreak = this.streak.current_streak;
        await this.fetchStreak();
        if (this.streak.current_streak > 0 && isStreakMilestone(this.streak.current_streak) && this.streak.current_streak > oldStreak) {
          celebrateStreak(this.streak.current_streak);
          this.emitVirtuProf('streak-milestone', { days: this.streak.current_streak });
        }
        this.showResults = true;
        this.$nextTick(() => {
          if (this.$refs.leitnerAccuracy) {
            countUp(this.$refs.leitnerAccuracy, this.sessionAccuracy, 1000, '%');
          }
        });
      }
    },
    async fetchStreak() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/streak'));
        this.streak = r.data;
      } catch (e) { /* streak is optional, ignore errors */ }
    },
    boxPercentage(n) { return this.stats.total === 0 ? 0 : Math.round((this.stats['box_' + n] || 0) / this.stats.total * 100); },
    finishReview() { this.started = false; this.currentIndex = 0; this.answered = false; this.showResults = false; this.openAnswer = ''; this.lastOpenAnswer = ''; this.checkInitialized(); this.fetchStreak(); }
  }
};
</script>

<style scoped>
.leitner-init { text-align: center; padding: 60px 20px; }
.leitner-init h3 { font-size: 24px; margin-bottom: 12px; color: var(--color-main-text); }
.leitner-init p { color: var(--color-text-maxcontrast); margin-bottom: 24px; }
.init-actions { display: flex; justify-content: center; gap: 8px; }
.due-banner { margin-bottom: 24px; }
.stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 24px; }
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
.review-card { position: relative; max-width: 700px; margin: 0 auto; padding: 60px 32px 32px; border: 2px solid var(--color-border); border-radius: 12px; background: var(--color-main-background); }
.review-box-indicator { font-size: 12px; color: var(--color-text-maxcontrast); margin-bottom: 16px; }
.question-text { font-size: 20px; line-height: 1.6; margin-bottom: 24px; font-weight: 500; color: var(--color-main-text); }
.answer-options { display: grid; gap: 10px; }
.answer-btn { padding: 14px 16px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-main-background); cursor: pointer; text-align: start; font-size: 15px; transition: all 0.2s; min-height: 52px; color: var(--color-main-text); }
.answer-btn:hover:not(:disabled) { border-color: var(--color-primary-element); background: var(--color-primary-element-light); }
.answer-btn:disabled { opacity: 0.7; cursor: wait; }
.answer-btn.answer-selected { border-color: var(--color-primary-element); background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background)); color: var(--color-primary-element); font-weight: 600; }
.multi-hint { text-align: center; font-size: 14px; font-weight: 600; color: var(--color-primary-element); margin-bottom: 16px; padding: 8px; background: color-mix(in srgb, var(--color-primary-element) 8%, transparent); border-radius: 8px; }
.multi-submit-area { grid-column: 1 / -1; text-align: center; margin-top: 8px; }
.answer-feedback { margin-top: 20px; }
.correct-answer-display { padding: 12px; background: var(--color-background-hover); border-radius: var(--border-radius); margin-bottom: 12px; color: var(--color-main-text); }
.next-btn { margin-top: 16px; }
.ai-explain-row { margin: 10px 0; }
.ai-explain-box { background: color-mix(in srgb, var(--color-primary-element) 8%, transparent); border-inline-start: 3px solid var(--color-primary-element); border-radius: var(--border-radius); padding: 10px 14px; font-size: 0.92em; color: var(--color-main-text); line-height: 1.5; }
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
  .review-card { padding: 56px 20px 20px; }
}
@media (max-width: 480px) {
  .box-grid { grid-template-columns: repeat(2, 1fr); }
  .question-text { font-size: 17px; }
  .review-card { padding: 52px 16px 16px; }
  .leitner-init { padding: 30px 12px; }
}
.due-start-btn { margin-top: 8px; }
.streak-card { border-color: var(--color-warning); background: color-mix(in srgb, var(--color-warning) 8%, var(--color-main-background)); }
.streak-value { color: var(--color-warning); font-size: 2em; text-shadow: 0 0 12px color-mix(in srgb, var(--color-warning) 25%, transparent); }
.streak-flames { display: inline-flex; gap: 1px; }
.streak-flame { display: inline-block; animation: flame-dance 0.8s ease-in-out infinite alternate; }
@keyframes flame-dance { 0% { transform: translateY(0) scale(1); } 100% { transform: translateY(-2px) scale(1.15); } }
@media (prefers-reduced-motion: reduce) { .streak-flame { animation: none; } }
.streak-banner { font-size: 24px; font-weight: 700; color: var(--color-warning); margin-bottom: 16px; }
.answer-buttons-review { display: grid; gap: 8px; margin-bottom: 12px; }
.answer-btn-review { padding: 10px 14px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); font-size: 14px; color: var(--color-main-text); background: var(--color-main-background); }
.answer-btn-review.answer-correct { border-color: var(--color-success); background: color-mix(in srgb, var(--color-success) 10%, var(--color-main-background)); }
.answer-btn-review.answer-user-selected { border-color: var(--color-primary-element); }
.answer-btn-review.answer-wrong-selected { border-color: var(--color-error); background: color-mix(in srgb, var(--color-error) 10%, var(--color-main-background)); }
.onboarding-hint { margin-bottom: 16px; }
.open-answer-area { display: flex; flex-direction: column; gap: 12px; align-items: center; }
.open-textarea { width: 100%; padding: 14px; font-size: 15px; border: 2px solid var(--color-border); border-radius: 12px; resize: vertical; min-height: 80px; box-sizing: border-box; }
.open-textarea:focus { border-color: var(--color-primary-element); outline: none; }
.open-answer-review { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
.open-review-row { padding: 10px 14px; border-radius: 8px; background: var(--color-background-hover); font-size: 14px; line-height: 1.5; }
</style>
