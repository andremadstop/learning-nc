<template>
  <div class="draft-review">
    <div class="draft-review__header">
      <div class="draft-review__title-row">
        <h4>{{ t('learning', '{n} questions generated', { n: questions.length }) }}</h4>
        <span class="draft-review__counter">
          {{ t('learning', '{accepted} of {total} accepted', { accepted: acceptedCount, total: questions.length }) }}
        </span>
      </div>
      <div class="draft-review__bulk-actions">
        <NcButton type="tertiary" @click="toggleAll">
          {{ allAccepted ? t('learning', 'Deselect All') : t('learning', 'Select All') }}
        </NcButton>
      </div>
    </div>

    <div class="draft-review__list">
      <div
        v-for="(q, idx) in questions"
        :key="idx"
        class="draft-card"
        :class="{ rejected: q._status === 'rejected' }"
      >
        <div class="draft-card__header">
          <input
            type="checkbox"
            :checked="q._status === 'accepted'"
            :id="'draft-' + idx"
            @change="toggleStatus(idx)"
          />
          <label :for="'draft-' + idx" class="draft-card__number">Q{{ idx + 1 }}</label>
          <span class="difficulty-badge" :class="q.difficulty">{{ q.difficulty }}</span>
          <div class="draft-card__actions">
            <button
              class="draft-action-btn"
              :class="{ active: editingIndex === idx }"
              :title="t('learning', 'Edit')"
              @click="editingIndex = editingIndex === idx ? null : idx"
            >
              {{ editingIndex === idx ? t('learning', 'Done') : t('learning', 'Edit') }}
            </button>
            <button
              class="draft-action-btn delete"
              :title="t('learning', 'Delete')"
              @click="deleteQuestion(idx)"
            >
              &times;
            </button>
          </div>
        </div>

        <!-- Edit mode -->
        <template v-if="editingIndex === idx">
          <textarea v-model="q.text" rows="2" class="nc-input draft-edit-input" />
          <div v-for="(a, aidx) in q.answers" :key="aidx" class="draft-edit-answer">
            <input
              type="radio"
              :name="'correct-' + idx"
              :checked="a.is_correct"
              @change="setCorrectAnswer(idx, aidx)"
            />
            <input v-model="a.text" class="nc-input draft-edit-answer-input" />
          </div>
          <textarea
            v-model="q.explanation"
            rows="1"
            class="nc-input draft-edit-input"
            :placeholder="t('learning', 'Explanation...')"
          />
        </template>

        <!-- View mode -->
        <template v-else>
          <div class="draft-card__text">{{ q.text }}</div>
          <div class="draft-card__answers">
            <div
              v-for="(a, aidx) in q.answers"
              :key="aidx"
              class="draft-answer"
              :class="{ correct: a.is_correct }"
            >
              {{ a.is_correct ? '\u2713' : '\u25CB' }} {{ a.text }}
            </div>
          </div>
          <div v-if="q.explanation" class="draft-card__explanation">{{ q.explanation }}</div>

          <!-- Source chunk (collapsible) -->
          <details v-if="q.source_chunk" class="draft-card__source">
            <summary>{{ t('learning', 'Source') }}</summary>
            <p>{{ q.source_chunk }}</p>
          </details>
        </template>
      </div>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton';

export default {
  name: 'PoolDraftReview',
  components: { NcButton },
  props: {
    questions: {
      type: Array,
      required: true,
    },
  },
  emits: ['update:questions'],
  data() {
    return {
      editingIndex: null,
    };
  },
  computed: {
    acceptedCount() {
      return this.questions.filter(q => q._status === 'accepted').length;
    },
    allAccepted() {
      return this.questions.length > 0 && this.questions.every(q => q._status === 'accepted');
    },
  },
  methods: {
    toggleStatus(idx) {
      const q = this.questions[idx];
      q._status = q._status === 'accepted' ? 'rejected' : 'accepted';
    },
    toggleAll() {
      const newStatus = this.allAccepted ? 'rejected' : 'accepted';
      this.questions.forEach(q => { q._status = newStatus; });
    },
    setCorrectAnswer(qIdx, aIdx) {
      this.questions[qIdx].answers.forEach((a, i) => { a.is_correct = i === aIdx; });
    },
    deleteQuestion(idx) {
      const updated = [...this.questions];
      updated.splice(idx, 1);
      this.$emit('update:questions', updated);
    },
  },
};
</script>

<style scoped>
.draft-review__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  flex-wrap: wrap;
  gap: 8px;
}
.draft-review__title-row {
  display: flex;
  align-items: baseline;
  gap: 12px;
}
.draft-review__title-row h4 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: var(--color-main-text);
}
.draft-review__counter {
  font-size: 13px;
  color: var(--color-text-maxcontrast);
  font-weight: 500;
}

.draft-review__list {
  display: grid;
  gap: 12px;
  max-height: 50vh;
  overflow-y: auto;
}

.draft-card {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 14px 16px;
  background: var(--color-main-background);
  transition: opacity 0.2s;
}
.draft-card.rejected {
  opacity: 0.45;
}

.draft-card__header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}
.draft-card__number {
  font-weight: 700;
  color: var(--color-primary-element);
  font-size: 13px;
  cursor: pointer;
}
.draft-card__actions {
  margin-left: auto;
  display: flex;
  gap: 6px;
}
.draft-action-btn {
  background: none;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  padding: 2px 10px;
  font-size: 12px;
  cursor: pointer;
  color: var(--color-primary-element);
}
.draft-action-btn.active {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
}
.draft-action-btn.delete {
  color: var(--color-error);
  font-size: 16px;
  font-weight: 700;
  padding: 0 8px;
  line-height: 1;
}

.draft-card__text {
  font-size: 14px;
  font-weight: 500;
  margin-bottom: 8px;
  color: var(--color-main-text);
}
.draft-card__answers {
  display: grid;
  gap: 4px;
}
.draft-answer {
  font-size: 13px;
  padding: 4px 8px;
  border-radius: 4px;
  color: var(--color-text-maxcontrast);
}
.draft-answer.correct {
  color: var(--color-success);
  font-weight: 600;
}
.draft-card__explanation {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  margin-top: 6px;
  font-style: italic;
}
.draft-card__source {
  margin-top: 8px;
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}
.draft-card__source summary {
  cursor: pointer;
  font-weight: 500;
}
.draft-card__source p {
  margin: 4px 0 0;
  white-space: pre-wrap;
  font-size: 11px;
  line-height: 1.4;
  max-height: 120px;
  overflow-y: auto;
}

.draft-edit-input {
  margin-bottom: 6px;
  font-size: 13px;
}
.draft-edit-answer {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 4px;
}
.draft-edit-answer-input {
  flex: 1;
  font-size: 13px;
}

.nc-input {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid var(--color-border);
  border-radius: var(--border-radius-large);
  font-size: 14px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  box-sizing: border-box;
}
.nc-input:focus {
  border-color: var(--color-primary-element);
  outline: none;
}

.difficulty-badge {
  padding: 2px 10px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
}
.difficulty-badge.easy {
  background: color-mix(in srgb, var(--color-success) 15%, transparent);
  color: var(--color-success);
}
.difficulty-badge.medium {
  background: color-mix(in srgb, var(--color-warning) 15%, transparent);
  color: var(--color-warning);
}
.difficulty-badge.hard {
  background: color-mix(in srgb, var(--color-error) 15%, transparent);
  color: var(--color-error);
}
</style>
