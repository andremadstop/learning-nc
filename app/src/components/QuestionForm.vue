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

        <div class="form-row">
          <div class="form-group">
            <label for="difficulty">Difficulty</label>
            <select id="difficulty" v-model="form.difficulty">
              <option value="">None</option>
              <option value="easy">Easy</option>
              <option value="medium">Medium</option>
              <option value="hard">Hard</option>
            </select>
          </div>
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
    question: {
      type: Object,
      default: null
    }
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
      }
    }
  },
  methods: {
    save() {
      this.saving = true;
      
      // Set correct answer
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
.question-form {
  max-width: 600px;
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.answers-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.answer-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.answer-row input[type="radio"] {
  flex-shrink: 0;
  width: 20px;
  height: 20px;
  cursor: pointer;
}

.answer-row input[type="text"] {
  flex: 1;
  padding: 8px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
}

select {
  width: 100%;
  padding: 8px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-main-background);
}
</style>
