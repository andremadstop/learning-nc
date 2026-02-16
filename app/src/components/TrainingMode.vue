<template>
  <div class="training-mode">
    <div v-if="!session && !loadError" class="training-start">
      <h3>{{ t('learning', 'Start Training Session') }}</h3>
      <p v-if="totalQuestions > 0">{{ t('learning', 'Test your knowledge with {n} questions', { n: totalQuestions }) }}</p>
      <NcEmptyContent v-else :name="t('learning', 'No questions')" :description="t('learning', 'No questions available for training')" />
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
      <div class="question-counter">Question {{ currentIndex + 1 }} of {{ questions.length }}</div>

      <div v-if="currentQuestion" class="question-card">
        <img v-if="currentQuestion.image_path" :src="questionImageUrl(currentQuestion.id)" alt="" class="question-image" />
        <div class="question-text">{{ currentQuestion.text }}</div>
        <div v-if="!answered" class="answers-grid">
          <template v-if="currentQuestion.answers && currentQuestion.answers.length > 0">
            <button v-for="answer in currentQuestion.answers" :key="answer.id" @click="submitAnswer(answer.id)" class="answer-btn" :disabled="submitting">{{ answer.text }}</button>
          </template>
          <div v-else class="no-answers">
            <p>{{ t('learning', 'This question has no answers yet.') }}</p>
            <NcButton type="secondary" @click="skipQuestion">{{ t('learning', 'Skip') }}</NcButton>
          </div>
        </div>
        <div v-else class="answer-feedback">
          <NcNoteCard :type="isCorrect ? 'success' : 'error'">{{ isCorrect ? t('learning', 'Correct!') : t('learning', 'Incorrect') }}</NcNoteCard>
          <div class="correct-answer"><strong>{{ t('learning', 'Correct answer:') }}</strong> {{ correctAnswerText }}</div>
          <NcNoteCard v-if="currentQuestion.explanation" type="warning"><strong>{{ t('learning', 'Explanation:') }}</strong> {{ currentQuestion.explanation }}</NcNoteCard>
          <NcButton type="primary" wide @click="nextQuestion" class="next-btn">{{ currentIndex < questions.length - 1 ? t('learning', 'Next Question \u2192') : t('learning', 'See Results') }}</NcButton>
        </div>
      </div>
    </div>

    <div v-else class="training-results">
      <h3>{{ t('learning', 'Training Complete!') }}</h3>
      <div class="score-display">
        <div class="score-circle"><span class="score-number">{{ results.score_percentage }}%</span></div>
        <p>{{ t('learning', '{n} out of {total} correct', { n: results.correct_answers, total: results.total_questions }) }}</p>
      </div>
      <div class="result-actions">
        <NcButton type="primary" @click="restartTraining">{{ t('learning', 'Train Again') }}</NcButton>
        <NcButton type="tertiary" @click="$emit('back')">{{ t('learning', 'Back to Questions') }}</NcButton>
      </div>
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

export default {
  name: 'TrainingMode',
  components: { NcButton, NcNoteCard, NcProgressBar, NcEmptyContent },
  props: {
    poolId: { type: Number, required: true },
    totalQuestions: { type: Number, required: true }
  },
  data() {
    return { session: null, questions: [], currentIndex: 0, answered: false, submitting: false, isCorrect: false, correctAnswerText: '', showResults: false, results: null, starting: false, loadError: null };
  },
  computed: {
    currentQuestion() { return this.questions[this.currentIndex] || null; },
    progress() { return ((this.currentIndex + 1) / this.questions.length) * 100; }
  },
  methods: {
    questionImageUrl(id) {
      return generateUrl('/apps/learning/api/questions/' + id + '/image');
    },
    async startTraining() {
      this.starting = true; this.loadError = null;
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/training/start'), { poolId: this.poolId });
        this.session = response.data.session_id; this.questions = response.data.questions;
      } catch (error) { this.loadError = error.response?.data?.error || t('learning', 'Failed to start training.'); }
      finally { this.starting = false; }
    },
    async submitAnswer(answerId) {
      this.submitting = true;
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/training/answer'), { sessionId: this.session, questionId: this.currentQuestion.id, answerId: answerId });
        // Use server response for correct answer info (is_correct no longer in question data)
        this.isCorrect = response.data.is_correct;
        this.correctAnswerText = response.data.correct_answer_text || '';
        this.answered = true;
      } catch (error) { showError(t('learning', 'Failed to submit answer')); }
      finally { this.submitting = false; }
    },
    async nextQuestion() {
      if (this.currentIndex < this.questions.length - 1) { this.currentIndex++; this.answered = false; }
      else { await this.completeSession(); }
    },
    async completeSession() {
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/training/complete'), { sessionId: this.session });
        this.results = response.data; this.showResults = true;
      } catch (error) { showError(t('learning', 'Failed to complete session')); }
    },
    skipQuestion() {
      if (this.currentIndex < this.questions.length - 1) { this.currentIndex++; this.answered = false; }
      else { this.completeSession(); }
    },
    restartTraining() {
      this.session = null; this.questions = []; this.currentIndex = 0; this.answered = false;
      this.showResults = false; this.results = null; this.loadError = null; this.startTraining();
    }
  }
};
</script>

<style scoped>
.training-mode { max-width: 900px; margin: 0 auto; }
.training-start { text-align: center; padding: 80px 20px; }
.training-start h3 { font-size: 28px; margin-bottom: 12px; font-weight: 700; color: var(--color-main-text); }
.training-start p { font-size: 16px; color: var(--color-text-maxcontrast); margin-bottom: 28px; }
.start-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
.question-counter { text-align: center; font-size: 14px; color: var(--color-text-maxcontrast); margin: 12px 0 28px; font-weight: 500; }
.question-card { background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: 16px; padding: 32px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
.question-image { max-width: 100%; max-height: 240px; border-radius: 12px; border: 1px solid var(--color-border); margin-bottom: 16px; object-fit: contain; display: block; }
.question-text { font-size: 20px; font-weight: 500; margin-bottom: 28px; line-height: 1.6; color: var(--color-main-text); }
.answers-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
.answer-btn { padding: 16px 20px; border: 2px solid var(--color-border); border-radius: 12px; background: var(--color-main-background); cursor: pointer; text-align: left; font-size: 15px; transition: all 0.15s; min-height: 56px; line-height: 1.5; color: var(--color-main-text); }
.answer-btn:hover:not(:disabled) { border-color: var(--color-primary-element); background: var(--color-primary-element-light); transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.answer-btn:disabled { opacity: 0.7; cursor: wait; }
.no-answers { grid-column: 1 / -1; text-align: center; padding: 20px; color: var(--color-text-maxcontrast); }
.no-answers p { margin-bottom: 12px; }
.answer-feedback { margin-top: 28px; }
.correct-answer { padding: 14px 18px; background: var(--color-background-hover); border-radius: 10px; margin-bottom: 12px; font-size: 14px; line-height: 1.5; color: var(--color-main-text); }
.next-btn { margin-top: 20px; }
.training-results { text-align: center; padding: 60px 20px; }
.training-results h3 { font-size: 32px; margin-bottom: 36px; font-weight: 700; color: var(--color-main-text); }
.score-display { margin-bottom: 36px; }
.score-circle { width: 180px; height: 180px; border-radius: 50%; background: var(--color-primary-element); color: var(--color-primary-element-text); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; box-shadow: 0 8px 24px rgba(0,100,180,0.3); }
.score-number { font-size: 48px; font-weight: 700; }
.score-display p { font-size: 16px; color: var(--color-text-maxcontrast); }
.result-actions { display: flex; justify-content: center; gap: 14px; flex-wrap: wrap; }
@media (max-width: 768px) {
  .training-start { padding: 40px 16px; }
  .question-card { padding: 20px; border-radius: 12px; }
  .question-text { font-size: 17px; }
  .answers-grid { grid-template-columns: 1fr; }
  .score-circle { width: 140px; height: 140px; }
  .score-number { font-size: 36px; }
}
</style>