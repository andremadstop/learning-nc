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

      <NcProgressBar :value="progressPercentage" />
      <div class="progress-label">{{ answeredCount }} / {{ questions.length }} {{ t('learning', 'answered') }}</div>

      <div v-if="currentQuestion" class="question-card">
        <div class="question-number">{{ t('learning', 'Question {n} of {total}', { n: currentQuestionIndex + 1, total: questions.length }) }}</div>
        <img v-if="currentQuestion.image_path" :src="questionImageUrl(currentQuestion.id)" alt="" class="question-image" />
        <div class="question-text">{{ currentQuestion.text }}</div>

        <div class="answer-options">
          <button
            v-for="answer in currentQuestion.answers"
            :key="answer.id"
            class="answer-btn"
            :class="{ 'answer-selected': userAnswers[currentQuestion.id] === answer.id }"
            @click="answerQuestion(answer.id)"
          >
            {{ answer.text }}
          </button>
        </div>

        <NcButton type="secondary" wide @click="skipQuestion" class="skip-btn">{{ t('learning', 'Skip') }}</NcButton>
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
            @click="jumpToQuestion(index)"
          >
            {{ index + 1 }}
          </div>
        </div>
      </div>
    </div>

    <!-- Results Screen -->
    <div v-else-if="screen === 'results'" class="results-screen">
      <h3 class="exam-title">{{ t('learning', 'Exam Results') }}</h3>

      <div v-if="resultsData" class="results-summary">
        <div class="score-circle" :class="scoreColorClass">
          <span class="score-number">{{ resultsData.score_percentage }}%</span>
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
        </div>
      </div>

      <div class="start-actions">
        <NcButton type="primary" wide @click="retakeExam">{{ t('learning', 'Retake Exam') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back') }}</NcButton>
      </div>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js';
import NcProgressBar from '@nextcloud/vue/dist/Components/NcProgressBar.js';
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js';
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError } from '@nextcloud/dialogs';

export default {
  name: 'ExamMode',
  components: { NcButton, NcNoteCard, NcProgressBar, NcEmptyContent, NcLoadingIcon },
  props: {
    poolId: { type: Number, required: true },
    totalQuestions: { type: Number, required: true }
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

      resultsData: null,
      detailedResults: [],
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
    currentQuestion() {
      return this.questions[this.currentQuestionIndex] || null;
    },
    answeredCount() {
      return Object.values(this.userAnswers).filter(v => v !== null).length;
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
    sortedDetailedResults() {
      return [...this.detailedResults].sort((a, b) => {
        if (a.isCorrect === b.isCorrect) return 0;
        return a.isCorrect ? 1 : -1;
      });
    }
  },
  methods: {
    questionImageUrl(id) {
      return generateUrl('/apps/learning/api/questions/' + id + '/image');
    },
    async startExam() {
      this.isLoading = true;
      try {
        const params = { poolId: this.poolId };
        if (this.selectedQuestionCount > 0) {
          params.limit = this.selectedQuestionCount;
        }
        const r = await axios.post(generateUrl('/apps/learning/api/training/start'), params);
        this.session = r.data.session_id;
        const questions = r.data.questions;

        if (!questions.length) {
          showError(t('learning', 'No questions available'));
          this.isLoading = false;
          return;
        }

        this.questions = questions;
        this.currentQuestionIndex = 0;
        this.userAnswers = {};
        this.detailedResults = [];
        this.resultsData = null;
        this.examDurationSeconds = this.selectedTimeLimit;
        this.timeLeftSeconds = this.selectedTimeLimit;
        this.examStartTime = Math.floor(Date.now() / 1000);
        this.examEndTime = null;

        this.startTimer();
        this.screen = 'exam';
      } catch (e) {
        showError(e.response?.data?.error || t('learning', 'Failed to start exam'));
      } finally {
        this.isLoading = false;
      }
    },

    startTimer() {
      if (this.timerInterval) clearInterval(this.timerInterval);
      this.timerInterval = setInterval(() => {
        if (this.timeLeftSeconds > 0) {
          this.timeLeftSeconds--;
        } else {
          this.finishExam();
        }
      }, 1000);
    },

    answerQuestion(answerId) {
      this.$set(this.userAnswers, this.currentQuestion.id, answerId);
      this.advanceToNext();
    },

    skipQuestion() {
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

    async finishExam() {
      if (this.timerInterval) {
        clearInterval(this.timerInterval);
        this.timerInterval = null;
      }
      this.examEndTime = Math.floor(Date.now() / 1000);
      this.isLoading = true;
      this.screen = 'results';

      try {
        // Collect answered questions into batch
        const batchAnswers = [];
        for (const q of this.questions) {
          const answerId = this.userAnswers[q.id];
          if (answerId !== null && answerId !== undefined) {
            batchAnswers.push({ questionId: q.id, answerId });
          }
        }

        // Submit all answers in a single request
        let serverResults = [];
        if (batchAnswers.length > 0) {
          const r = await axios.post(generateUrl('/apps/learning/api/training/submitBatch'), {
            sessionId: this.session,
            answers: batchAnswers,
          });
          serverResults = r.data;
        }

        // Build detailed results by matching server response
        const resultMap = {};
        for (const sr of serverResults) {
          resultMap[sr.questionId] = sr;
        }

        const results = [];
        for (const q of this.questions) {
          const answerId = this.userAnswers[q.id];
          const userAnswer = answerId ? q.answers.find(a => a.id === answerId) : null;
          const sr = resultMap[q.id];
          results.push({
            questionText: q.text,
            userAnswerText: userAnswer ? userAnswer.text : null,
            correctAnswerText: sr ? (sr.correct_answer_text || '') : '',
            isCorrect: sr ? sr.is_correct : false,
          });
        }
        this.detailedResults = results;

        // Complete session
        const cr = await axios.post(generateUrl('/apps/learning/api/training/complete'), { sessionId: this.session });
        this.resultsData = cr.data;
      } catch (e) {
        showError(t('learning', 'Failed to process results'));
        this.resultsData = {
          total_questions: this.questions.length,
          correct_answers: this.detailedResults.filter(r => r.isCorrect).length,
          score_percentage: Math.round(this.detailedResults.filter(r => r.isCorrect).length / this.questions.length * 100),
        };
      } finally {
        this.isLoading = false;
      }
    },

    retakeExam() {
      this.screen = 'setup';
      this.session = null;
      this.questions = [];
      this.userAnswers = {};
      this.resultsData = null;
      this.detailedResults = [];
      if (this.timerInterval) clearInterval(this.timerInterval);
      this.timerInterval = null;
      this.timeLeftSeconds = null;
    },
  },

  beforeDestroy() {
    if (this.timerInterval) clearInterval(this.timerInterval);
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

.progress-label { text-align: center; font-size: 13px; color: var(--color-text-maxcontrast); margin: 8px 0 24px; }

/* Question Card */
.question-card {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 16px;
  padding: 28px;
  margin-bottom: 20px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.04);
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
.skip-btn { margin-top: 8px; }

/* Navigation Bar */
.nav-bar {
  position: fixed;
  bottom: 0;
  left: 0;
  width: 100%;
  background: var(--color-background-dark);
  box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
  padding: 10px 0;
  z-index: 5;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.nav-bar-inner { display: flex; gap: 6px; padding: 0 16px; justify-content: center; flex-wrap: nowrap; }
.nav-dot {
  width: 32px; height: 32px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 600;
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

@media (max-width: 768px) {
  .timer-display { font-size: 36px; }
  .question-text { font-size: 17px; }
  .question-card { padding: 20px; }
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
  .score-circle { width: 130px; height: 130px; }
  .score-number { font-size: 36px; }
  .setup-screen { padding: 24px 12px; }
}
</style>
