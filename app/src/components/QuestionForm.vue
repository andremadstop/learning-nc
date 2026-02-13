<template>
  <div class="dialog-overlay" @click.self="$emit('close')">
    <div class="dialog-content question-form">
      <h3>{{ question ? 'Edit Question' : 'Create Question' }}</h3>
      <form @submit.prevent="save">
        <div class="form-group">
          <label for="question-text">Question *</label>
          <textarea
            id="question-text"
            v-model="form.text"
            required
            rows="3"
            placeholder="Enter your question..."
          ></textarea>
        </div>

        <div class="form-group">
          <label for="difficulty">Difficulty</label>
          <select id="difficulty" v-model="form.difficulty">
            <option value="">None</option>
            <option value="easy">Easy</option>
            <option value="medium">Medium</option>
            <option value="hard">Hard</option>
          </select>
        </div>

        <div class="form-group">
          <label>Answers (select the correct one) *</label>
          <div class="answers-form">
            <div v-for="(answer, index) in form.answers" :key="index" class="answer-row">
              <input
                type="radio"
                :id="`correct-${index}`"
                :value="index"
                v-model="correctAnswerIndex"
                required
              />
              <input
                type="text"
                v-model="answer.text"
                :placeholder="`Answer ${index + 1}`"
                required
              />
            </div>
          </div>
        </div>

        <div class="form-group">
          <label for="explanation">Explanation (optional)</label>
          <textarea
            id="explanation"
            v-model="form.explanation"
            rows="2"
            placeholder="Explain why the answer is correct..."
          ></textarea>
        </div>

        <div class="dialog-actions">
          <button type="button" @click="$emit('close')" class="button">Cancel</button>
          <button type="submit" class="button primary" :disabled="saving">
            {{ saving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script>
export default {
  name: 'QuestionForm',
  props: {
    question: { type: Object, default: null }
  },
  data() {
    return {
      saving: false,
      correctAnswerIndex: 0,
      form: {
        text: '',
        explanation: '',
        difficulty: '',
        answers: [
          { text: '', is_correct: false },
          { text: '', is_correct: false },
          { text: '', is_correct: false },
          { text: '', is_correct: false }
        ]
      }
    };
  },
  mounted() {
    if (this.question) {
      this.form.text = this.question.text;
      this.form.explanation = this.question.explanation || '';
      this.form.difficulty = this.question.difficulty || '';

      if (this.question.answers && this.question.answers.length === 4) {
        this.form.answers = this.question.answers.map(a => ({
          text: a.text,
          is_correct: a.is_correct
        }));
        this.correctAnswerIndex = this.form.answers.findIndex(a => a.is_correct);
        if (this.correctAnswerIndex < 0) this.correctAnswerIndex = 0;
      }
    }
  },
  methods: {
    save() {
      this.saving = true;
      this.form.answers.forEach((answer, index) => {
        answer.is_correct = (index === this.correctAnswerIndex);
      });
      this.$emit('save', {
        text: this.form.text,
        explanation: this.form.explanation || null,
        difficulty: this.form.difficulty || null,
        answers: this.form.answers
      });
      this.saving = false;
    }
  }
};
</script>

<style scoped>
.dialog-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
}

.dialog-content {
  background: var(--color-main-background);
  border-radius: 8px;
  padding: 24px;
  width: 90%;
  max-width: 600px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 4px 16px rgba(0,0,0,0.2);
}

.dialog-content h3 { margin: 0 0 20px 0; font-size: 20px; font-weight: 600; }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px; }
.form-group textarea, .form-group input[type="text"] {
  width: 100%;
  padding: 10px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  font-size: 14px;
  box-sizing: border-box;
}

.form-group select {
  width: 100%;
  padding: 10px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  font-size: 14px;
}

.answers-form { display: flex; flex-direction: column; gap: 10px; }

.answer-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.answer-row input[type="radio"] {
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  cursor: pointer;
}

.answer-row input[type="text"] { flex: 1; }

.dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 20px;
}

.button {
  padding: 10px 20px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  cursor: pointer;
  font-size: 14px;
  min-height: 44px;
}

.button:hover { background: var(--color-background-hover); }
.button.primary { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.button:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 480px) {
  .dialog-content { padding: 16px; }
  .answer-row { gap: 8px; }
}
</style>
