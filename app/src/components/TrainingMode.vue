<template>
  <div class="training-mode">
    <div v-if="!session && !loadError" class="training-start">
      <h3>{{ t('learning', 'Start Training Session') }}</h3>
      <p v-if="totalQuestions > 0">{{ t('learning', 'Test your knowledge with {n} questions', { n: totalQuestions }) }}</p>
      <NcEmptyContent v-else :name="t('learning', 'No questions')" :description="t('learning', 'No questions available for training')" />
      <div v-if="allowWfMode" class="mode-toggle" role="group" :aria-label="t('learning', 'Lernmodus')">
        <button class="mode-toggle-btn" :class="{ active: !localWfMode }" @click="localWfMode = false">
          Multiple-Choice
        </button>
        <button class="mode-toggle-btn" :class="{ active: localWfMode }" @click="localWfMode = true">
          Wahr/Falsch
        </button>
      </div>
      <div class="start-actions">
        <NcButton v-if="totalQuestions > 0" type="primary" @click="startTraining" :disabled="starting">{{ starting ? t('learning', 'Starting...') : t('learning', 'Start Training') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>

    <div v-else-if="loadError" class="training-start">
      <NcNoteCard type="error">{{ loadError }}</NcNoteCard>
      <div class="start-actions">
        <NcButton type="primary" @click="startTraining">{{ t('learning', 'Retry') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>

    <div v-else-if="!showResults" class="training-active">
      <NcProgressBar :value="progress" />
      <div class="question-counter">
        {{ t('learning', 'Question {n} of {total}', { n: currentIndex + 1, total: questions.length }) }}
        <span v-if="currentQuestion && currentQuestion.pool_position" class="question-db-id">#{{ currentQuestion.pool_position }}</span>
      </div>

      <!-- Wahr/Falsch card block -->
      <div v-if="localWfMode && currentQuestion" class="card-stack">
        <div
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
            <img v-if="currentQuestion.image_path" :src="questionImageUrl(currentQuestion.id)" :alt="currentQuestion.image_alt || t('learning', 'Diagram for question')" class="question-image" />
            <p class="question-text">{{ currentQuestion.text }}</p>
            <div v-if="showFeedback" class="explanation-area">
              <p><strong>{{ displayCorrectAnswerTexts.length > 1 ? t('learning', 'Correct answers:') : t('learning', 'Correct:') }}</strong> {{ displayCorrectAnswerTexts.join(', ') }}</p>
              <p v-if="currentQuestion.explanation" class="explanation-text">{{ currentQuestion.explanation }}</p>
              <p v-if="currentQuestion.note_visible && currentQuestion.instructor_note" class="explanation-text">
                <strong>{{ t('learning', 'Note:') }}</strong> {{ currentQuestion.instructor_note }}
              </p>
            </div>
          </div>
        </div>

        <div v-if="!showFeedback" class="wf-buttons">
          <button class="wf-btn wf-true" @click="selectWfAnswer(true)" :disabled="submitting">
            &#x2713; {{ t('learning', 'Wahr') }}
          </button>
          <button class="wf-btn wf-false" @click="selectWfAnswer(false)" :disabled="submitting">
            &#x2717; {{ t('learning', 'Falsch') }}
          </button>
        </div>
        <div v-if="showFeedback" class="swipe-hint-area">
          <p class="swipe-hint">&#x2190; {{ t('learning', 'Wischen oder weiter tippen') }} &#x2192;</p>
          <NcButton type="primary" @click="advanceWfCard">
            {{ currentIndex < questions.length - 1 ? t('learning', 'Weiter \u2192') : t('learning', 'Ergebnisse') }}
          </NcButton>
        </div>
        <div v-if="submitting" class="submission-status">{{ t('learning', 'Submitting answer...') }}</div>
      </div>

      <!-- MC card block -->
      <div v-if="!localWfMode && currentQuestion" class="question-card">
        <QuestionLanguageSwitcher v-model="questionLanguage" />
        <img v-if="currentQuestion.image_path" :src="questionImageUrl(currentQuestion.id)" :alt="currentQuestion.image_alt || t('learning', 'Diagram for question')" class="question-image" />
        <div class="question-text">{{ currentQuestion.text }}</div>

        <div v-if="isMultiSelect" class="multi-hint">{{ t('learning', 'Select all correct answers') }}</div>

        <!-- PBQ block -->
        <div v-if="!answered && isPbq" class="pbq-answer-area">
          <PbqRenderer
            :question="currentQuestion"
            :disabled="submitting"
            @submit="submitPbqAnswer"
            @skip="skipQuestion"
          />
        </div>
        <div v-else-if="answered && isPbq" class="answer-feedback">
          <NcNoteCard :type="isCorrect ? 'success' : 'error'">
            {{ isCorrect ? t('learning', 'Correct!') : t('learning', 'Incorrect') }}
            — {{ pbqPoints }}/{{ pbqMaxPoints }} {{ t('learning', 'points') }}
          </NcNoteCard>
          <NcNoteCard v-if="currentQuestion.explanation" type="warning">
            <strong>{{ t('learning', 'Explanation:') }}</strong> {{ currentQuestion.explanation }}
          </NcNoteCard>
          <NcNoteCard v-if="currentQuestion.note_visible && currentQuestion.instructor_note" type="info">
            <strong>{{ t('learning', 'Note:') }}</strong> {{ currentQuestion.instructor_note }}
          </NcNoteCard>
          <NcButton type="primary" wide @click="nextQuestion" class="next-btn">
            {{ currentIndex < questions.length - 1 ? t('learning', 'Next Question \u2192') : t('learning', 'See Results') }}
          </NcButton>
        </div>

        <div v-else-if="!answered && isOpenQuestion" class="open-answer-area">
          <textarea v-model="openAnswer" :placeholder="t('learning', 'Type your answer...')" rows="3" class="nc-input open-textarea" :disabled="submitting"></textarea>
          <NcButton type="primary" @click="submitOpenAnswer" :disabled="submitting || !openAnswer.trim()">
            {{ t('learning', 'Submit Answer') }}
          </NcButton>
        </div>
        <div v-else-if="!answered" class="answers-grid">
          <template v-if="currentQuestion.answers && currentQuestion.answers.length > 0">
            <template v-if="isMultiSelect">
              <button
                v-for="answer in currentQuestion.answers"
                :key="answer.id"
                @click="toggleMultiAnswer(answer.id)"
                class="answer-btn"
                :class="{ 'answer-selected': selectedAnswerIds.includes(answer.id) }"
                :aria-pressed="selectedAnswerIds.includes(answer.id) ? 'true' : 'false'"
                :disabled="submitting"
              >{{ answer.text }}</button>
              <div class="multi-submit-area">
                <NcButton type="primary" @click="submitMultiAnswer" :disabled="submitting || selectedAnswerIds.length === 0">
                  {{ t('learning', 'Submit Answer') }}
                </NcButton>
              </div>
            </template>
            <template v-else>
              <button v-for="answer in currentQuestion.answers" :key="answer.id" @click="submitAnswer(answer.id)" class="answer-btn" :aria-pressed="selectedAnswerId === answer.id ? 'true' : 'false'" :disabled="submitting">{{ answer.text }}</button>
            </template>
          </template>
          <div v-else class="no-answers">
            <p>{{ t('learning', 'This question has no answers yet.') }}</p>
            <NcButton type="secondary" @click="skipQuestion">{{ t('learning', 'Skip') }}</NcButton>
          </div>
        </div>
        <div v-else-if="answered && isOpenQuestion" class="answer-feedback">
          <NcNoteCard :type="isCorrect ? 'success' : 'error'">{{ isCorrect ? t('learning', 'Correct!') : t('learning', 'Incorrect') }}</NcNoteCard>
          <div class="open-answer-review">
            <div class="open-review-row"><strong>{{ t('learning', 'Your answer') }}:</strong> {{ lastOpenAnswer }}</div>
            <div class="open-review-row"><strong>{{ t('learning', 'Model answer') }}:</strong> {{ displayCorrectAnswerTexts[0] || '' }}</div>
          </div>
          <NcNoteCard v-if="currentQuestion.explanation" type="warning"><strong>{{ t('learning', 'Explanation:') }}</strong> {{ currentQuestion.explanation }}</NcNoteCard>
          <NcNoteCard v-if="currentQuestion.note_visible && currentQuestion.instructor_note" type="info">
            <strong>{{ t('learning', 'Note:') }}</strong> {{ currentQuestion.instructor_note }}
          </NcNoteCard>
          <div v-if="aiAvailable" class="ai-explain-row">
            <NcButton v-if="!explainTaskId && !explainText" type="tertiary" :disabled="explainLoading" @click="requestExplain">
              {{ explainLoading ? t('learning', 'Thinking...') : t('learning', '💡 Explain this') }}
            </NcButton>
            <div v-if="explainText" class="ai-explain-box">{{ explainText }}</div>
          </div>
          <div v-if="!isCorrect" class="ai-explain-row">
            <NcButton type="tertiary" size="small" @click="explainViaVirtuProf">
              {{ t('learning', 'Explain via VirtuProf') }}
            </NcButton>
          </div>
          <NcButton type="primary" wide @click="nextQuestion" class="next-btn">{{ currentIndex < questions.length - 1 ? t('learning', 'Next Question \u2192') : t('learning', 'See Results') }}</NcButton>
        </div>
        <div v-else class="answer-feedback">
          <NcNoteCard :type="isCorrect ? 'success' : 'error'">{{ isCorrect ? t('learning', 'Correct!') : t('learning', 'Incorrect') }}</NcNoteCard>
          <div class="answer-buttons-review">
            <div v-for="answer in currentQuestion.answers" :key="'fb-' + answer.id"
                 class="answer-btn-review"
                 :class="{
                   'answer-user-selected': isMultiSelect ? lastSelectedAnswerIds.includes(answer.id) : lastSelectedAnswerId === answer.id,
                   'answer-correct': answer.is_correct,
                   'answer-wrong-selected': (isMultiSelect ? lastSelectedAnswerIds.includes(answer.id) : lastSelectedAnswerId === answer.id) && !answer.is_correct
                 }">
              {{ answer.text }}
            </div>
          </div>
          <div class="correct-answer">
            <strong>{{ displayCorrectAnswerTexts.length > 1 ? t('learning', 'Correct answers:') : t('learning', 'Correct answer:') }}</strong>
            {{ displayCorrectAnswerTexts.join(', ') }}
          </div>
          <NcNoteCard v-if="currentQuestion.explanation" type="warning"><strong>{{ t('learning', 'Explanation:') }}</strong> {{ currentQuestion.explanation }}</NcNoteCard>
          <NcNoteCard v-if="currentQuestion.note_visible && currentQuestion.instructor_note" type="info">
            <strong>{{ t('learning', 'Note:') }}</strong> {{ currentQuestion.instructor_note }}
          </NcNoteCard>
          <div v-if="aiAvailable" class="ai-explain-row">
            <NcButton v-if="!explainTaskId && !explainText" type="tertiary" :disabled="explainLoading" @click="requestExplain">
              {{ explainLoading ? t('learning', 'Thinking...') : t('learning', '💡 Explain this') }}
            </NcButton>
            <div v-if="explainText" class="ai-explain-box">{{ explainText }}</div>
          </div>
          <div v-if="!isCorrect" class="ai-explain-row">
            <NcButton type="tertiary" size="small" @click="explainViaVirtuProf">
              {{ t('learning', 'Explain via VirtuProf') }}
            </NcButton>
          </div>
          <NcButton type="primary" wide @click="nextQuestion" class="next-btn">{{ currentIndex < questions.length - 1 ? t('learning', 'Next Question \u2192') : t('learning', 'See Results') }}</NcButton>
        </div>
      </div>
    </div>

    <div v-else class="training-results">
      <h3>{{ t('learning', 'Training Complete!') }}</h3>
      <div v-if="streak.current_streak > 0" class="streak-banner"><span class="streak-flames"><span v-for="i in Math.min(streak.current_streak, 7)" :key="i" class="streak-flame" :style="{ animationDelay: (i * 0.1) + 's' }">&#x1F525;</span></span> {{ t('learning', 'Day {n}!', { n: streak.current_streak }) }}</div>
      <div v-if="results.is_personal_best" class="personal-best-banner">{{ t('learning', 'Personal Best!') }}</div>
      <div class="score-display">
        <div class="score-circle"><span class="score-number" ref="scoreNumber">{{ results.score_percentage }}%</span></div>
        <p>{{ t('learning', '{n} out of {total} correct', { n: results.correct_answers, total: results.total_questions }) }}</p>
        <div v-if="results.improvement && results.improvement !== 0" class="improvement-label" :class="results.improvement > 0 ? 'improvement-up' : 'improvement-down'">
          {{ results.improvement > 0 ? '+' : '' }}{{ results.improvement }}% {{ t('learning', 'vs average') }}
        </div>
      </div>
      <div v-if="results.xp_earned" class="xp-earned">+{{ results.xp_earned }} XP</div>
      <div class="result-actions">
        <NcButton type="primary" @click="restartTraining">{{ t('learning', 'Train Again') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back to Questions') }}</NcButton>
      </div>
      <!-- TRIG-04: Manual "Zusammenfassung erstellen" button -->
      <div class="summary-actions">
        <NcButton
          v-if="!summaryPath"
          type="secondary"
          :disabled="summaryGenerating"
          @click="generateSummaryNote"
        >
          {{ summaryGenerating ? t('learning', 'Generating summary...') : t('learning', 'Create Summary Note') }}
        </NcButton>
        <div v-if="summaryPath" class="summary-success">
          {{ t('learning', 'Summary saved to {path}', { path: summaryPath }) }}
        </div>
        <div v-if="summaryError" class="summary-error">{{ summaryError }}</div>
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
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError } from '@nextcloud/dialogs';
import { celebratePerfectSession, celebrateStreak, isStreakMilestone } from '../confetti.js';
import { countUp } from '../countUp.js';
import BadgeUnlock from './BadgeUnlock.vue';
import LevelUpOverlay from './LevelUpOverlay.vue';
import PbqRenderer from './PbqRenderer.vue';
import QuestionLanguageSwitcher from './QuestionLanguageSwitcher.vue';

export default {
  name: 'TrainingMode',
  components: { NcButton, NcNoteCard, NcProgressBar, NcEmptyContent, BadgeUnlock, LevelUpOverlay, PbqRenderer, QuestionLanguageSwitcher },
  props: {
    poolId: { type: Number, required: true },
    courseId: { type: Number, default: null },
    totalQuestions: { type: Number, required: true },
    contentLanguage: { type: String, default: '' },
    wfMode: { type: Boolean, default: false },
    allowWfMode: { type: Boolean, default: true },
  },
  data() {
    return {
      session: null, questions: [], currentIndex: 0, answered: false, submitting: false,
      isCorrect: false, correctAnswerText: '', correctAnswerTexts: [],
      pbqPoints: 0, pbqMaxPoints: 1,
      selectedAnswerId: null,
      selectedAnswerIds: [],
      lastSelectedAnswerId: null,
      lastSelectedAnswerIds: [],
      openAnswer: '',
      lastOpenAnswer: '',
      showResults: false, results: null, starting: false, loadError: null,
      streak: { current_streak: 0, longest_streak: 0, is_active_today: false },
      newBadges: [],
      levelBefore: 0,
      levelAfter: 0,
      // AI explain
      aiAvailable: false,
      explainLoading: false,
      explainTaskId: null,
      explainText: '',
      explainPollTimer: null,
      questionLanguage: this.contentLanguage || '',
      // TRIG-02 / TRIG-04: wrong answer tracking + summary generation
      wrongAnswersOnPool: 0,
      summaryGenerating: false,
      summaryPath: null,
      summaryError: null,
      // localWfMode: starts from prop, user can toggle on start screen
      localWfMode: this.allowWfMode ? this.wfMode : false,
      cardAnimClass: '',
      canDrag: false,
      isDragging: false,
      pointerDown: false,
      dragStartX: 0,
      dragStartY: 0,
      dragDeltaX: 0,
      showFeedback: false,
    };
  },
  mounted() {
    this.checkAiAvailable();
    this.emitVirtuProf('training-first-start');
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
    wfMode(newVal) {
      this.localWfMode = this.allowWfMode ? newVal : false;
      if (this.session) {
        this.cardAnimClass = '';
        this.canDrag = false;
        this.isDragging = false;
        this.dragDeltaX = 0;
        this.showFeedback = false;
        this.restartTraining();
      }
    },
    allowWfMode(newVal) {
      this.localWfMode = newVal ? this.wfMode : false;
      if (this.session) {
        this.cardAnimClass = '';
        this.canDrag = false;
        this.isDragging = false;
        this.dragDeltaX = 0;
        this.showFeedback = false;
        this.restartTraining();
      }
    },
    localWfMode(newVal) {
      if (this.session) {
        this.restartTraining();
      }
    },
    currentQuestion: {
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
            questionId: q.id || null,
          },
        });
      },
      immediate: true,
    },
  },
  computed: {
    currentQuestion() { return this.questions[this.currentIndex] || null; },
    progress() { return this.questions.length > 0 ? ((this.currentIndex + 1) / this.questions.length) * 100 : 0; },
    isMultiSelect() { return this.currentQuestion && this.currentQuestion.question_type === 'multi'; },
    isOpenQuestion() { return this.currentQuestion && this.currentQuestion.question_type === 'open'; },
    isPbq() { return this.currentQuestion && this.currentQuestion.question_type === 'pbq'; },
    effectiveContentLanguage() { return this.questionLanguage || ''; },
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
    explainViaVirtuProf() {
      if (!this.currentQuestion) return;
      this.$root.$emit('virtuprof:explain-question', {
        questionText: this.currentQuestion.question,
        correctAnswer: this.displayCorrectAnswerTexts.join(', '),
        poolId: this.poolId,
        courseId: this.courseId,
        questionId: this.currentQuestion.id,
      });
    },
    badgeDisplayName(badge) {
      return badge?.badge_name || badge?.name || badge?.title || t('learning', 'New badge');
    },
    emitBadgeTrigger(badges) {
      if (Array.isArray(badges) && badges.length > 0) {
        this.emitVirtuProf('badge-earned', { badgeName: this.badgeDisplayName(badges[0]) });
      }
    },
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
          this.questions = this.mergeFutureQuestions(response.data.questions);
          this.correctAnswerTexts = this.displayCorrectAnswerTexts;
        }
      } catch (error) {
        // Language refresh is best-effort only.
      }
    },
    questionImageUrl(id) {
      return generateUrl('/apps/learning/api/questions/' + id + '/image');
    },
    async startTraining() {
      this.starting = true; this.loadError = null;
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/training/start'), this.requestPayload({ poolId: this.poolId }));
        this.session = response.data.session_id; this.questions = response.data.questions;
        if (this.localWfMode) {
          this.$nextTick(() => { this.cardAnimClass = 'card-enter'; });
        }
      } catch (error) { this.loadError = error.response?.data?.error || t('learning', 'Failed to start training.'); }
      finally { this.starting = false; }
    },
    // --- WF Mode methods ---
    selectWfAnswer(isTrue) {
      if (!this.currentQuestion || !Array.isArray(this.currentQuestion.answers) || this.currentQuestion.answers.length === 0) {
        this.skipQuestion();
        return;
      }
      // Find the answer that corresponds to isTrue (is_correct === true → Wahr, is_correct === false → Falsch)
      const targetAnswer = this.currentQuestion.answers.find(a => a.is_correct === isTrue);
      if (!targetAnswer) {
        // Fallback: pick first answer
        this.submitAnswer(this.currentQuestion.answers[0].id).then(() => {
          this.showFeedback = true;
          this.canDrag = true;
          this.cardAnimClass = '';
        });
        return;
      }
      this.submitAnswer(targetAnswer.id).then(() => {
        this.showFeedback = true;
        this.canDrag = true;
        this.cardAnimClass = '';
      });
    },
    advanceWfCard() {
      this.canDrag = false;
      this.dragDeltaX = window.innerWidth + 100;
      setTimeout(this.goToWfNext, 380);
    },
    goToWfNext() {
      this.showFeedback = false;
      this.answered = false;
      this.selectedAnswerId = null;
      this.selectedAnswerIds = [];
      this.lastSelectedAnswerId = null;
      this.lastSelectedAnswerIds = [];
      this.correctAnswerTexts = [];
      this.cardAnimClass = '';
      this.canDrag = false;
      this.isDragging = false;
      this.pointerDown = false;
      this.dragDeltaX = 0;
      this.currentIndex++;
      if (this.currentIndex < this.questions.length) {
        this.$nextTick(() => {
          this.cardAnimClass = 'card-enter';
        });
      } else {
        this.completeSession();
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
      if (Math.abs(this.dragDeltaX) > threshold && this.canDrag) {
        var direction = this.dragDeltaX > 0 ? 1 : -1;
        this.dragDeltaX = direction * (window.innerWidth + 100);
        this.canDrag = false;
        setTimeout(this.goToWfNext, 350);
      } else {
        this.dragDeltaX = 0;
      }
    },
    // --- MC methods ---
    toggleMultiAnswer(answerId) {
      const idx = this.selectedAnswerIds.indexOf(answerId);
      if (idx >= 0) {
        this.selectedAnswerIds.splice(idx, 1);
      } else {
        this.selectedAnswerIds.push(answerId);
      }
    },
    async submitPbqAnswer(pbqAnswers) {
      this.submitting = true;
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/training/answer'), {
          sessionId: this.session,
          questionId: this.currentQuestion.id,
          pbqAnswers,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.isCorrect = response.data.is_correct;
        this.pbqPoints = response.data.pbq_points ?? 0;
        this.pbqMaxPoints = response.data.pbq_max_points ?? 1;
        this.correctAnswerTexts = [];
        this.answered = true;
      } catch (error) { showError(t('learning', 'Failed to submit answer')); }
      finally { this.submitting = false; }
    },
    async submitOpenAnswer() {
      this.submitting = true;
      this.lastOpenAnswer = this.openAnswer;
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/training/answer'), {
          sessionId: this.session,
          questionId: this.currentQuestion.id,
          answerText: this.openAnswer,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.isCorrect = response.data.is_correct;
        this.correctAnswerTexts = [response.data.correct_answer_text || ''];
        this.answered = true;
      } catch (error) { showError(t('learning', 'Failed to submit answer')); }
      finally { this.submitting = false; }
    },
    async submitMultiAnswer() {
      this.submitting = true;
      this.lastSelectedAnswerIds = this.selectedAnswerIds.slice();
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/training/answer'), {
          sessionId: this.session,
          questionId: this.currentQuestion.id,
          answerIds: this.selectedAnswerIds,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.isCorrect = response.data.is_correct;
        this.correctAnswerTexts = response.data.correct_answer_texts || [response.data.correct_answer_text || ''];
        this.answered = true;
      } catch (error) { showError(t('learning', 'Failed to submit answer')); }
      finally { this.submitting = false; }
    },
    async submitAnswer(answerId) {
      this.selectedAnswerId = answerId;
      this.lastSelectedAnswerId = answerId;
      this.submitting = true;
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/training/answer'), {
          sessionId: this.session,
          questionId: this.currentQuestion.id,
          answerId: answerId,
          ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
        });
        this.isCorrect = response.data.is_correct;
        this.correctAnswerTexts = response.data.correct_answer_texts || [response.data.correct_answer_text || ''];
        this.answered = true;
        // TRIG-02: Check if 5+ wrong answers on this pool → offer summary
        if (!this.isCorrect && response.data.wrong_answers_on_pool >= 5) {
          this.wrongAnswersOnPool = response.data.wrong_answers_on_pool;
          this.emitVirtuProf('suggest-summary', { poolId: this.poolId });
        }
      } catch (error) { showError(t('learning', 'Failed to submit answer')); }
      finally { this.submitting = false; }
    },
    async checkAiAvailable() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/ai/available'));
        this.aiAvailable = !!r.data.available;
      } catch (e) { this.aiAvailable = false; }
    },
    async requestExplain() {
      if (!this.currentQuestion) return;
      this.explainLoading = true;
      this.explainText = '';
      this.explainTaskId = null;
      clearInterval(this.explainPollTimer);
      try {
        const payload = { questionId: this.currentQuestion.id };
        if (this.isOpenQuestion) payload.answerText = this.lastOpenAnswer;
        else if (this.isMultiSelect) payload.selectedAnswerIds = this.lastSelectedAnswerIds;
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
            this.explainText = r.data.output || t('learning', 'No explanation available');
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
      if (this.currentIndex < this.questions.length - 1) {
        this.currentIndex++;
        this.answered = false;
        this.selectedAnswerIds = [];
        this.selectedAnswerId = null;
        this.lastSelectedAnswerId = null;
        this.lastSelectedAnswerIds = [];
        this.openAnswer = '';
        this.lastOpenAnswer = '';
        this.pbqPoints = 0; this.pbqMaxPoints = 1;
      } else {
        await this.completeSession();
      }
    },
    async completeSession() {
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/training/complete'), this.requestPayload({ sessionId: this.session }));
        this.results = response.data; this.showResults = true;
        if (this.results.score_percentage === 100) { celebratePerfectSession(); }
        const oldStreak = this.streak.current_streak;
        await this.fetchStreak();
        if (this.streak.current_streak > 0 && isStreakMilestone(this.streak.current_streak) && this.streak.current_streak > oldStreak) {
          celebrateStreak(this.streak.current_streak);
          this.emitVirtuProf('streak-milestone', { days: this.streak.current_streak });
        }
        // Animate score count-up
        this.$nextTick(() => {
          if (this.$refs.scoreNumber) {
            countUp(this.$refs.scoreNumber, this.results.score_percentage, 1200, '%');
          }
        });
        // Level-up detection
        if (response.data.level_before && response.data.level_after) {
          this.levelBefore = response.data.level_before;
          this.levelAfter = response.data.level_after;
        }
        // Show badge unlocks
        if (response.data.newly_earned_badges && response.data.newly_earned_badges.length > 0) {
          this.newBadges = response.data.newly_earned_badges;
          this.emitBadgeTrigger(response.data.newly_earned_badges);
        }
      } catch (error) { showError(t('learning', 'Failed to complete session')); }
    },
    async fetchStreak() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/streak'));
        this.streak = r.data;
      } catch (e) { /* streak is optional */ }
    },
    skipQuestion() {
      if (this.currentIndex < this.questions.length - 1) {
        this.currentIndex++;
        this.answered = false;
        this.selectedAnswerId = null;
        this.selectedAnswerIds = [];
      } else {
        this.completeSession();
      }
    },
    restartTraining() {
      this.session = null; this.questions = []; this.currentIndex = 0; this.answered = false;
      this.showResults = false; this.results = null; this.loadError = null;
      this.selectedAnswerIds = [];
      this.selectedAnswerId = null;
      this.lastSelectedAnswerId = null;
      this.lastSelectedAnswerIds = [];
      this.openAnswer = '';
      this.lastOpenAnswer = '';
      // Reset wfMode state
      this.cardAnimClass = '';
      this.canDrag = false;
      this.isDragging = false;
      this.pointerDown = false;
      this.dragDeltaX = 0;
      this.showFeedback = false;
      // Reset summary state
      this.summaryPath = null;
      this.summaryError = null;
      this.summaryGenerating = false;
      this.wrongAnswersOnPool = 0;
      this.startTraining();
    },
    // TRIG-04: Manual "Zusammenfassung erstellen" — calls POST /api/notes/generate
    async generateSummaryNote() {
      this.summaryGenerating = true;
      this.summaryError = null;
      this.summaryPath = null;
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/notes/generate'), {
          pool_id: this.poolId,
          course_id: this.courseId || null,
        });
        this.summaryPath = r.data.path || null;
      } catch (e) {
        const msg = e.response?.data?.error || t('learning', 'Summary generation failed');
        this.summaryError = msg;
      } finally {
        this.summaryGenerating = false;
      }
    },
  }
};
</script>

<style scoped>
.training-mode { max-width: 900px; margin: 0 auto; }
.training-start { text-align: center; padding: 80px 20px; }
.training-start h3 { font-size: 28px; margin-bottom: 12px; font-weight: 700; color: var(--color-main-text); }
.training-start p { font-size: 16px; color: var(--color-text-maxcontrast); margin-bottom: 28px; }
.start-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
.question-counter { text-align: center; font-size: 14px; color: var(--color-text-maxcontrast); margin: 12px 0 28px; font-weight: 500; display: flex; justify-content: center; align-items: center; gap: 8px; }
.question-db-id { font-size: 11px; font-weight: 400; color: var(--color-text-maxcontrast); opacity: 0.6; font-family: monospace; background: var(--color-background-hover); padding: 1px 5px; border-radius: 4px; }
.question-card { position: relative; background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: 16px; padding: 60px 32px 32px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
.question-image { max-width: 100%; max-height: 240px; border-radius: 12px; border: 1px solid var(--color-border); margin-bottom: 16px; object-fit: contain; display: block; }
.question-text { font-size: 20px; font-weight: 500; margin-bottom: 28px; line-height: 1.6; color: var(--color-main-text); }
.multi-hint { text-align: center; font-size: 14px; font-weight: 600; color: var(--color-primary-element); margin-bottom: 16px; padding: 8px; background: color-mix(in srgb, var(--color-primary-element) 8%, transparent); border-radius: 8px; }
.answers-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
.answer-btn { padding: 16px 20px; border: 2px solid var(--color-border); border-radius: 12px; background: var(--color-main-background); cursor: pointer; text-align: start; font-size: 15px; transition: all 0.15s; min-height: 56px; line-height: 1.5; color: var(--color-main-text); }
.answer-btn:hover:not(:disabled) { border-color: var(--color-primary-element); background: var(--color-primary-element-light); transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.answer-btn:disabled { opacity: 0.7; cursor: wait; }
.answer-btn.answer-selected { border-color: var(--color-primary-element); background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background)); color: var(--color-primary-element); font-weight: 600; }
.multi-submit-area { grid-column: 1 / -1; text-align: center; margin-top: 8px; }
.no-answers { grid-column: 1 / -1; text-align: center; padding: 20px; color: var(--color-text-maxcontrast); }
.open-answer-area { display: flex; flex-direction: column; gap: 12px; align-items: center; }
.open-textarea { width: 100%; padding: 14px; font-size: 15px; border: 2px solid var(--color-border); border-radius: 12px; resize: vertical; min-height: 80px; box-sizing: border-box; }
.open-textarea:focus { border-color: var(--color-primary-element); outline: none; }
.open-answer-review { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
.open-review-row { padding: 10px 14px; border-radius: 8px; background: var(--color-background-hover); font-size: 14px; line-height: 1.5; }
.no-answers p { margin-bottom: 12px; }
.answer-feedback { margin-top: 28px; }
.correct-answer { padding: 14px 18px; background: var(--color-background-hover); border-radius: 10px; margin-bottom: 12px; font-size: 14px; line-height: 1.5; color: var(--color-main-text); }
.next-btn { margin-top: 20px; }
.ai-explain-row { margin: 10px 0; }
.ai-explain-box { background: color-mix(in srgb, var(--color-primary-element) 8%, transparent); border-inline-start: 3px solid var(--color-primary-element); border-radius: var(--border-radius); padding: 10px 14px; font-size: 0.92em; color: var(--color-main-text); line-height: 1.5; }
.training-results { text-align: center; padding: 60px 20px; }
.training-results h3 { font-size: 32px; margin-bottom: 36px; font-weight: 700; color: var(--color-main-text); }
.score-display { margin-bottom: 36px; }
.score-circle { width: 180px; height: 180px; border-radius: 50%; background: var(--color-primary-element); color: var(--color-primary-element-text); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; box-shadow: 0 8px 24px rgba(0,100,180,0.3); }
.score-number { font-size: 48px; font-weight: 700; }
.score-display p { font-size: 16px; color: var(--color-text-maxcontrast); }
.result-actions { display: flex; justify-content: center; gap: 14px; flex-wrap: wrap; }
.summary-actions { margin-top: 20px; display: flex; flex-direction: column; align-items: center; gap: 8px; }
.summary-success { font-size: 14px; color: var(--color-success); padding: 8px 14px; background: color-mix(in srgb, var(--color-success) 10%, transparent); border-radius: 8px; }
.summary-error { font-size: 13px; color: var(--color-error); }
@media (max-width: 768px) {
  .training-start { padding: 40px 16px; }
  .question-card { padding: 56px 20px 20px; border-radius: 12px; }
  .question-text { font-size: 17px; }
  .answers-grid { grid-template-columns: 1fr; }
  .score-circle { width: 140px; height: 140px; }
  .score-number { font-size: 36px; }
}
.answer-buttons-review { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 8px; margin-bottom: 12px; }
.answer-btn-review { padding: 12px 16px; border: 2px solid var(--color-border); border-radius: 12px; font-size: 14px; color: var(--color-main-text); background: var(--color-main-background); text-align: start; }
.answer-btn-review.answer-correct { border-color: var(--color-success); background: color-mix(in srgb, var(--color-success) 10%, var(--color-main-background)); }
.answer-btn-review.answer-user-selected { border-color: var(--color-primary-element); }
.answer-btn-review.answer-wrong-selected { border-color: var(--color-error); background: color-mix(in srgb, var(--color-error) 10%, var(--color-main-background)); }
.streak-banner { font-size: 24px; font-weight: 700; color: var(--color-warning); margin-bottom: 16px; text-align: center; text-shadow: 0 0 16px color-mix(in srgb, var(--color-warning) 25%, transparent); }
.streak-flames { display: inline-flex; gap: 1px; }
.streak-flame { display: inline-block; animation: flame-dance 0.8s ease-in-out infinite alternate; }
@keyframes flame-dance { 0% { transform: translateY(0) scale(1); } 100% { transform: translateY(-2px) scale(1.15); } }
@media (prefers-reduced-motion: reduce) { .streak-flame { animation: none; } }
.personal-best-banner { font-size: 20px; font-weight: 700; color: var(--color-success); margin-bottom: 12px; text-align: center; animation: pbPulse 1s ease-in-out 2; }
@keyframes pbPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
.improvement-label { font-size: 14px; font-weight: 600; margin-top: 8px; }
.improvement-up { color: var(--color-success); }
.improvement-down { color: var(--color-text-maxcontrast); }
.xp-earned { font-size: 22px; font-weight: 700; color: var(--color-primary-element); margin-bottom: 24px; text-align: center; animation: xpFadeIn 0.5s ease-out 0.5s both; }
@keyframes xpFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@media (prefers-reduced-motion: reduce) {
  .answer-btn,
  .streak-flame,
  .personal-best-banner,
  .xp-earned {
    animation: none;
    transition: none;
  }
  .personal-best-banner { animation: none; }
  .xp-earned { animation: none; }
}

/* Mode Toggle */
.mode-toggle { display: flex; justify-content: center; gap: 0; margin-bottom: 24px; border: 2px solid var(--color-border); border-radius: 10px; overflow: hidden; }
.mode-toggle-btn { flex: 1; padding: 10px 20px; border: none; background: var(--color-main-background); cursor: pointer; font-size: 14px; font-weight: 500; color: var(--color-text-maxcontrast); transition: all 0.15s; }
.mode-toggle-btn.active { background: var(--color-primary-element); color: var(--color-primary-element-text); font-weight: 600; }

/* WF Buttons */
.wf-buttons { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 16px; }
.wf-btn { padding: 18px; border: 2px solid var(--color-border); border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.15s; background: var(--color-main-background); color: var(--color-main-text); }
.wf-true:hover:not(:disabled) { border-color: var(--color-success); background: color-mix(in srgb, var(--color-success) 10%, var(--color-main-background)); }
.wf-false:hover:not(:disabled) { border-color: var(--color-error); background: color-mix(in srgb, var(--color-error) 10%, var(--color-main-background)); }
.wf-btn:disabled { opacity: 0.6; cursor: wait; }

/* Card Stack / Swipe Card */
.card-stack {
  position: relative;
  width: 100%;
  min-height: 280px;
  display: flex;
  flex-direction: column;
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
  transition: transform 0.35s ease-out, opacity 0.35s ease-out;
  cursor: default;
}

.swipe-card.card-dragging {
  transition: none;
  cursor: grabbing;
}

.card-content { width: 100%; z-index: 1; }

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

/* Card enter animation */
.card-enter { animation: cardIn 0.3s ease-out; }
@keyframes cardIn {
  from { transform: translateY(30px) scale(0.95); opacity: 0; }
  to { transform: translateY(0) scale(1); opacity: 1; }
}

/* Swipe hint */
.swipe-hint-area { text-align: center; padding: 16px 0; }
.swipe-hint {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
  margin-bottom: 12px;
  animation: hintPulse 2s ease-in-out infinite;
}
@keyframes hintPulse {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
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

.submission-status { text-align: center; margin-top: 12px; font-size: 13px; color: var(--color-text-maxcontrast); }

@media (max-width: 480px) {
  .swipe-card { padding: 56px 16px 20px; min-height: 220px; }
  .wf-buttons { gap: 8px; }
  .wf-btn { padding: 14px; font-size: 15px; }
}

@media (prefers-reduced-motion: reduce) {
  .swipe-card { transition: none; }
  .swipe-hint { animation: none; }
}
</style>
