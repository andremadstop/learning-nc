<template>
  <div class="swipe-mode">
    <div v-if="!session && !loadError" class="swipe-start">
      <h3>{{ t('learning', 'Wahr/Falsch') }}</h3>

      <!-- Pool picker: only shown in standalone mode with multiple pools -->
      <div v-if="pools.length > 1" class="pool-picker">
        <label class="pool-picker-label">{{ t('learning', 'Pool') }}</label>
        <select v-model="activePoolId" class="pool-select">
          <option :value="0" disabled>{{ t('learning', '— Pool auswählen —') }}</option>
          <option v-for="p in pools" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>
      <div v-else-if="pools.length === 1" class="pool-name-chip">{{ pools[0].name }}</div>

      <p v-if="activePoolId > 0">{{ t('learning', 'Decide: true or false — swipe or tap to continue') }}</p>
      <NcNoteCard v-if="starting" type="info" class="status-note">{{ t('learning', 'Session is starting...') }}</NcNoteCard>
      <NcNoteCard v-else-if="poolsLoading" type="info" class="status-note">{{ t('learning', 'Loading pools...') }}</NcNoteCard>
      <NcEmptyContent v-else-if="!poolsLoading && pools.length === 0 && !activePoolId" :name="t('learning', 'No pools available')" :description="t('learning', 'Create a pool with questions first')" />
      <div class="start-actions">
        <NcButton v-if="activePoolId > 0" type="primary" @click="startSession" :disabled="starting || poolsLoading">{{ starting ? t('learning', 'Starting...') : t('learning', 'Start') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')" :disabled="starting">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>

    <div v-else-if="loadError" class="swipe-start">
      <NcNoteCard type="error">{{ loadError }}</NcNoteCard>
      <div class="start-actions">
        <NcButton type="primary" @click="startSession" :disabled="starting">{{ starting ? t('learning', 'Retrying...') : t('learning', 'Retry') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>

    <div v-else-if="!showResults" class="swipe-active">
      <div class="progress-area">
        <div class="progress-text">{{ currentIndex + 1 }} / {{ questions.length }}</div>
        <NcProgressBar :value="progressPercent" />
      </div>

      <div class="card-stack">
        <div
          v-if="currentQuestion"
          :key="currentIndex"
          class="swipe-card"
          :class="[cardAnimClass, { 'card-dragging': isDragging }]"
          :style="cardDragStyle"
          @pointerdown="onPointerDown"
          @pointermove="onPointerMove"
          @pointerup="onPointerUp"
          @pointercancel="onPointerUp"
        >
          <QuestionLanguageSwitcher v-model="questionLanguage" />
          <div v-if="showFeedback && !isDragging" class="feedback-overlay" :class="isCorrect ? 'feedback-correct' : 'feedback-incorrect'">
            <span class="feedback-icon">{{ isCorrect ? '\u2713' : '\u2717' }}</span>
            <span class="feedback-label">{{ isCorrect ? t('learning', 'Correct!') : t('learning', 'Wrong') }}</span>
          </div>
          <div class="card-content">
            <img v-if="currentQuestion.image_path" :src="questionImageUrl(currentQuestion.id)" alt="" class="question-image" />
            <p class="question-text">{{ currentQuestion.text }}</p>
            <div v-if="showFeedback" class="explanation-area">
              <p><strong>{{ displayCorrectAnswerTexts.length > 1 ? t('learning', 'Correct answers:') : t('learning', 'Correct:') }}</strong> {{ displayCorrectAnswerTexts.join(', ') || correctAnswerText }}</p>
              <p v-if="currentQuestion.explanation" class="explanation-text">{{ currentQuestion.explanation }}</p>
            </div>
          </div>
        </div>
      </div>

      <div v-if="isMultiSelect && !showFeedback" class="multi-hint">{{ t('learning', 'Select all correct answers') }}</div>

      <div v-if="currentQuestion && !showFeedback" class="answer-grid">
        <template v-if="hasAnswers">
          <template v-if="isMultiSelect">
            <button
              v-for="answer in currentQuestion.answers"
              :key="answer.id"
              class="answer-btn"
              :class="{ 'answer-selected': selectedAnswerIds.includes(answer.id) }"
              :disabled="submitting"
              @click="toggleMultiAnswer(answer.id)"
            >
              {{ answer.text }}
            </button>
            <div class="multi-submit-area">
              <NcButton type="primary" @click="submitMultiAnswer" :disabled="submitting || selectedAnswerIds.length === 0">
                {{ t('learning', 'Confirm') }}
              </NcButton>
            </div>
          </template>
          <template v-else>
            <button
              v-for="answer in currentQuestion.answers"
              :key="answer.id"
              class="answer-btn"
              :class="{ 'answer-selected': selectedAnswerId === answer.id }"
              :disabled="submitting"
              @click="selectAnswer(answer)"
            >
              {{ answer.text }}
            </button>
          </template>
        </template>
        <div v-else class="no-answers">
          <p>{{ t('learning', 'This question has no answers yet.') }}</p>
          <NcButton type="secondary" @click="skipQuestion">{{ t('learning', 'Skip') }}</NcButton>
        </div>
      </div>

      <div v-if="showFeedback" class="swipe-hint-area">
        <p class="swipe-hint">\u2190 {{ t('learning', 'Swipe or tap Next') }} \u2192</p>
        <NcButton type="primary" class="next-btn" @click="advanceCard">
          {{ currentIndex < questions.length - 1 ? t('learning', 'Next \u2192') : t('learning', 'See Results') }}
        </NcButton>
      </div>

      <div v-if="submitting" class="submission-status">{{ t('learning', 'Submitting answer...') }}</div>
    </div>

    <div v-else class="swipe-results">
      <h3>{{ t('learning', 'Session Complete!') }}</h3>
      <div class="score-area">
        <div class="score-circle">
          <span class="score-number" ref="swipeScoreNumber">{{ results ? results.score_percentage : 0 }}%</span>
        </div>
        <p class="score-detail" v-if="results">{{ results.correct_answers }} / {{ results.total_questions }} {{ t('learning', 'correct') }}</p>
      </div>
      <div v-if="results && results.xp_earned" class="xp-earned">+{{ results.xp_earned }} XP</div>
      <div class="start-actions">
        <NcButton type="primary" @click="restartSession">{{ t('learning', 'Play Again') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
      <BadgeUnlock :badges="newBadges" />
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js';
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError } from '@nextcloud/dialogs';
import { countUp } from '../countUp.js';
import BadgeUnlock from './BadgeUnlock.vue';
import QuestionLanguageSwitcher from './QuestionLanguageSwitcher.vue';

export default {
  name: 'SwipeMode',
  components: { NcButton, NcNoteCard, NcProgressBar, NcEmptyContent, BadgeUnlock, QuestionLanguageSwitcher },
  props: {
    poolId: { type: Number, default: 0 },
    courseId: { type: Number, default: null },
    totalQuestions: { type: Number, default: 0 },
    contentLanguage: { type: String, default: '' }
  },
  data() {
    return {
      pools: [],
      activePoolId: 0,
      poolsLoading: false,
      session: null,
      questions: [],
      currentIndex: 0,
      selectedAnswerId: null,
      selectedAnswerIds: [],
      lastSelectedAnswerIds: [],
      correctAnswerId: null,
      correctAnswerTexts: [],
      submitting: false,
      showFeedback: false,
      isCorrect: false,
      correctAnswerText: '',
      showResults: false,
      results: null,
      starting: false,
      loadError: null,
      cardAnimClass: '',
      // Swipe / drag state
      canDrag: false,
      isDragging: false,
      pointerDown: false,
      dragStartX: 0,
      dragStartY: 0,
      dragDeltaX: 0,
      newBadges: [],
      questionLanguage: this.contentLanguage || '',
    };
  },
  computed: {
    currentQuestion() { return this.questions[this.currentIndex] || null; },
    isMultiSelect() { return this.currentQuestion && this.currentQuestion.question_type === 'multi'; },
    hasAnswers() {
      return this.currentQuestion
        && Array.isArray(this.currentQuestion.answers)
        && this.currentQuestion.answers.length > 0;
    },
    progressPercent() {
      return this.questions.length > 0
        ? Math.round(((this.currentIndex + (this.showFeedback ? 1 : 0)) / this.questions.length) * 100)
        : 0;
    },
    effectiveContentLanguage() {
      return this.questionLanguage || '';
    },
    displayCorrectAnswerTexts() {
      if (this.currentQuestion && Array.isArray(this.currentQuestion.answers)) {
        const texts = this.currentQuestion.answers
          .filter(answer => answer.is_correct)
          .map(answer => answer.text)
          .filter(Boolean);
        if (texts.length > 0) {
          return texts;
        }
      }
      return Array.isArray(this.correctAnswerTexts) ? this.correctAnswerTexts.filter(Boolean) : [];
    },
    cardDragStyle() {
      if (this.dragDeltaX === 0) return {};
      const x = this.dragDeltaX;
      const rotate = Math.min(Math.max(x * 0.06, -15), 15);
      const opacity = Math.max(0.4, 1 - Math.abs(x) / 500);
      return {
        transform: 'translateX(' + x + 'px) rotate(' + rotate + 'deg)',
        opacity: String(opacity),
      };
    },
  },
  mounted() {
    if (this.poolId > 0) {
      this.activePoolId = this.poolId;
    } else {
      this.fetchPools();
    }
  },
  watch: {
    contentLanguage(newLang, oldLang) {
      if (!this.questionLanguage || this.questionLanguage === oldLang) {
        this.questionLanguage = newLang || '';
      }
    },
    questionLanguage() {
      this.refreshQuestionsForLanguage();
    },
  },
  methods: {
    requestPayload(payload = {}) {
      const basePayload = this.courseId ? { ...payload, courseId: this.courseId } : payload;
      return this.effectiveContentLanguage ? { ...basePayload, lang: this.effectiveContentLanguage } : basePayload;
    },
    buildStatusParams(includeQuestions = false) {
      const params = {};
      if (this.effectiveContentLanguage) {
        params.lang = this.effectiveContentLanguage;
      }
      if (includeQuestions) {
        params.includeQuestions = true;
      }
      return params;
    },
    mergeFutureQuestions(translatedQuestions) {
      if (!Array.isArray(translatedQuestions) || translatedQuestions.length === 0) {
        return this.questions;
      }
      return translatedQuestions;
    },
    async refreshQuestionsForLanguage() {
      if (!this.session || this.showResults) {
        return;
      }
      try {
        const response = await axios.get(
          generateUrl('/apps/learning/api/training/session/' + this.session),
          { params: this.buildStatusParams(true) }
        );
        if (Array.isArray(response.data?.questions)) {
          this.questions = this.mergeFutureQuestions(
            response.data.questions.filter(q => q.question_type !== 'open')
          );
          this.correctAnswerTexts = this.displayCorrectAnswerTexts;
        }
      } catch (error) {
        // Language refresh is best-effort only.
      }
    },
    async fetchPools() {
      this.poolsLoading = true;
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/pools'));
        this.pools = r.data || [];
        if (this.pools.length === 1) {
          this.activePoolId = this.pools[0].id;
        }
      } catch (e) {
        this.loadError = t('learning', 'Failed to load pools');
      } finally {
        this.poolsLoading = false;
      }
    },

    questionImageUrl(id) {
      return generateUrl('/apps/learning/api/questions/' + id + '/image');
    },

    async startSession() {
      this.starting = true;
      this.loadError = null;
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/training/start'), this.requestPayload({ poolId: this.activePoolId }));
        this.session = r.data.session_id;
        this.questions = r.data.questions.filter(q => q.question_type !== 'open');
        if (this.questions.length === 0) {
          this.loadError = t('learning', 'All questions in this pool are open-ended and cannot be used in Swipe mode.');
          return;
        }
        this.currentIndex = 0;
        this.showResults = false;
        this.results = null;
        this.submitting = false;
        this.showFeedback = false;
        this.selectedAnswerId = null;
        this.correctAnswerId = null;
        this.canDrag = false;
        this.isDragging = false;
        this.dragDeltaX = 0;
        this.cardAnimClass = 'card-enter';
      } catch (e) {
        this.loadError = e.response?.data?.error || t('learning', 'Failed to start session');
        showError(t('learning', 'Failed to start session'));
      } finally {
        this.starting = false;
      }
    },

    // --- Pointer / touch drag ---
    onPointerDown(e) {
      if (!this.canDrag) return;
      this.pointerDown = true;
      this.dragStartX = e.clientX;
      this.dragStartY = e.clientY;
      this.dragDeltaX = 0;
      this.isDragging = false;
    },

    onPointerMove(e) {
      if (!this.pointerDown) return;

      if (!this.isDragging) {
        var dx = Math.abs(e.clientX - this.dragStartX);
        var dy = Math.abs(e.clientY - this.dragStartY);
        if (dx > dy && dx > 8) {
          this.isDragging = true;
          e.preventDefault();
        } else if (dy > dx && dy > 8) {
          this.pointerDown = false;
          return;
        }
        return;
      }

      e.preventDefault();
      this.dragDeltaX = e.clientX - this.dragStartX;
    },

    onPointerUp() {
      if (!this.pointerDown) return;
      this.pointerDown = false;

      if (!this.isDragging) {
        this.dragDeltaX = 0;
        return;
      }
      this.isDragging = false;

      var threshold = 80;
      if (Math.abs(this.dragDeltaX) > threshold) {
        var direction = this.dragDeltaX > 0 ? 1 : -1;
        this.dragDeltaX = direction * (window.innerWidth + 100);
        this.canDrag = false;
        setTimeout(this.goToNext, 350);
      } else {
        this.dragDeltaX = 0;
      }
    },

    // --- Answer handling ---
    toggleMultiAnswer(answerId) {
      var idx = this.selectedAnswerIds.indexOf(answerId);
      if (idx >= 0) {
        this.selectedAnswerIds.splice(idx, 1);
      } else {
        this.selectedAnswerIds.push(answerId);
      }
    },
    async submitMultiAnswer() {
      if (this.submitting) return;
      this.submitting = true;
      this.lastSelectedAnswerIds = this.selectedAnswerIds.slice();
      try {
        var r = await axios.post(generateUrl('/apps/learning/api/training/answer'), {
          sessionId: this.session,
          questionId: this.currentQuestion.id,
          answerIds: this.selectedAnswerIds,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.isCorrect = r.data.is_correct;
        this.correctAnswerText = r.data.correct_answer_text || '';
        this.correctAnswerTexts = r.data.correct_answer_texts || [this.correctAnswerText];
        this.correctAnswerId = null;
      } catch (e) {
        showError(t('learning', 'Failed to submit answer'));
        this.isCorrect = false;
        this.correctAnswerText = '';
        this.correctAnswerTexts = [];
        this.correctAnswerId = null;
      }
      this.submitting = false;
      this.showFeedback = true;
      this.canDrag = true;
    },
    async selectAnswer(answer) {
      if (this.submitting) return;
      this.submitting = true;
      this.selectedAnswerId = answer.id;

      try {
        var r = await axios.post(generateUrl('/apps/learning/api/training/answer'), {
          sessionId: this.session,
          questionId: this.currentQuestion.id,
          answerId: answer.id,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.isCorrect = r.data.is_correct;
        this.correctAnswerId = r.data.correct_answer_id;
        this.correctAnswerText = r.data.correct_answer_text || '';
        this.correctAnswerTexts = r.data.correct_answer_texts || [this.correctAnswerText];
      } catch (e) {
        showError(t('learning', 'Failed to submit answer'));
        this.isCorrect = false;
        this.correctAnswerText = '';
        this.correctAnswerTexts = [];
        this.correctAnswerId = null;
      }

      this.submitting = false;
      this.showFeedback = true;
      this.canDrag = true;
    },

    advanceCard() {
      this.canDrag = false;
      this.dragDeltaX = window.innerWidth + 100;
      setTimeout(this.goToNext, 380);
    },

    skipQuestion() {
      this.dragDeltaX = window.innerWidth + 100;
      setTimeout(this.goToNext, 380);
    },

    goToNext() {
      this.showFeedback = false;
      this.selectedAnswerId = null;
      this.selectedAnswerIds = [];
      this.lastSelectedAnswerIds = [];
      this.correctAnswerId = null;
      this.correctAnswerTexts = [];
      this.cardAnimClass = '';
      this.canDrag = false;
      this.isDragging = false;
      this.pointerDown = false;
      this.dragDeltaX = 0;
      this.currentIndex++;

      if (this.currentIndex < this.questions.length) {
        this.$nextTick(function() {
          this.cardAnimClass = 'card-enter';
        }.bind(this));
      } else {
        this.completeSession();
      }
    },

    async completeSession() {
      try {
        var r = await axios.post(generateUrl('/apps/learning/api/training/complete'), this.requestPayload({ sessionId: this.session }));
        this.results = r.data;
        if (r.data.newly_earned_badges && r.data.newly_earned_badges.length > 0) {
          this.newBadges = r.data.newly_earned_badges;
        }
      } catch (e) {
        showError(t('learning', 'Failed to complete session'));
        this.results = { score_percentage: 0, correct_answers: 0, total_questions: this.questions.length };
      }
      this.showResults = true;
      this.$nextTick(() => {
        if (this.$refs.swipeScoreNumber && this.results) {
          countUp(this.$refs.swipeScoreNumber, this.results.score_percentage, 1000, '%');
        }
      });
    },

    restartSession() {
      this.session = null;
      this.questions = [];
      this.currentIndex = 0;
      this.showResults = false;
      this.results = null;
      this.startSession();
    }
  }
};
</script>

<style scoped>
.swipe-mode { max-width: 600px; margin: 0 auto; }

.swipe-start { text-align: center; padding: 60px 20px; }
.swipe-start h3 { font-size: 28px; font-weight: 700; margin-bottom: 12px; color: var(--color-main-text); }
.swipe-start p { font-size: 16px; color: var(--color-text-maxcontrast); margin-bottom: 24px; }
.start-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
.status-note { margin: 0 auto 16px; max-width: 420px; text-align: start; }
.pool-picker { margin: 0 auto 24px; max-width: 360px; text-align: start; }
.pool-picker-label { display: block; font-size: 13px; font-weight: 600; color: var(--color-text-maxcontrast); margin-bottom: 6px; }
.pool-select { width: 100%; padding: 10px 12px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-main-background); color: var(--color-main-text); font-size: 15px; cursor: pointer; }
.pool-select:focus { border-color: var(--color-primary-element); outline: none; }
.pool-name-chip { display: inline-block; background: var(--color-background-hover); border: 1px solid var(--color-border); border-radius: 20px; padding: 4px 14px; font-size: 14px; color: var(--color-text-maxcontrast); margin-bottom: 16px; }

.swipe-active { width: 100%; }

.progress-area { margin-bottom: 20px; }
.progress-text { text-align: center; font-size: 15px; font-weight: 600; margin-bottom: 8px; color: var(--color-primary-element); }

.card-stack {
  position: relative;
  width: 100%;
  min-height: 280px;
  display: flex;
  justify-content: center;
  align-items: center;
  margin-bottom: 20px;
  touch-action: pan-y;
  -webkit-user-select: none;
  user-select: none;
}

.swipe-card {
  width: 100%;
  min-height: 260px;
  background: var(--color-main-background);
  border: 2px solid var(--color-border);
  border-radius: 16px;
  padding: 60px 24px 28px;
  box-shadow: 0 4px 16px rgba(0,0,0,0.08);
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  text-align: center;
  position: relative;
  overflow: hidden;
  box-sizing: border-box;
  /* Smooth transitions for snap-back and fly-out */
  transition: transform 0.35s ease-out, opacity 0.35s ease-out;
  cursor: default;
}

.swipe-card.card-dragging {
  transition: none;
  cursor: grabbing;
}

.card-content { width: 100%; z-index: 1; }

.question-image { max-width: 100%; max-height: 180px; border-radius: 10px; border: 1px solid var(--color-border); margin-bottom: 12px; object-fit: contain; }
.question-text {
  font-size: 20px;
  font-weight: 500;
  line-height: 1.6;
  margin-bottom: 16px;
  color: var(--color-main-text);
}

.explanation-area {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--color-border);
  text-align: start;
  font-size: 14px;
  color: var(--color-main-text);
  line-height: 1.5;
}
.explanation-text { color: var(--color-text-maxcontrast); margin-top: 4px; }

/* Feedback overlay */
.feedback-overlay {
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  z-index: 5;
  pointer-events: none;
}
.feedback-correct { background: color-mix(in srgb, var(--color-success) 20%, transparent); }
.feedback-incorrect { background: color-mix(in srgb, var(--color-error) 20%, transparent); }
.feedback-icon { font-size: 56px; font-weight: 700; }
.feedback-correct .feedback-icon { color: var(--color-success); }
.feedback-incorrect .feedback-icon { color: var(--color-error); }
.feedback-label { font-size: 18px; font-weight: 600; margin-top: 4px; }
.feedback-correct .feedback-label { color: var(--color-success); }
.feedback-incorrect .feedback-label { color: var(--color-error); }

/* Answer buttons */
.answer-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

.answer-btn {
  padding: 16px 16px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius-large);
  background: var(--color-main-background);
  cursor: pointer;
  text-align: center;
  font-size: 14px;
  transition: all 0.15s;
  min-height: 58px;
  color: var(--color-main-text);
  line-height: 1.4;
  word-break: break-word;
}
.answer-btn:hover:not(:disabled) { border-color: var(--color-primary-element); background: var(--color-background-hover); }
.answer-btn:disabled { opacity: 0.7; cursor: wait; }
.answer-btn.answer-selected { border-color: var(--color-primary-element); background: var(--color-primary-element-light); font-weight: 600; }

.multi-hint { text-align: center; font-size: 14px; font-weight: 600; color: var(--color-primary-element); margin-bottom: 12px; padding: 8px; background: color-mix(in srgb, var(--color-primary-element) 8%, transparent); border-radius: 8px; }
.multi-submit-area { grid-column: 1 / -1; text-align: center; margin-top: 8px; }

/* No answers / skip */
.no-answers {
  grid-column: 1 / -1;
  text-align: center;
  padding: 20px;
  color: var(--color-text-maxcontrast);
}
.no-answers p { margin-bottom: 12px; }

/* Swipe hint after answering */
.swipe-hint-area {
  text-align: center;
  padding: 16px 0;
}
.next-btn { min-width: 240px; }
.swipe-hint {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 12px;
  animation: hintPulse 2s ease-in-out infinite;
}
.submission-status {
  text-align: center;
  margin-top: 12px;
  font-size: 13px;
  color: var(--color-text-maxcontrast);
}

@keyframes hintPulse {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
}

/* Results */
.swipe-results { text-align: center; padding: 60px 20px; }
.swipe-results h3 { font-size: 28px; font-weight: 700; margin-bottom: 32px; color: var(--color-main-text); }
.score-area { margin-bottom: 32px; }
.score-circle {
  width: 160px; height: 160px;
  border-radius: 50%;
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 20px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.15);
}
.score-number { font-size: 42px; font-weight: 700; }
.score-detail { font-size: 16px; color: var(--color-text-maxcontrast); }

.xp-earned { font-size: 22px; font-weight: 700; color: var(--color-primary-element); margin-bottom: 24px; text-align: center; animation: xpFadeIn 0.5s ease-out 0.5s both; }
@keyframes xpFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Card animations */
.card-enter { animation: cardIn 0.3s ease-out; }

@keyframes cardIn {
  from { transform: translateY(30px) scale(0.95); opacity: 0; }
  to { transform: translateY(0) scale(1); opacity: 1; }
}

/* Responsive */
@media (max-width: 480px) {
  .swipe-start { padding: 40px 12px; }
  .swipe-card { padding: 56px 16px 20px; min-height: 220px; }
  .question-text { font-size: 17px; }
  .answer-grid { grid-template-columns: 1fr; }
  .answer-btn { min-height: 66px; font-size: 15px; }
  .next-btn { width: 100%; }
  .score-circle { width: 130px; height: 130px; }
  .score-number { font-size: 36px; }
}

@media (prefers-reduced-motion: reduce) {
  .swipe-card { transition: none; }
  .hint-text { animation: none; }
  .xp-earned { animation: none; }
}
</style>
