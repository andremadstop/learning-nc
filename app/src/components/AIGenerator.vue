<template>
  <div class="ai-generator-overlay" @click.self="$emit('close')">
    <div class="ai-generator" role="dialog" :aria-label="t('learning', 'AI Question Generator')">
      <div class="ai-header">
        <h3>{{ t('learning', 'Generate Questions with AI') }}</h3>
        <button class="close-btn" @click="$emit('close')">&times;</button>
      </div>

      <!-- Step 1: Input -->
      <div v-if="step === 'input'" class="ai-input-step">
        <div class="form-group">
          <label>{{ t('learning', 'Source Text') }}</label>
          <textarea v-model="inputText" rows="10" class="nc-input ai-textarea"
            :placeholder="t('learning', 'Paste your text here (lecture notes, textbook excerpt, etc.)...')"
            :maxlength="50000" />
          <span class="char-count">{{ inputText.length }} / 50,000</span>
        </div>
        <div class="ai-options">
          <div class="form-group">
            <label>{{ t('learning', 'Number of Questions') }}</label>
            <input type="range" v-model.number="questionCount" min="5" max="30" step="1" class="ai-slider" />
            <span class="slider-value">{{ questionCount }}</span>
          </div>
          <div class="form-group">
            <label>{{ t('learning', 'Language') }}</label>
            <select v-model="language" class="nc-input ai-select">
              <option value="de">Deutsch</option>
              <option value="en">English</option>
              <option value="fr">Français</option>
              <option value="es">Español</option>
            </select>
          </div>
        </div>
        <div class="ai-actions">
          <NcButton type="tertiary" @click="$emit('close')">{{ t('learning', 'Cancel') }}</NcButton>
          <NcButton type="primary" :disabled="inputText.trim().length < 50 || generating"
            @click="startGeneration">
            {{ generating ? t('learning', 'Generating...') : t('learning', 'Generate Questions') }}
          </NcButton>
        </div>
      </div>

      <!-- Step 2: Generating -->
      <div v-if="step === 'generating'" class="ai-generating-step">
        <NcLoadingIcon :size="44" />
        <p class="generating-text">{{ t('learning', 'AI is generating {n} questions...', { n: questionCount }) }}</p>
        <p class="generating-hint">{{ t('learning', 'This may take 10-30 seconds depending on the AI provider.') }}</p>
      </div>

      <!-- Step 3: Preview -->
      <div v-if="step === 'preview'" class="ai-preview-step">
        <div class="preview-header">
          <h4>{{ t('learning', '{n} questions generated', { n: generatedQuestions.length }) }}</h4>
          <div class="preview-actions-top">
            <NcButton type="tertiary" @click="toggleSelectAll">
              {{ allSelected ? t('learning', 'Deselect All') : t('learning', 'Select All') }}
            </NcButton>
          </div>
        </div>

        <div class="preview-list">
          <div v-for="(q, idx) in generatedQuestions" :key="idx" class="preview-question"
            :class="{ deselected: !q._selected }">
            <div class="preview-q-header">
              <input type="checkbox" v-model="q._selected" :id="'q-sel-' + idx" />
              <label :for="'q-sel-' + idx" class="preview-q-number">Q{{ idx + 1 }}</label>
              <span class="difficulty-badge" :class="q.difficulty">{{ q.difficulty }}</span>
              <button class="preview-edit-btn" @click="editingIndex = editingIndex === idx ? null : idx">
                {{ editingIndex === idx ? t('learning', 'Done') : t('learning', 'Edit') }}
              </button>
            </div>

            <template v-if="editingIndex === idx">
              <textarea v-model="q.text" rows="2" class="nc-input preview-edit-input" />
              <div v-for="(a, aidx) in q.answers" :key="aidx" class="preview-edit-answer">
                <input type="radio" :name="'correct-' + idx" :checked="a.is_correct"
                  @change="setCorrectAnswer(idx, aidx)" />
                <input v-model="a.text" class="nc-input preview-edit-answer-input" />
              </div>
              <textarea v-model="q.explanation" rows="1" class="nc-input preview-edit-input"
                :placeholder="t('learning', 'Explanation...')" />
            </template>

            <template v-else>
              <div class="preview-q-text">{{ q.text }}</div>
              <div class="preview-answers">
                <div v-for="(a, aidx) in q.answers" :key="aidx"
                  class="preview-answer" :class="{ correct: a.is_correct }">
                  {{ a.is_correct ? '✓' : '○' }} {{ a.text }}
                </div>
              </div>
              <div v-if="q.explanation" class="preview-explanation">{{ q.explanation }}</div>
            </template>
          </div>
        </div>

        <div class="ai-actions">
          <NcButton type="tertiary" @click="step = 'input'">{{ t('learning', 'Back') }}</NcButton>
          <NcButton type="primary" :disabled="selectedCount === 0 || importing"
            @click="importQuestions">
            {{ importing ? t('learning', 'Importing...') : t('learning', 'Import {n} Questions', { n: selectedCount }) }}
          </NcButton>
        </div>
      </div>

      <!-- Error state -->
      <NcNoteCard v-if="error" type="error" class="ai-error">{{ error }}</NcNoteCard>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess } from '@nextcloud/dialogs';

export default {
  name: 'AIGenerator',
  components: { NcButton, NcNoteCard, NcLoadingIcon },
  props: {
    poolId: { type: Number, required: true },
  },
  data() {
    return {
      step: 'input',
      inputText: '',
      questionCount: 10,
      language: 'de',
      generating: false,
      generatedQuestions: [],
      editingIndex: null,
      importing: false,
      error: '',
      taskId: null,
      pollTimer: null,
    };
  },
  computed: {
    selectedCount() {
      return this.generatedQuestions.filter(q => q._selected).length;
    },
    allSelected() {
      return this.generatedQuestions.length > 0 && this.generatedQuestions.every(q => q._selected);
    },
  },
  beforeUnmount() {
    if (this.pollTimer) clearTimeout(this.pollTimer);
  },
  methods: {
    async startGeneration() {
      this.generating = true;
      this.error = '';
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/ai/generate'), {
          poolId: this.poolId,
          text: this.inputText,
          count: this.questionCount,
          lang: this.language,
        });
        this.taskId = r.data.taskId;
        this.step = 'generating';
        this.pollStatus();
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Failed to start generation');
        this.generating = false;
      }
    },

    async pollStatus() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/ai/status/{taskId}', { taskId: this.taskId }));
        const data = r.data;

        if (data.status === 'completed') {
          this.generatedQuestions = (data.questions || []).map(q => ({ ...q, _selected: true }));
          if (this.generatedQuestions.length === 0) {
            this.error = t('learning', 'AI returned no valid questions. Try different text or fewer questions.');
            this.step = 'input';
          } else {
            this.step = 'preview';
          }
          this.generating = false;
        } else if (data.status === 'failed') {
          this.error = data.error || t('learning', 'AI generation failed');
          this.step = 'input';
          this.generating = false;
        } else {
          // Still running — poll again in 3s
          this.pollTimer = setTimeout(() => this.pollStatus(), 3000);
        }
      } catch (e) {
        this.error = t('learning', 'Failed to check generation status');
        this.step = 'input';
        this.generating = false;
      }
    },

    toggleSelectAll() {
      const newState = !this.allSelected;
      this.generatedQuestions.forEach(q => { q._selected = newState; });
    },

    setCorrectAnswer(qIdx, aIdx) {
      this.generatedQuestions[qIdx].answers.forEach((a, i) => { a.is_correct = i === aIdx; });
    },

    async importQuestions() {
      this.importing = true;
      this.error = '';
      try {
        const selected = this.generatedQuestions
          .filter(q => q._selected)
          .map(({ _selected, ...q }) => q);

        const r = await axios.post(
          generateUrl('/apps/learning/api/ai/import/{taskId}', { taskId: this.taskId }),
          { poolId: this.poolId, questions: selected }
        );

        showSuccess(t('learning', '{n} questions imported', { n: r.data.imported }));
        this.$emit('imported');
        this.$emit('close');
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Failed to import questions');
      } finally {
        this.importing = false;
      }
    },
  },
};
</script>

<style scoped>
.ai-generator-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.ai-generator {
  background: var(--color-main-background);
  border-radius: 16px;
  padding: 24px 28px;
  max-width: 800px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
}
.ai-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}
.ai-header h3 { margin: 0; font-size: 20px; font-weight: 700; color: var(--color-main-text); }
.close-btn {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  color: var(--color-text-maxcontrast);
  padding: 4px 8px;
}
.close-btn:hover { color: var(--color-main-text); }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; color: var(--color-main-text); }
.ai-textarea { min-height: 200px; resize: vertical; font-family: inherit; }
.char-count { font-size: 12px; color: var(--color-text-maxcontrast); text-align: right; display: block; }

.ai-options { display: flex; gap: 24px; flex-wrap: wrap; }
.ai-slider { width: 200px; }
.slider-value { font-weight: 700; font-size: 16px; color: var(--color-primary-element); margin-left: 8px; }
.ai-select { width: 160px; padding: 8px 12px; }

.ai-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }

.ai-generating-step { text-align: center; padding: 60px 20px; }
.generating-text { font-size: 18px; font-weight: 600; margin-top: 16px; color: var(--color-main-text); }
.generating-hint { font-size: 14px; color: var(--color-text-maxcontrast); }

.preview-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.preview-header h4 { margin: 0; font-size: 16px; font-weight: 600; color: var(--color-main-text); }

.preview-list { display: grid; gap: 12px; max-height: 50vh; overflow-y: auto; }
.preview-question {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 14px 16px;
  background: var(--color-main-background);
  transition: opacity 0.2s;
}
.preview-question.deselected { opacity: 0.5; }
.preview-q-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.preview-q-number { font-weight: 700; color: var(--color-primary-element); font-size: 13px; cursor: pointer; }
.preview-edit-btn {
  margin-left: auto;
  background: none;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  padding: 2px 10px;
  font-size: 12px;
  cursor: pointer;
  color: var(--color-primary-element);
}
.preview-q-text { font-size: 14px; font-weight: 500; margin-bottom: 8px; color: var(--color-main-text); }
.preview-answers { display: grid; gap: 4px; }
.preview-answer { font-size: 13px; padding: 4px 8px; border-radius: 4px; color: var(--color-text-maxcontrast); }
.preview-answer.correct { color: var(--color-success); font-weight: 600; }
.preview-explanation { font-size: 12px; color: var(--color-text-maxcontrast); margin-top: 6px; font-style: italic; }

.preview-edit-input { margin-bottom: 6px; font-size: 13px; }
.preview-edit-answer { display: flex; gap: 8px; align-items: center; margin-bottom: 4px; }
.preview-edit-answer-input { flex: 1; font-size: 13px; }

.nc-input { width: 100%; padding: 10px 12px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); font-size: 14px; background: var(--color-main-background); color: var(--color-main-text); box-sizing: border-box; }
.nc-input:focus { border-color: var(--color-primary-element); outline: none; }

.difficulty-badge { padding: 2px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.difficulty-badge.easy { background: color-mix(in srgb, var(--color-success) 15%, transparent); color: var(--color-success); }
.difficulty-badge.medium { background: color-mix(in srgb, var(--color-warning) 15%, transparent); color: var(--color-warning); }
.difficulty-badge.hard { background: color-mix(in srgb, var(--color-error) 15%, transparent); color: var(--color-error); }

.ai-error { margin-top: 16px; }

@media (max-width: 768px) {
  .ai-generator { padding: 16px 18px; }
  .ai-options { flex-direction: column; }
}
</style>
