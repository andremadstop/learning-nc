<template>
  <AccessibleDialog :name="question ? t('learning', 'Edit Question') : t('learning', 'Create Question')" @closing="$emit('close')" size="normal">
    <form @submit.prevent="save">
      <div class="form-group">
        <label for="question-text">{{ t('learning', 'Question') }} <span aria-hidden="true">*</span></label>
        <textarea id="question-text" v-model="form.text" aria-required="true" required rows="3" :placeholder="t('learning', 'Enter your question...')" class="nc-input"></textarea>
      </div>

      <!-- Image Upload -->
      <div class="form-group">
        <label>{{ t('learning', 'Image (optional)') }}</label>
        <div v-if="imagePreview || existingImagePath" class="image-preview-area">
          <img :src="imagePreview || existingImageUrl" alt="Question image" class="image-preview" />
          <NcButton type="error" @click="removeImage" class="remove-image-btn">{{ t('learning', 'Remove Image') }}</NcButton>
        </div>
        <div v-else class="image-upload-area">
          <input type="file" ref="imageInput" accept="image/jpeg,image/png,image/gif,image/webp" @change="onImageSelected" class="image-input" />
          <NcButton type="secondary" @click="$refs.imageInput.click()">{{ t('learning', 'Upload Image') }}</NcButton>
          <span class="upload-hint">{{ t('learning', 'JPEG, PNG, GIF, WebP (max 5MB)') }}</span>
        </div>
        <NcNoteCard v-if="imageError" type="error" class="image-error">{{ imageError }}</NcNoteCard>
      </div>

      <div class="form-group">
        <label for="difficulty">{{ t('learning', 'Difficulty') }}</label>
        <select id="difficulty" v-model="form.difficulty" class="nc-input">
          <option value="">{{ t('learning', 'None') }}</option>
          <option value="easy">{{ t('learning', 'Easy') }}</option>
          <option value="medium">{{ t('learning', 'Medium') }}</option>
          <option value="hard">{{ t('learning', 'Hard') }}</option>
        </select>
      </div>

      <div class="form-group">
        <label for="question-type">{{ t('learning', 'Answer Type') }}</label>
        <select id="question-type" v-model="form.questionType" class="nc-input" @change="onQuestionTypeChange">
          <option value="single">{{ t('learning', 'Single answer') }}</option>
          <option value="multi">{{ t('learning', 'Multiple answers') }}</option>
          <option value="open">{{ t('learning', 'Free text') }}</option>
        </select>
      </div>

      <div class="metadata-grid">
        <div class="form-group">
          <label for="exam-key">{{ t('learning', 'Exam Key (optional)') }}</label>
          <input id="exam-key" v-model.trim="form.examKey" type="text" :placeholder="t('learning', 'e.g., n10-009 or 220-1101')" class="nc-input" />
        </div>
        <div class="form-group">
          <label for="handbook-key">{{ t('learning', 'Handbook Key (optional)') }}</label>
          <input id="handbook-key" v-model.trim="form.handbookKey" type="text" :placeholder="t('learning', 'e.g., kammermann-network-plus-9ed-2024')" class="nc-input" />
        </div>
      </div>

      <div class="form-group">
        <label for="handbook-title">{{ t('learning', 'Handbook Title (optional)') }}</label>
        <input id="handbook-title" v-model.trim="form.handbookTitle" type="text" :placeholder="t('learning', 'e.g., Kammermann CompTIA Network+ (9. Auflage, 2024)')" class="nc-input" />
      </div>

      <div class="metadata-grid">
        <div class="form-group">
          <label for="chapter-key">{{ t('learning', 'Chapter Key (optional)') }}</label>
          <input id="chapter-key" v-model.trim="form.chapterKey" type="text" :placeholder="t('learning', 'e.g., chapter-09-ip')" class="nc-input" />
        </div>
        <div class="form-group">
          <label for="chapter-order">{{ t('learning', 'Chapter Number (optional)') }}</label>
          <input id="chapter-order" v-model.number="form.chapterOrder" type="number" min="1" max="9999" :placeholder="t('learning', 'e.g., 9')" class="nc-input" />
        </div>
      </div>

      <div class="form-group">
        <label for="chapter-title">{{ t('learning', 'Chapter Title (optional)') }}</label>
        <input id="chapter-title" v-model.trim="form.chapterTitle" type="text" :placeholder="t('learning', 'e.g., Mein Name ist IP – Internet Protocol')" class="nc-input" />
      </div>

      <!-- Open-question: single model answer -->
      <div v-if="form.questionType === 'open'" class="form-group">
        <label for="model-answer">{{ t('learning', 'Model answer') }} <span aria-hidden="true">*</span></label>
        <textarea id="model-answer" v-model="modelAnswer" aria-required="true" required rows="2" :placeholder="t('learning', 'The expected correct answer...')" class="nc-input"></textarea>
      </div>

      <!-- MC answers -->
      <div v-else class="form-group">
        <label>{{ form.questionType === 'multi' ? t('learning', 'Answers (select all correct)') : t('learning', 'Answers (select the correct one)') }} <span aria-hidden="true">*</span></label>
        <div class="answers-form">
          <div v-for="(answer, index) in form.answers" :key="index" class="answer-row">
            <NcCheckboxRadioSwitch
              v-if="form.questionType === 'single'"
              :model-value="correctAnswerIndex === index"
              @update:model-value="correctAnswerIndex = index"
              type="radio"
              name="correct-answer"
            />
            <NcCheckboxRadioSwitch
              v-else
              :model-value="correctAnswerIndices.has(index)"
              @update:model-value="toggleCorrectIndex(index)"
              type="checkbox"
            />
            <input type="text" v-model="answer.text" :placeholder="t('learning', 'Answer {n}', { n: index + 1 })" aria-required="true" required class="nc-input" />
            <button v-if="form.answers.length > 2" type="button" class="remove-answer-btn" @click="removeAnswer(index)" :aria-label="t('learning', 'Remove answer')">&#215;</button>
          </div>
        </div>
        <div class="answer-actions">
          <NcButton type="secondary" @click="addAnswer" :disabled="form.answers.length >= 8">
            {{ t('learning', '+ Add Answer') }}
          </NcButton>
        </div>
        <NcNoteCard v-if="multiWarning" type="warning" class="multi-warning">
          {{ t('learning', 'Multi-select questions should have at least 2 correct answers.') }}
        </NcNoteCard>
      </div>
      <div class="form-group">
        <label for="explanation">{{ t('learning', 'Explanation (optional)') }}</label>
        <textarea id="explanation" v-model="form.explanation" rows="2" :placeholder="t('learning', 'Explain why the answer is correct...')" class="nc-input"></textarea>
      </div>
      <!-- PBQ Config Section -->
      <div class="form-group">
        <label for="pbq-subtype">{{ t('learning', 'PBQ Type (optional)') }}</label>
        <select id="pbq-subtype" v-model="form.pbqSubtype" class="nc-input" @change="onPbqSubtypeChange">
          <option value="">{{ t('learning', 'None (standard question)') }}</option>
          <option value="cli">CLI Terminal</option>
          <option value="placement">Device Placement</option>
          <option value="dropdown">Inline Dropdown</option>
          <option value="cable">Cable Mapping</option>
          <option value="multi_panel">Multi-Panel</option>
          <option value="switch_config">Switch Config</option>
          <option value="routing_config">Routing Config</option>
          <option value="diagnostic">Diagnostic Review</option>
        </select>
      </div>

      <div v-if="form.pbqSubtype" class="form-group">
        <label for="pbq-config">{{ t('learning', 'PBQ Config (JSON)') }}</label>
        <div class="pbq-config-actions">
          <NcButton type="secondary" @click="showAuthorTool = true">
            {{ t('learning', 'PBQ Config Builder') }}
          </NcButton>
        </div>
        <textarea
          id="pbq-config"
          v-model="form.pbqConfig"
          @input="pbqConfigManualError = null"
          rows="6"
          :placeholder="t('learning', 'Paste JSON from PBQ Config Builder or enter manually...')"
          class="nc-input pbq-config-textarea"
        ></textarea>
        <NcNoteCard v-if="pbqConfigDisplayError" type="error" class="pbq-config-error">
          {{ pbqConfigDisplayError }}
        </NcNoteCard>
      </div>

      <AccessibleDialog
        v-if="showAuthorTool"
        :name="t('learning', 'PBQ Config Builder')"
        size="large"
        @closing="showAuthorTool = false"
      >
        <PbqAuthorTool
          :initial-subtype="form.pbqSubtype"
          :initial-config="parsedPbqConfigForBuilder"
          @apply="applyPbqBuilderConfig"
        />
      </AccessibleDialog>

      <!-- Instructor Note -->
      <div class="form-group">
        <label for="instructor-note">{{ t('learning', 'Instructor Note (optional)') }}</label>
        <textarea
          id="instructor-note"
          v-model="form.instructorNote"
          rows="3"
          :placeholder="t('learning', 'Private note for instructors...')"
          class="nc-input"
        ></textarea>
        <NcCheckboxRadioSwitch
          :model-value="form.noteVisible"
          @update:model-value="form.noteVisible = $event"
          type="checkbox"
        >
          {{ t('learning', 'Visible to students') }}
        </NcCheckboxRadioSwitch>
      </div>

      <div class="dialog-actions">
        <NcButton type="tertiary" @click="$emit('close')">{{ t('learning', 'Cancel') }}</NcButton>
        <NcButton type="primary" native-type="submit" :disabled="saving">{{ saving ? t('learning', 'Saving...') : t('learning', 'Save') }}</NcButton>
      </div>
    </form>
  </AccessibleDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton';
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import { generateUrl } from '@nextcloud/router';
import PbqAuthorTool from './PbqAuthorTool.vue';
import AccessibleDialog from './AccessibleDialog.vue';

export default {
  name: 'QuestionForm',
  components: { AccessibleDialog, NcButton, NcCheckboxRadioSwitch, NcNoteCard, PbqAuthorTool },
  props: { question: { type: Object, default: null } },
  data() {
    return {
      saving: false,
      correctAnswerIndex: 0,
      correctAnswerIndices: new Set(),
      imageFile: null,
      imageError: null,
      imagePreview: null,
      existingImagePath: null,
      removeExistingImage: false,
      modelAnswer: '',
      pbqConfigManualError: null,
      showAuthorTool: false,
      form: {
        text: '', explanation: '', difficulty: '',
        questionType: 'single',
        answers: [
          { text: '', is_correct: false }, { text: '', is_correct: false },
          { text: '', is_correct: false }, { text: '', is_correct: false }
        ],
        pbqSubtype: '',
        pbqConfig: '',
        instructorNote: '',
        noteVisible: false,
        examKey: '',
        handbookKey: '',
        handbookTitle: '',
        chapterKey: '',
        chapterTitle: '',
        chapterOrder: null,
      }
    };
  },
  computed: {
    existingImageUrl() {
      if (!this.question || !this.existingImagePath) return '';
      return generateUrl('/apps/learning/api/questions/' + this.question.id + '/image');
    },
    multiWarning() {
      if (this.form.questionType !== 'multi') return false;
      return this.correctAnswerIndices.size <= 1;
    },
    parsedPbqConfigForBuilder() {
      if (!this.form.pbqConfig || !this.form.pbqConfig.trim()) {
        return null;
      }
      try {
        const parsed = JSON.parse(this.form.pbqConfig);
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed) && parsed.pbq_config && typeof parsed.pbq_config === 'object') {
          return parsed.pbq_config;
        }
        return parsed;
      } catch (error) {
        return null;
      }
    },
    pbqConfigLiveError() {
      if (!this.form.pbqSubtype || !this.form.pbqConfig || !this.form.pbqConfig.trim()) {
        return '';
      }
      try {
        JSON.parse(this.form.pbqConfig);
        return '';
      } catch (error) {
        return t('learning', 'PBQ Config must be valid JSON.');
      }
    },
    pbqConfigDisplayError() {
      return this.pbqConfigManualError || this.pbqConfigLiveError;
    }
  },
  mounted() {
    if (this.question) {
      this.form.text = this.question.text;
      this.form.explanation = this.question.explanation || '';
      this.form.difficulty = this.question.difficulty || '';
      this.form.questionType = this.question.question_type || 'single';
      this.existingImagePath = this.question.image_path || null;
      if (this.form.questionType === 'open' && this.question.answers && this.question.answers.length >= 1) {
        this.modelAnswer = this.question.answers[0].text || '';
      }
      if (this.question.answers && this.question.answers.length >= 2) {
        this.form.answers = this.question.answers.map(a => ({ text: a.text, is_correct: a.is_correct }));
        // Pad to minimum 4 answer slots for consistency
        while (this.form.answers.length < 4) {
          this.form.answers.push({ text: '', is_correct: false });
        }
        // Initialize correct indices for both modes
        this.correctAnswerIndices = new Set();
        this.form.answers.forEach((a, i) => {
          if (a.is_correct) this.correctAnswerIndices.add(i);
        });
        this.correctAnswerIndex = this.form.answers.findIndex(a => a.is_correct);
        if (this.correctAnswerIndex < 0) this.correctAnswerIndex = 0;
      }
      this.form.pbqSubtype = this.question.pbq_subtype || ''
      this.form.pbqConfig  = this.question.pbq_config
        ? JSON.stringify(this.question.pbq_config, null, 2)
        : ''
      this.form.instructorNote = this.question.instructor_note || ''
      this.form.noteVisible = this.question.note_visible || false
      this.form.examKey = this.question.exam_key || ''
      this.form.handbookKey = this.question.handbook_key || ''
      this.form.handbookTitle = this.question.handbook_title || ''
      this.form.chapterKey = this.question.chapter_key || ''
      this.form.chapterTitle = this.question.chapter_title || ''
      this.form.chapterOrder = this.question.chapter_order || null
    }
  },
  methods: {
    onImageSelected(event) {
      const file = event.target.files[0];
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) {
        this.imageError = t('learning', 'Image too large (max 5MB)');
        return;
      }
      this.imageError = null;
      this.imageFile = file;
      this.imagePreview = URL.createObjectURL(file);
      this.removeExistingImage = false;
    },
    removeImage() {
      if (this.imagePreview) {
        URL.revokeObjectURL(this.imagePreview);
        this.imagePreview = null;
        this.imageFile = null;
      }
      if (this.existingImagePath) {
        this.removeExistingImage = true;
        this.existingImagePath = null;
      }
    },
    toggleCorrectIndex(index) {
      const newSet = new Set(this.correctAnswerIndices);
      if (newSet.has(index)) {
        newSet.delete(index);
      } else {
        newSet.add(index);
      }
      this.correctAnswerIndices = newSet;
    },
    onQuestionTypeChange() {
      if (this.form.questionType === 'open') {
        // No action needed — open mode hides the answer list
        return;
      }
      if (this.form.questionType === 'single') {
        const first = [...this.correctAnswerIndices].sort((a, b) => a - b)[0];
        this.correctAnswerIndex = first !== undefined ? first : 0;
      } else {
        this.correctAnswerIndices = new Set([this.correctAnswerIndex]);
      }
    },
    onPbqSubtypeChange() {
      this.pbqConfigManualError = null;
      if (!this.form.pbqSubtype) {
        this.form.pbqConfig = '';
      }
    },
    addAnswer() {
      if (this.form.answers.length < 8) {
        this.form.answers.push({ text: '', is_correct: false });
      }
    },
    removeAnswer(index) {
      if (this.form.answers.length <= 2) return;
      this.form.answers.splice(index, 1);
      // Update correct answer tracking
      if (this.form.questionType === 'single') {
        if (this.correctAnswerIndex === index) {
          this.correctAnswerIndex = 0;
        } else if (this.correctAnswerIndex > index) {
          this.correctAnswerIndex--;
        }
      } else {
        const newSet = new Set();
        for (const i of this.correctAnswerIndices) {
          if (i < index) newSet.add(i);
          else if (i > index) newSet.add(i - 1);
          // skip i === index (removed)
        }
        this.correctAnswerIndices = newSet;
      }
    },
    applyPbqBuilderConfig(payload) {
      this.form.pbqSubtype = payload.subtype || '';
      this.form.pbqConfig = payload.json || '';
      this.pbqConfigManualError = null;
      this.showAuthorTool = false;
    },
    normalizePbqPayload() {
      const rawSubtype = this.form.pbqSubtype || null;
      const rawConfig = this.form.pbqConfig ? this.form.pbqConfig.trim() : '';

      if (!rawSubtype && !rawConfig) {
        return { pbqSubtype: null, pbqConfig: null, error: null };
      }

      if (!rawSubtype) {
        return {
          pbqSubtype: null,
          pbqConfig: null,
          error: t('learning', 'Select a PBQ Type before saving PBQ Config.'),
        };
      }

      if (!rawConfig) {
        return {
          pbqSubtype: rawSubtype,
          pbqConfig: null,
          error: t('learning', 'PBQ questions require a PBQ Config JSON object.'),
        };
      }

      let parsed;
      try {
        parsed = JSON.parse(rawConfig);
      } catch (error) {
        return {
          pbqSubtype: rawSubtype,
          pbqConfig: null,
          error: t('learning', 'PBQ Config must be valid JSON.'),
        };
      }

      let finalSubtype = rawSubtype;
      let finalConfig = parsed;

      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed) && parsed.pbq_config !== undefined) {
        if (parsed.pbq_subtype && parsed.pbq_subtype !== rawSubtype) {
          return {
            pbqSubtype: rawSubtype,
            pbqConfig: null,
            error: t('learning', 'PBQ Type and pasted builder subtype do not match.'),
          };
        }
        finalSubtype = parsed.pbq_subtype || rawSubtype;
        finalConfig = parsed.pbq_config;
      }

      if (!finalConfig || typeof finalConfig !== 'object' || Array.isArray(finalConfig)) {
        return {
          pbqSubtype: finalSubtype,
          pbqConfig: null,
          error: t('learning', 'PBQ Config must decode to a JSON object.'),
        };
      }

      return {
        pbqSubtype: finalSubtype,
        pbqConfig: finalConfig,
        error: null,
      };
    },
    save() {
      this.saving = true;
      this.pbqConfigManualError = null;
      let filteredAnswers;
      if (this.form.questionType === 'open') {
        filteredAnswers = [{ text: this.modelAnswer, is_correct: true }];
      } else if (this.form.questionType === 'multi') {
        this.form.answers.forEach((answer, index) => {
          answer.is_correct = this.correctAnswerIndices.has(index);
        });
        filteredAnswers = this.form.answers.filter(a => a.text.trim() !== '');
      } else {
        this.form.answers.forEach((answer, index) => {
          answer.is_correct = (index === this.correctAnswerIndex);
        });
        filteredAnswers = this.form.answers.filter(a => a.text.trim() !== '');
      }

      const normalizedPbq = this.normalizePbqPayload();
      if (normalizedPbq.error) {
        this.pbqConfigManualError = normalizedPbq.error;
        this.saving = false;
        return;
      }

      this.$emit('save', {
        text: this.form.text,
        explanation: this.form.explanation || null,
        difficulty: this.form.difficulty || null,
        questionType: this.form.questionType,
        answers: filteredAnswers,
        imageFile: this.imageFile,
        removeImage: this.removeExistingImage,
        pbqSubtype: normalizedPbq.pbqSubtype,
        pbqConfig: normalizedPbq.pbqConfig,
        instructorNote: this.form.instructorNote || null,
        noteVisible: this.form.noteVisible,
        examKey: this.form.examKey || null,
        handbookKey: this.form.handbookKey || null,
        handbookTitle: this.form.handbookTitle || null,
        chapterKey: this.form.chapterKey || null,
        chapterTitle: this.form.chapterTitle || null,
        chapterOrder: this.form.chapterOrder || null,
      });
      this.saving = false;
    }
  },
  beforeUnmount() {
    if (this.imagePreview) {
      URL.revokeObjectURL(this.imagePreview);
    }
  }
};
</script>

<style scoped>
.form-group { margin-bottom: 18px; }
.metadata-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; color: var(--color-main-text); }
.nc-input { width: 100%; padding: 10px 12px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); font-size: 14px; background: var(--color-main-background); color: var(--color-main-text); transition: border-color 0.2s; box-sizing: border-box; }
.nc-input:focus { border-color: var(--color-primary-element); outline: none; }
.answers-form { display: flex; flex-direction: column; gap: 10px; }
.answer-row { display: flex; align-items: center; gap: 10px; }
.answer-row .nc-input { flex: 1; }
.dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 24px; }

/* Image upload */
.image-upload-area { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.image-input { display: none; }
.upload-hint { font-size: 12px; color: var(--color-text-maxcontrast); }

.image-preview-area { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
.image-preview { max-width: 100%; max-height: 200px; border-radius: var(--border-radius-large); border: 1px solid var(--color-border); object-fit: contain; }
.remove-image-btn { align-self: flex-start; }

.answer-actions { margin-top: 8px; }
.remove-answer-btn { background: none; border: none; color: var(--color-text-maxcontrast); font-size: 18px; cursor: pointer; padding: 4px 8px; border-radius: 4px; flex-shrink: 0; }
.remove-answer-btn:hover { color: var(--color-error); background: color-mix(in srgb, var(--color-error) 10%, transparent); }
.image-error { margin-top: 8px; }
.multi-warning { margin-top: 8px; }
.pbq-config-actions { margin-bottom: 8px; }
.pbq-config-textarea { font-family: monospace; font-size: 12px; }
.pbq-config-error { margin-top: 8px; }
</style>
