<template>
  <div class="exam-mode">
    <NcLoadingIcon v-if="isLoading" class="loading-overlay" />

    <!-- Setup Screen -->
    <div v-if="screen === 'setup'" class="setup-screen">
      <h3 class="exam-title">{{ t('learning', 'Exam Mode') }}</h3>
      <p class="exam-description">{{ t('learning', 'Timed exam — answer all questions before time runs out. No feedback until the end.') }}</p>

      <div class="setup-section">
        <div class="setup-label">{{ t('learning', 'Time Limit') }}</div>
        <div class="button-group">
          <NcButton
            v-for="time in timeOptions"
            :key="time.value"
            :type="selectedTimeLimit === time.value ? 'primary' : 'tertiary'"
            @click="selectedTimeLimit = time.value"
          >
            {{ time.label }}
          </NcButton>
        </div>
      </div>

      <div class="setup-section">
        <div class="setup-label">{{ t('learning', 'Number of Questions') }}</div>
        <div class="button-group">
          <NcButton
            v-for="count in questionCountOptions"
            :key="count.value"
            :type="selectedQuestionCount === count.value ? 'primary' : 'tertiary'"
            @click="selectedQuestionCount = count.value"
          >
            {{ count.label }}
          </NcButton>
        </div>
      </div>

      <div class="start-actions">
        <NcButton type="primary" wide :disabled="isLoading" @click="startExam">{{ t('learning', 'Start Exam') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>

    <!-- Exam Active Screen -->
    <div v-else-if="screen === 'exam'" class="exam-screen">
      <div :class="['timer-display', timerColorClass]">
        {{ formattedTimeLeft }}
      </div>
      <div class="exam-meta-line">
        <span v-if="attemptNo">{{ t('learning', 'Attempt #{n}', { n: attemptNo }) }}</span>
      </div>
      <p v-if="resumedFromServer" class="resume-note">{{ t('learning', 'Active exam resumed from server state.') }}</p>
      <p v-if="lockDenied" class="lock-note">{{ t('learning', 'This exam is active in another tab. This tab is read-only.') }}</p>
      <NcNoteCard type="info" class="exam-mode-banner">
        {{ t('learning', 'Exam mode active — no feedback until the end') }}
      </NcNoteCard>
      <p class="hotkey-note">{{ t('learning', 'Hotkeys: 1-8 select answer, Enter confirms/next') }}</p>

      <NcProgressBar :value="progressPercentage" />
      <div class="progress-label">{{ answeredCount }} / {{ questions.length }} {{ t('learning', 'answered') }}</div>

      <div v-if="currentQuestion" ref="questionCard" class="question-card">
        <QuestionLanguageSwitcher v-model="questionLanguage" />
        <!-- Snake timer border -->
        <svg v-if="snakeReady"
             :class="['snake-svg', snakeColorClass]"
             :width="snakeWidth" :height="snakeHeight">
          <rect
            class="snake-line"
            x="1.5" y="1.5"
            :width="snakeWidth - 3" :height="snakeHeight - 3"
            rx="15" ry="15"
            fill="none"
            stroke-width="3"
            stroke-linecap="round"
            pathLength="1000"
            stroke-dasharray="1000"
            :stroke-dashoffset="snakeDashoffset"
          />
        </svg>
        <div class="question-number">{{ t('learning', 'Question {n} of {total}', { n: currentQuestionIndex + 1, total: questions.length }) }}</div>
        <img v-if="currentQuestion.image_path" :src="questionImageUrl(currentQuestion.id)" alt="" class="question-image" />
        <div class="question-text">{{ currentQuestion.text }}</div>

        <div v-if="isCurrentMulti && currentQuestion.question_type !== 'pbq'" class="multi-hint">{{ t('learning', 'Select all correct answers') }}</div>

        <PbqRenderer
          v-if="currentQuestion && currentQuestion.question_type === 'pbq'"
          :question="currentQuestion"
          :initial-value="pbqAnswers[currentQuestion.id] || null"
          :disabled="screen !== 'exam' || lockDenied"
          @change="(ans) => $set(pbqAnswers, currentQuestion.id, ans)"
          @submit="(ans) => { $set(pbqAnswers, currentQuestion.id, ans); $set(userAnswers, currentQuestion.id, 'pbq_submitted'); advanceToNext() }"
          @skip="advanceToNext()"
        />
        <div v-else-if="isCurrentOpen" class="open-answer-area">
          <textarea
            :value="openAnswerTexts[currentQuestion.id] || ''"
            @input="$set(openAnswerTexts, currentQuestion.id, $event.target.value)"
            :placeholder="t('learning', 'Type your answer...')"
            rows="3"
            class="nc-input open-textarea"
          ></textarea>
          <NcButton type="primary" @click="submitOpenExamAnswer" :disabled="lockDenied || !openAnswerTexts[currentQuestion.id] || !openAnswerTexts[currentQuestion.id].trim()">
            {{ t('learning', 'Submit Answer') }}
          </NcButton>
        </div>
        <div v-else class="answer-options">
          <button
            v-for="answer in currentQuestion.answers"
            :key="answer.id"
            class="answer-btn"
            :class="{ 'answer-selected': isAnswerSelected(answer.id) }"
            :disabled="lockDenied"
            @click="answerQuestion(answer.id)"
          >
            {{ answer.text }}
          </button>
        </div>

        <NcButton v-if="isCurrentMulti && multiSelections[currentQuestion.id] && multiSelections[currentQuestion.id].length > 0"
          type="primary" wide @click="confirmMultiAnswer" class="confirm-btn" :disabled="lockDenied">
          {{ t('learning', 'Confirm Selection') }}
        </NcButton>
        <NcButton v-if="currentQuestion && currentQuestion.question_type !== 'pbq'" type="secondary" wide @click="skipQuestion" class="skip-btn" :disabled="lockDenied">{{ t('learning', 'Skip') }}</NcButton>
      </div>

      <!-- Question navigation bar -->
      <div class="nav-bar">
        <div class="nav-bar-inner">
          <div
            v-for="(q, index) in questions"
            :key="q.id"
            :class="['nav-dot', {
              'nav-dot-current': index === currentQuestionIndex,
              'nav-dot-answered': userAnswers[q.id] !== undefined && userAnswers[q.id] !== null
            }]"
            tabindex="0"
            role="button"
            :aria-label="t('learning', 'Question {n}', { n: index + 1 })"
            @click="jumpToQuestion(index)"
            @keydown.enter="jumpToQuestion(index)"
            @keydown.space.prevent="jumpToQuestion(index)"
          >
            {{ index + 1 }}
          </div>
        </div>
        <NcButton type="error" class="end-exam-btn" @click="confirmEndExam">
          {{ t('learning', 'End Exam') }}
        </NcButton>
        <NcButton type="tertiary" class="abort-exam-btn" @click="confirmAbortExam">
          {{ t('learning', 'Abort') }}
        </NcButton>
      </div>
    </div>

    <!-- Results Screen -->
    <div v-else-if="screen === 'results'" class="results-screen">
      <h3 class="exam-title">{{ t('learning', 'Exam Results') }}</h3>
      <p v-if="resultsData && resultsData.timed_out" class="timeout-note">
        {{ t('learning', 'Time limit reached. The exam was auto-submitted.') }}
      </p>

      <div v-if="resultsData" class="results-summary">
        <div class="score-circle" :class="scoreColorClass">
          <span class="score-number" ref="examScoreNumber">{{ resultsData.score_percentage }}%</span>
          <span class="score-label">{{ t('learning', 'Score') }}</span>
        </div>

        <div class="stats-grid">
          <div class="stat-item">
            <div class="stat-value">{{ resultsData.correct_answers }}</div>
            <div class="stat-label">{{ t('learning', 'Correct') }}</div>
          </div>
          <div class="stat-item">
            <div class="stat-value">{{ resultsData.total_questions - resultsData.correct_answers }}</div>
            <div class="stat-label">{{ t('learning', 'Wrong') }}</div>
          </div>
          <div class="stat-item">
            <div class="stat-value">{{ timeTaken }}</div>
            <div class="stat-label">{{ t('learning', 'Time') }}</div>
          </div>
          <div class="stat-item">
            <div class="stat-value">{{ questionsPerMinute }}</div>
            <div class="stat-label">{{ t('learning', 'Q/min') }}</div>
          </div>
        </div>
      </div>

      <h4 class="review-title">{{ t('learning', 'Detailed Review') }}</h4>
      <div class="review-list" v-if="sortedDetailedResults.length > 0">
        <div
          v-for="(res, index) in sortedDetailedResults"
          :key="index"
          :class="['review-item', res.isCorrect ? 'review-correct' : 'review-wrong']"
        >
          <div class="review-question">{{ index + 1 }}. {{ res.questionText }}</div>
          <template v-if="res.isOpen">
            <div class="open-answer-review">
              <div class="open-review-row"><strong>{{ t('learning', 'Your answer') }}:</strong> {{ res.userAnswerText || t('learning', 'Skipped') }}</div>
              <div class="open-review-row"><strong>{{ t('learning', 'Model answer') }}:</strong> {{ res.correctAnswerText }}</div>
            </div>
          </template>
          <template v-else-if="res.isMulti && res.answerDetails">
            <div class="review-answers-detail">
              <div v-for="(ad, ai) in res.answerDetails" :key="ai"
                   :class="['review-answer-item', {
                     'ra-correct-selected': ad.isCorrect && ad.wasSelected,
                     'ra-wrong-selected': !ad.isCorrect && ad.wasSelected,
                     'ra-correct-missed': ad.isCorrect && !ad.wasSelected,
                     'ra-neutral': !ad.isCorrect && !ad.wasSelected
                   }]">
                <span class="ra-icon">{{ ad.isCorrect && ad.wasSelected ? '\u2713' : (!ad.isCorrect && ad.wasSelected ? '\u2717' : (ad.isCorrect && !ad.wasSelected ? '\u25CB' : '\u2014')) }}</span>
                {{ ad.text }}
              </div>
            </div>
          </template>
          <template v-else>
            <div class="review-answer">
              <span class="review-label">{{ t('learning', 'Your answer:') }}</span>
              <span :class="res.isCorrect ? 'text-success' : 'text-error'">
                {{ res.userAnswerText || t('learning', 'Skipped') }}
              </span>
            </div>
            <div v-if="!res.isCorrect && res.correctAnswerText" class="review-answer">
              <span class="review-label">{{ t('learning', 'Correct:') }}</span>
              <span class="text-success">{{ res.correctAnswerText }}</span>
            </div>
          </template>
          <NcNoteCard v-if="res.noteVisible && res.instructorNote" type="info">
            <strong>{{ t('learning', 'Note:') }}</strong> {{ res.instructorNote }}
          </NcNoteCard>
        </div>
      </div>

      <div v-if="resultsData && resultsData.xp_earned" class="xp-earned">+{{ resultsData.xp_earned }} XP</div>

      <div class="start-actions">
        <NcButton type="primary" wide @click="retakeExam">{{ t('learning', 'Retake Exam') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
      <BadgeUnlock :badges="newBadges" />
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js';
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError } from '@nextcloud/dialogs';
import { celebratePerfectSession } from '../confetti.js';
import { countUp } from '../countUp.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import BadgeUnlock from './BadgeUnlock.vue';
import PbqRenderer from './PbqRenderer.vue';
import QuestionLanguageSwitcher from './QuestionLanguageSwitcher.vue';

export default {
  name: 'ExamMode',
  components: { NcButton, NcProgressBar, NcLoadingIcon, NcNoteCard, BadgeUnlock, PbqRenderer, QuestionLanguageSwitcher },
  props: {
    poolId: { type: Number, required: true },
    courseId: { type: Number, default: null },
    totalQuestions: { type: Number, required: true },
    contentLanguage: { type: String, default: '' }
  },
  data() {
    return {
      screen: 'setup',
      isLoading: false,

      timeOptions: [
        { label: t('learning', '5 min'), value: 5 * 60 },
        { label: t('learning', '10 min'), value: 10 * 60 },
        { label: t('learning', '15 min'), value: 15 * 60 },
        { label: t('learning', '20 min'), value: 20 * 60 },
        { label: t('learning', '30 min'), value: 30 * 60 },
      ],
      questionCountOptions: [
        { label: '10', value: 10 },
        { label: '20', value: 20 },
        { label: '50', value: 50 },
        { label: t('learning', 'All'), value: 0 },
      ],
      selectedTimeLimit: 10 * 60,
      selectedQuestionCount: 20,

      session: null,
      questions: [],
      currentQuestionIndex: 0,
      userAnswers: {},
      timerInterval: null,
      timeLeftSeconds: null,
      examDurationSeconds: null,
      examStartTime: null,
      examEndTime: null,
      examDeadlineAt: null,
      attemptNo: null,
      resumedFromServer: false,
      lockDenied: false,
      tabLockId: null,
      lockHeartbeat: null,
      statusPollTimer: null,
      snakeWidth: 0,
      snakeHeight: 0,
      resizeObserver: null,

      resultsData: null,
      detailedResults: [],
      multiSelections: {},
      openAnswerTexts: {},
      pbqAnswers: {},
      newBadges: [],
      questionLanguage: this.contentLanguage || '',
    };
  },
  computed: {
    formattedTimeLeft() {
      if (this.timeLeftSeconds === null || this.timeLeftSeconds < 0) return '00:00';
      const m = Math.floor(this.timeLeftSeconds / 60);
      const s = this.timeLeftSeconds % 60;
      return m.toString().padStart(2, '0') + ':' + s.toString().padStart(2, '0');
    },
    timerColorClass() {
      if (!this.examDurationSeconds || this.timeLeftSeconds === null) return '';
      const pct = (this.timeLeftSeconds / this.examDurationSeconds) * 100;
      if (pct > 50) return 'timer-green';
      if (pct > 25) return 'timer-yellow';
      return 'timer-red';
    },
    snakeReady() {
      return this.screen === 'exam' && this.snakeWidth > 0 && this.snakeHeight > 0;
    },
    snakeDashoffset() {
      if (!this.examDurationSeconds || this.timeLeftSeconds === null) return 0;
      const progress = this.timeLeftSeconds / this.examDurationSeconds;
      return 1000 * (1 - progress);
    },
    snakeColorClass() {
      if (!this.examDurationSeconds || this.timeLeftSeconds === null) return '';
      const pct = (this.timeLeftSeconds / this.examDurationSeconds) * 100;
      if (pct > 50) return '';
      if (pct > 25) return 'snake-warning';
      return 'snake-danger';
    },
    currentQuestion() {
      return this.questions[this.currentQuestionIndex] || null;
    },
    answeredCount() {
      return Object.values(this.userAnswers).filter(v => v !== null && v !== undefined && v !== false).length;
    },
    progressPercentage() {
      if (!this.questions.length) return 0;
      return Math.round((this.answeredCount / this.questions.length) * 100);
    },
    timeTaken() {
      if (!this.examStartTime || !this.examEndTime) return '0:00';
      const secs = this.examEndTime - this.examStartTime;
      const m = Math.floor(secs / 60);
      const s = secs % 60;
      return m + ':' + s.toString().padStart(2, '0');
    },
    questionsPerMinute() {
      if (!this.examStartTime || !this.examEndTime) return 0;
      const mins = (this.examEndTime - this.examStartTime) / 60;
      if (mins <= 0) return this.answeredCount;
      return Math.round(this.answeredCount / mins * 10) / 10;
    },
    scoreColorClass() {
      if (!this.resultsData) return '';
      const s = this.resultsData.score_percentage;
      if (s >= 80) return 'score-green';
      if (s >= 50) return 'score-yellow';
      return 'score-red';
    },
    isCurrentMulti() {
      return this.currentQuestion && this.currentQuestion.question_type === 'multi';
    },
    isCurrentOpen() {
      return this.currentQuestion && this.currentQuestion.question_type === 'open';
    },
    sortedDetailedResults() {
      return [...this.detailedResults].sort((a, b) => {
        if (a.isCorrect === b.isCorrect) return 0;
        return a.isCorrect ? 1 : -1;
      });
    },
    effectiveContentLanguage() {
      return this.questionLanguage || '';
    }
  },
  watch: {
    contentLanguage(newLang, oldLang) {
      if (!this.questionLanguage || this.questionLanguage === oldLang) {
        this.questionLanguage = newLang || '';
      }
    },
    questionLanguage() {
      this.refreshExamQuestionsForLanguage();
    },
  },
  methods: {
    emitVirtuProf(triggerId, context = {}) {
      this.$root.$emit('virtuprof:trigger', triggerId, context);
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
    async refreshExamQuestionsForLanguage() {
      if (!this.session || this.screen !== 'exam') {
        return;
      }
      try {
        const r = await axios.get(
          generateUrl('/apps/learning/api/training/session/{id}', { id: this.session }),
          { params: this.buildStatusParams(true) }
        );
        if (Array.isArray(r.data?.questions)) {
          const orderedQuestions = [
            ...r.data.questions.filter(q => q.question_type === 'pbq'),
            ...r.data.questions.filter(q => q.question_type !== 'pbq'),
          ];
          this.questions = this.mergeFutureQuestions(orderedQuestions);
        }
      } catch (e) {
        // Language refresh is best-effort only.
      }
    },
    isEditableTarget(event) {
      const target = event && event.target ? event.target : null;
      if (!target || !target.tagName) return false;
      const tag = String(target.tagName).toLowerCase();
      return tag === 'input' || tag === 'textarea' || target.isContentEditable;
    },
    handleExamHotkeys(event) {
      if (this.screen !== 'exam' || !this.currentQuestion || this.isLoading) return;
      if (this.isEditableTarget(event)) return;

      const key = String(event.key || '');
      if (key === 'Enter') {
        event.preventDefault();
        if (this.lockDenied) return;
        if (this.isCurrentOpen) {
          this.submitOpenExamAnswer();
          return;
        }
        if (this.isCurrentMulti) {
          const qId = this.currentQuestion.id;
          const selected = this.multiSelections[qId] || [];
          if (selected.length > 0) {
            this.confirmMultiAnswer();
          } else {
            this.skipQuestion();
          }
          return;
        }

        const currentValue = this.userAnswers[this.currentQuestion.id];
        if (currentValue === undefined) {
          this.skipQuestion();
        } else {
          this.advanceToNext();
        }
        return;
      }

      if (!/^[1-8]$/.test(key) || this.isCurrentOpen || this.lockDenied) return;
      const answerIndex = Number(key) - 1;
      const answers = Array.isArray(this.currentQuestion.answers) ? this.currentQuestion.answers : [];
      if (answerIndex < 0 || answerIndex >= answers.length) return;
      event.preventDefault();
      this.answerQuestion(answers[answerIndex].id);
    },
    questionImageUrl(id) {
      return generateUrl('/apps/learning/api/questions/' + id + '/image');
    },
    updateSnakeDimensions() {
      if (this.$refs.questionCard) {
        this.snakeWidth = this.$refs.questionCard.offsetWidth;
        this.snakeHeight = this.$refs.questionCard.offsetHeight;
      }
    },
    cleanupSnake() {
      if (this.resizeObserver) {
        this.resizeObserver.disconnect();
        this.resizeObserver = null;
      }
      this.snakeWidth = 0;
      this.snakeHeight = 0;
    },
    async startExam() {
      this.isLoading = true;
      try {
        const params = { poolId: this.poolId, mode: 'exam' };
        if (this.selectedQuestionCount > 0) {
          params.limit = this.selectedQuestionCount;
        }
        params.timeLimitSeconds = this.selectedTimeLimit;
        const r = await axios.post(generateUrl('/apps/learning/api/training/start'), this.requestPayload(params));
        this.session = r.data.session_id;
        const questions = r.data.questions;

        if (!questions.length) {
          showError(t('learning', 'No questions available'));
          this.isLoading = false;
          return;
        }

        this.questions = [
          ...questions.filter(q => q.question_type === 'pbq'),
          ...questions.filter(q => q.question_type !== 'pbq'),
        ];
        this.currentQuestionIndex = 0;
        this.userAnswers = {};
        this.openAnswerTexts = {};
        this.multiSelections = {};
        this.pbqAnswers = {};
        this.detailedResults = [];
        this.resultsData = null;
        this.resumedFromServer = !!r.data.resumed;
        this.examDurationSeconds = Number(r.data.time_limit_seconds || this.selectedTimeLimit);
        this.examDeadlineAt = Number(r.data.exam_deadline_at || 0) || null;
        const serverNow = Number(r.data.server_time || Math.floor(Date.now() / 1000));
        this.timeLeftSeconds = this.examDeadlineAt
          ? Math.max(0, this.examDeadlineAt - serverNow)
          : this.examDurationSeconds;
        this.examStartTime = Number(r.data.started_at || serverNow);
        this.examEndTime = null;
        this.attemptNo = Number(r.data.attempt_no || 0) || null;
        if (r.data.answered && typeof r.data.answered === 'object') {
          this.restoreAnsweredState(r.data.answered);
        }
        this.currentQuestionIndex = this.findFirstUnansweredIndex();
        this.startExamLock();

        this.startTimer();
        this.startStatusPolling();
        this.screen = 'exam';
        this.$nextTick(() => {
          this.updateSnakeDimensions();
          if (typeof ResizeObserver !== 'undefined') {
            this.resizeObserver = new ResizeObserver(() => this.updateSnakeDimensions());
            if (this.$refs.questionCard) {
              this.resizeObserver.observe(this.$refs.questionCard);
            }
          }
        });
      } catch (e) {
        showError(e.response?.data?.error || t('learning', 'Failed to start exam'));
      } finally {
        this.isLoading = false;
      }
    },
    restoreAnsweredState(answeredMap) {
      const normalized = {};
      const restoredOpenTexts = {};
      const restoredMulti = {};

      for (const [qIdRaw, value] of Object.entries(answeredMap)) {
        const qId = Number(qIdRaw);
        if (value === null || value === undefined) {
          normalized[qId] = null;
          continue;
        }
        if (Array.isArray(value)) {
          const ids = value.map(v => Number(v)).filter(v => Number.isInteger(v));
          normalized[qId] = ids;
          restoredMulti[qId] = ids.slice();
          continue;
        }
        if (typeof value === 'object' && value.answerText) {
          const text = String(value.answerText);
          normalized[qId] = { answerText: text };
          restoredOpenTexts[qId] = text;
          continue;
        }
        normalized[qId] = Number(value);
      }

      this.userAnswers = normalized;
      this.openAnswerTexts = restoredOpenTexts;
      this.multiSelections = restoredMulti;
    },
    findFirstUnansweredIndex() {
      if (!Array.isArray(this.questions) || this.questions.length === 0) return 0;
      for (let i = 0; i < this.questions.length; i++) {
        const qId = this.questions[i].id;
        if (this.userAnswers[qId] === undefined) {
          return i;
        }
      }
      return 0;
    },

    startTimer() {
      if (this.timerInterval) clearInterval(this.timerInterval);
      this.timerInterval = setInterval(() => {
        if (this.examDeadlineAt) {
          const now = Math.floor(Date.now() / 1000);
          this.timeLeftSeconds = Math.max(0, this.examDeadlineAt - now);
        } else if (this.timeLeftSeconds > 0) {
          this.timeLeftSeconds -= 1;
        }

        if (this.timeLeftSeconds <= 0) {
          this.finishExam({ forceTimedOut: true });
        } else {
          this.timeLeftSeconds = Math.max(0, this.timeLeftSeconds);
        }
      }, 1000);
    },
    lockKey() {
      return this.session ? `learning_exam_lock_${this.session}` : null
    },
    claimLock() {
      const key = this.lockKey()
      if (!key || !this.tabLockId) return true
      const now = Date.now()
      const raw = localStorage.getItem(key)
      if (raw) {
        try {
          const parsed = JSON.parse(raw)
          if (parsed.tabId !== this.tabLockId && (now - Number(parsed.ts || 0)) < 15000) {
            this.lockDenied = true
            return false
          }
        } catch {}
      }
      localStorage.setItem(key, JSON.stringify({ tabId: this.tabLockId, ts: now }))
      this.lockDenied = false
      return true
    },
    startExamLock() {
      this.tabLockId = this.tabLockId || `tab_${Math.random().toString(36).slice(2)}_${Date.now()}`
      this.claimLock()
      if (this.lockHeartbeat) clearInterval(this.lockHeartbeat)
      this.lockHeartbeat = setInterval(() => this.claimLock(), 5000)
    },
    releaseExamLock() {
      if (this.lockHeartbeat) {
        clearInterval(this.lockHeartbeat)
        this.lockHeartbeat = null
      }
      const key = this.lockKey()
      if (!key || !this.tabLockId) return
      const raw = localStorage.getItem(key)
      if (!raw) return
      try {
        const parsed = JSON.parse(raw)
        if (parsed.tabId === this.tabLockId) {
          localStorage.removeItem(key)
        }
      } catch {}
    },
    startStatusPolling() {
      if (this.statusPollTimer) clearInterval(this.statusPollTimer)
      this.statusPollTimer = setInterval(() => this.refreshSessionStatus(), 15000)
    },
    stopStatusPolling() {
      if (this.statusPollTimer) {
        clearInterval(this.statusPollTimer)
        this.statusPollTimer = null
      }
    },
    async refreshSessionStatus() {
      if (!this.session || this.screen !== 'exam') return
      try {
        const r = await axios.get(
          generateUrl('/apps/learning/api/training/session/{id}', { id: this.session }),
          { params: this.buildStatusParams(false) }
        )
        const s = r.data || {}
        if (typeof s.remaining_seconds === 'number') {
          this.timeLeftSeconds = s.remaining_seconds
        }
        if (s.completed) {
          await this.fetchCompletedResultOnly(!!s.timed_out)
        }
      } catch {}
    },
    async fetchCompletedResultOnly(forceTimedOut = false) {
      if (this.timerInterval) {
        clearInterval(this.timerInterval)
        this.timerInterval = null
      }
      this.stopStatusPolling()
      this.releaseExamLock()
      this.examEndTime = Math.floor(Date.now() / 1000)
      this.screen = 'results'
      this.isLoading = true
      try {
        const cr = await axios.post(generateUrl('/apps/learning/api/training/complete'), this.requestPayload({ sessionId: this.session }))
        this.resultsData = cr.data
        this.resultsData.timed_out = !!(cr.data.timed_out || forceTimedOut)
      } catch {
        this.resultsData = { total_questions: this.questions.length, correct_answers: 0, score_percentage: 0, timed_out: !!forceTimedOut }
      } finally {
        this.isLoading = false
      }
    },

    isAnswerSelected(answerId) {
      const qId = this.currentQuestion.id;
      if (this.isCurrentOpen) return false;
      if (this.isCurrentMulti) {
        return this.multiSelections[qId] && this.multiSelections[qId].includes(answerId);
      }
      return this.userAnswers[qId] === answerId;
    },
    answerQuestion(answerId) {
      if (this.isCurrentMulti) {
        if (this.lockDenied) return
        // Toggle multi-select
        const qId = this.currentQuestion.id;
        if (!this.multiSelections[qId]) {
          this.$set(this.multiSelections, qId, []);
        }
        const idx = this.multiSelections[qId].indexOf(answerId);
        if (idx >= 0) {
          this.multiSelections[qId].splice(idx, 1);
        } else {
          this.multiSelections[qId].push(answerId);
        }
        return; // Don't auto-advance for multi
      }
      this.$set(this.userAnswers, this.currentQuestion.id, answerId);
      this.advanceToNext();
    },
    submitOpenExamAnswer() {
      if (this.lockDenied) return;
      const qId = this.currentQuestion.id;
      const text = (this.openAnswerTexts[qId] || '').trim();
      if (!text) return;
      this.$set(this.userAnswers, qId, { answerText: text });
      this.advanceToNext();
    },
    confirmMultiAnswer() {
      if (this.lockDenied) return;
      const qId = this.currentQuestion.id;
      // Store the multi selection as the answer
      this.$set(this.userAnswers, qId, this.multiSelections[qId].slice());
      this.advanceToNext();
    },

    skipQuestion() {
      if (this.lockDenied) return;
      if (this.userAnswers[this.currentQuestion.id] === undefined) {
        this.$set(this.userAnswers, this.currentQuestion.id, null);
      }
      this.advanceToNext();
    },

    advanceToNext() {
      // Find next unanswered question
      for (let i = 1; i <= this.questions.length; i++) {
        const idx = (this.currentQuestionIndex + i) % this.questions.length;
        const qId = this.questions[idx].id;
        if (this.userAnswers[qId] === undefined) {
          this.currentQuestionIndex = idx;
          return;
        }
      }
      // All questions touched — check if all answered
      if (Object.keys(this.userAnswers).length >= this.questions.length) {
        this.finishExam();
      }
    },

    jumpToQuestion(index) {
      this.currentQuestionIndex = index;
    },

    confirmEndExam() {
      if (this.isLoading) {
        return;
      }
      const shouldEnd = window.confirm(
        t('learning', '{answered} of {total} questions answered. End the exam now?', {
          answered: this.answeredCount,
          total: this.questions.length,
        })
      );
      if (shouldEnd) {
        this.finishExam();
      }
    },

    confirmAbortExam() {
      if (this.isLoading) {
        return;
      }
      const shouldAbort = window.confirm(
        t('learning', 'Abort the exam? Your progress will be discarded and no result will be saved.')
      );
      if (shouldAbort) {
        this.abortExam();
      }
    },

    async finishExam({ forceTimedOut = false } = {}) {
      if (this.isLoading) return;
      if (this.timerInterval) {
        clearInterval(this.timerInterval);
        this.timerInterval = null;
      }
      this.stopStatusPolling();
      this.releaseExamLock();
      this.examEndTime = Math.floor(Date.now() / 1000);
      this.isLoading = true;
      this.screen = 'results';

      try {
        // Collect answered questions into batch
        const batchAnswers = [];
        for (const q of this.questions) {
          if (q.question_type === 'pbq') {
            const pbqAns = this.pbqAnswers[q.id];
            if (pbqAns) batchAnswers.push({ questionId: q.id, pbqAnswers: pbqAns });
            continue;
          }
          const answer = this.userAnswers[q.id];
          if (answer !== null && answer !== undefined) {
            if (answer && typeof answer === 'object' && answer.answerText) {
              batchAnswers.push({ questionId: q.id, answerText: answer.answerText });
            } else if (Array.isArray(answer)) {
              batchAnswers.push({ questionId: q.id, answerIds: answer });
            } else {
              batchAnswers.push({ questionId: q.id, answerId: answer });
            }
          }
        }

        // Submit all answers (exam mode: server won't return correct answers)
        let batchTimedOut = false;
        if (batchAnswers.length > 0) {
          try {
            await axios.post(generateUrl('/apps/learning/api/training/submitBatch'), {
              sessionId: this.session,
              answers: batchAnswers,
              ...(this.effectiveContentLanguage ? { lang: this.effectiveContentLanguage } : {}),
            });
          } catch (submitErr) {
            if (submitErr.response?.status === 409 && submitErr.response?.data?.timed_out) {
              batchTimedOut = true;
            } else {
              throw submitErr;
            }
          }
        }

        // Complete session — server returns review data for exam sessions
        const cr = await axios.post(generateUrl('/apps/learning/api/training/complete'), this.requestPayload({ sessionId: this.session }));
        this.resultsData = cr.data;
        this.resultsData.timed_out = !!(cr.data.timed_out || batchTimedOut || forceTimedOut);

        // Build detailed results from server review data
        const reviewMap = {};
        if (cr.data.review) {
          for (const r of cr.data.review) {
            reviewMap[r.questionId] = r;
          }
        }

        const results = [];
        for (const q of this.questions) {
          const answer = this.userAnswers[q.id];
          const rv = reviewMap[q.id];
          let userAnswerText = null;
          const isOpen = q.question_type === 'open';
          if (isOpen && answer && typeof answer === 'object' && answer.answerText) {
            userAnswerText = answer.answerText;
          } else if (Array.isArray(answer)) {
            const texts = answer.map(aid => {
              const a = q.answers.find(a => a.id === aid);
              return a ? a.text : '';
            }).filter(Boolean);
            userAnswerText = texts.join(', ');
          } else if (answer) {
            const userAnswer = q.answers.find(a => a.id === answer);
            userAnswerText = userAnswer ? userAnswer.text : null;
          }
          const correctText = rv ? rv.correct_answer_texts.join(', ') : '';

          let answerDetails = null;
          if (rv && rv.answers) {
            answerDetails = rv.answers.map(a => ({
              text: a.text,
              isCorrect: a.is_correct,
              wasSelected: a.was_selected,
            }));
          }
          results.push({
            questionText: rv ? rv.questionText : q.text,
            userAnswerText: userAnswerText,
            correctAnswerText: correctText,
            isCorrect: rv ? rv.is_correct : false,
            isMulti: rv ? rv.questionType === 'multi' : q.question_type === 'multi',
            isOpen: isOpen,
            answerDetails: answerDetails,
            instructorNote: q.instructor_note || null,
            noteVisible: q.note_visible || false,
          });
        }
        this.detailedResults = results;
        if (this.resultsData && this.resultsData.score_percentage >= 90) {
          celebratePerfectSession();
        }
        if (this.resultsData && this.resultsData.score_percentage < 60) {
          this.emitVirtuProf('exam-low-score');
        }
        // countUp animation
        this.$nextTick(() => {
          if (this.$refs.examScoreNumber && this.resultsData) {
            countUp(this.$refs.examScoreNumber, this.resultsData.score_percentage, 1200, '%');
          }
        });
        // Show badge unlocks
        if (cr.data.newly_earned_badges && cr.data.newly_earned_badges.length > 0) {
          this.newBadges = cr.data.newly_earned_badges;
          this.emitBadgeTrigger(cr.data.newly_earned_badges);
        }
      } catch (e) {
        showError(t('learning', 'Failed to process results'));
        this.resultsData = {
          total_questions: this.questions.length,
          correct_answers: this.detailedResults.filter(r => r.isCorrect).length,
          score_percentage: Math.round(this.detailedResults.filter(r => r.isCorrect).length / this.questions.length * 100),
          timed_out: !!forceTimedOut,
          attempt_no: this.attemptNo,
        };
      } finally {
        this.isLoading = false;
      }
    },

    async abortExam() {
      if (this.timerInterval) {
        clearInterval(this.timerInterval);
        this.timerInterval = null;
      }
      this.stopStatusPolling();
      this.releaseExamLock();
      if (this.session) {
        try {
          await axios.post(generateUrl('/apps/learning/api/training/abort'), { sessionId: this.session });
        } catch {}
      }
      this.$emit('back');
    },

    retakeExam() {
      this.cleanupSnake();
      this.screen = 'setup';
      this.session = null;
      this.questions = [];
      this.userAnswers = {};
      this.multiSelections = {};
      this.openAnswerTexts = {};
      this.pbqAnswers = {};
      this.resultsData = null;
      this.detailedResults = [];
      this.examDeadlineAt = null;
      this.attemptNo = null;
      this.resumedFromServer = false;
      this.lockDenied = false;
      this.stopStatusPolling();
      this.releaseExamLock();
      if (this.timerInterval) clearInterval(this.timerInterval);
      this.timerInterval = null;
      this.timeLeftSeconds = null;
    },
  },
  mounted() {
    window.addEventListener('keydown', this.handleExamHotkeys);
    this.emitVirtuProf('exam-first-start');
  },

  beforeDestroy() {
    window.removeEventListener('keydown', this.handleExamHotkeys);
    if (this.timerInterval) clearInterval(this.timerInterval);
    this.stopStatusPolling();
    this.releaseExamLock();
    this.cleanupSnake();
  },
};
</script>

<style scoped>
.exam-mode { max-width: 900px; margin: 0 auto; }

.exam-title { font-size: 28px; font-weight: 700; text-align: center; margin-bottom: 8px; color: var(--color-main-text); }
.exam-description { text-align: center; color: var(--color-text-maxcontrast); margin-bottom: 32px; font-size: 15px; }

/* Setup */
.setup-screen { text-align: center; padding: 40px 20px; }
.setup-section { margin-bottom: 28px; }
.setup-label { font-size: 14px; font-weight: 600; color: var(--color-text-maxcontrast); margin-bottom: 12px; }
.button-group { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
.start-actions { display: flex; flex-direction: column; gap: 10px; align-items: center; margin-top: 24px; max-width: 300px; margin-left: auto; margin-right: auto; }

/* Timer */
.timer-display {
  font-size: 48px;
  font-weight: 700;
  text-align: center;
  padding: 12px 24px;
  border-radius: 12px;
  margin-bottom: 16px;
  transition: background 0.3s, color 0.3s;
  color: var(--color-main-text);
}
.timer-green { background: color-mix(in srgb, var(--color-success) 15%, transparent); color: var(--color-success); }
.timer-yellow { background: color-mix(in srgb, var(--color-warning) 15%, transparent); color: var(--color-warning); }
.timer-red { background: color-mix(in srgb, var(--color-error) 15%, transparent); color: var(--color-error); }
.exam-meta-line { text-align: center; color: var(--color-text-maxcontrast); font-size: 13px; margin: -4px 0 12px; }
.resume-note { text-align: center; color: var(--color-primary-element); font-size: 13px; margin: -4px 0 12px; }
.lock-note { text-align: center; color: var(--color-warning); font-size: 13px; margin: -4px 0 12px; font-weight: 600; }
.exam-mode-banner { margin: 0 0 12px; }
.hotkey-note {
  text-align: center;
  color: var(--color-text-maxcontrast);
  font-size: 12px;
  margin: -4px 0 12px;
}

.progress-label { text-align: center; font-size: 13px; color: var(--color-text-maxcontrast); margin: 8px 0 24px; }

/* Question Card */
.question-card {
  position: relative;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 16px;
  padding: 60px 28px 28px;
  margin-bottom: 20px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

/* Snake Timer */
.snake-svg {
  position: absolute;
  top: 0;
  left: 0;
  pointer-events: none;
  z-index: 1;
  filter: drop-shadow(0 0 3px var(--color-success));
  transition: filter 0.5s ease;
}
.snake-svg.snake-warning {
  filter: drop-shadow(0 0 3px var(--color-warning));
}
.snake-svg.snake-danger {
  filter: drop-shadow(0 0 4px var(--color-error));
}
.snake-line {
  stroke: var(--color-success);
  transition: stroke-dashoffset 1s linear, stroke 0.5s ease;
}
.snake-warning .snake-line {
  stroke: var(--color-warning);
}
.snake-danger .snake-line {
  stroke: var(--color-error);
  animation: snakePulse 1.5s ease-in-out infinite;
}
@keyframes snakePulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}
.question-number { text-align: center; font-size: 13px; color: var(--color-text-maxcontrast); margin-bottom: 12px; font-weight: 500; }
.question-image { max-width: 100%; max-height: 200px; border-radius: 12px; border: 1px solid var(--color-border); margin-bottom: 16px; object-fit: contain; display: block; margin-left: auto; margin-right: auto; }
.question-text { font-size: 20px; font-weight: 500; line-height: 1.6; margin-bottom: 24px; text-align: center; color: var(--color-main-text); }

.answer-options { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
.answer-btn {
  padding: 14px 20px;
  border: 2px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-main-background);
  cursor: pointer;
  text-align: left;
  font-size: 15px;
  transition: all 0.15s;
  min-height: 52px;
  line-height: 1.5;
  color: var(--color-main-text);
}
.answer-btn:hover { border-color: var(--color-primary-element); background: var(--color-background-hover); }
.answer-btn.answer-selected {
  border-color: var(--color-primary-element);
  background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background));
  color: var(--color-primary-element);
  font-weight: 600;
}
.multi-hint { text-align: center; font-size: 14px; font-weight: 600; color: var(--color-primary-element); margin-bottom: 16px; padding: 8px; background: color-mix(in srgb, var(--color-primary-element) 8%, transparent); border-radius: 8px; }
.confirm-btn { margin-top: 12px; }
.skip-btn { margin-top: 8px; }

/* Navigation Bar */
.nav-bar {
  position: fixed;
  bottom: 0;
  left: 0;
  width: 100%;
  background: var(--color-background-dark);
  box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
  padding: 10px 16px;
  z-index: 5;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  display: flex;
  align-items: center;
  gap: 12px;
}
.nav-bar-inner { display: flex; gap: 6px; flex: 1; justify-content: center; flex-wrap: nowrap; }
.end-exam-btn { flex-shrink: 0; }
.abort-exam-btn { flex-shrink: 0; }
.nav-dot {
  width: 40px; height: 40px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 600;
  cursor: pointer;
  flex-shrink: 0;
  transition: all 0.15s;
  background: var(--color-background-hover);
  color: var(--color-text-maxcontrast);
}
.nav-dot:hover { background: var(--color-border); }
.nav-dot-answered {
  background: color-mix(in srgb, var(--color-primary-element) 30%, transparent);
  color: var(--color-primary-element);
}
.nav-dot-current {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  transform: scale(1.15);
}

/* Exam screen needs bottom padding for nav bar */
.exam-screen { padding-bottom: 70px; }

/* Results */
.results-screen { padding: 32px 20px; }
.results-summary { display: flex; flex-direction: column; align-items: center; margin-bottom: 32px; }

.score-circle {
  width: 160px; height: 160px;
  border-radius: 50%;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  margin-bottom: 24px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.12);
}
.score-green { background: var(--color-success); color: var(--color-primary-element-text); }
.score-yellow { background: var(--color-warning); color: var(--color-main-text); }
.score-red { background: var(--color-error); color: var(--color-primary-element-text); }
.score-number { font-size: 42px; font-weight: 700; }
.score-label { font-size: 14px; font-weight: 400; opacity: 0.8; }

.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; width: 100%; max-width: 500px; margin-top: 12px; }
.stat-item {
  text-align: center;
  padding: 12px 8px;
  background: var(--color-background-hover);
  border-radius: 10px;
  border: 1px solid var(--color-border);
}
.stat-value { font-size: 20px; font-weight: 700; color: var(--color-main-text); }
.stat-label { font-size: 12px; color: var(--color-text-maxcontrast); margin-top: 2px; }

/* Review */
.review-title { font-size: 18px; font-weight: 600; margin: 28px 0 16px; color: var(--color-main-text); }
.timeout-note { margin: 0 0 14px; text-align: center; color: var(--color-error); font-weight: 600; }
.review-list { border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; margin-bottom: 28px; }
.review-item { padding: 16px; border-bottom: 1px solid var(--color-border); }
.review-item:last-child { border-bottom: none; }
.review-correct { background: color-mix(in srgb, var(--color-success) 5%, var(--color-main-background)); }
.review-wrong { background: color-mix(in srgb, var(--color-error) 5%, var(--color-main-background)); }
.review-question { font-weight: 600; margin-bottom: 8px; color: var(--color-main-text); line-height: 1.5; }
.review-answer { font-size: 14px; line-height: 1.5; margin-bottom: 4px; }
.review-label { font-weight: 500; margin-right: 8px; color: var(--color-text-maxcontrast); }
.text-success { color: var(--color-success); font-weight: 600; }
.text-error { color: var(--color-error); font-weight: 600; }

/* Multi-select review visualization */
.review-answers-detail { display: flex; flex-direction: column; gap: 6px; margin-top: 8px; }
.review-answer-item { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 8px; font-size: 14px; border: 1px solid var(--color-border); }
.ra-icon { font-weight: 700; flex-shrink: 0; width: 20px; text-align: center; }
.ra-correct-selected { border-color: var(--color-success); background: color-mix(in srgb, var(--color-success) 10%, var(--color-main-background)); color: var(--color-success); }
.ra-wrong-selected { border-color: var(--color-error); background: color-mix(in srgb, var(--color-error) 10%, var(--color-main-background)); color: var(--color-error); }
.ra-correct-missed { border-color: var(--color-warning); background: color-mix(in srgb, var(--color-warning) 10%, var(--color-main-background)); color: var(--color-warning); }
.ra-neutral { color: var(--color-text-maxcontrast); }

.open-answer-area { display: flex; flex-direction: column; gap: 12px; align-items: center; margin-bottom: 16px; }
.open-textarea { width: 100%; padding: 14px; font-size: 15px; border: 2px solid var(--color-border); border-radius: 12px; resize: vertical; min-height: 80px; box-sizing: border-box; }
.open-textarea:focus { border-color: var(--color-primary-element); outline: none; }
.open-answer-review { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; margin-bottom: 12px; }
.open-review-row { padding: 10px 14px; border-radius: 8px; background: var(--color-background-hover); font-size: 14px; line-height: 1.5; }

.xp-earned { font-size: 22px; font-weight: 700; color: var(--color-primary-element); margin: 16px 0 8px; text-align: center; animation: xpFadeIn 0.5s ease-out 0.5s both; }
@keyframes xpFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@media (max-width: 768px) {
  .timer-display { font-size: 36px; }
  .question-text { font-size: 17px; }
  .question-card { padding: 56px 20px 20px; }
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
  .score-circle { width: 130px; height: 130px; }
  .score-number { font-size: 36px; }
  .setup-screen { padding: 24px 12px; }
}

@media (prefers-reduced-motion: reduce) {
  .snake-timer-svg { animation: none; }
  .xp-earned { animation: none; }
  .timer-danger .snake-timer-svg { animation: none; }
}
</style>
