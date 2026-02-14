<template>
  <NcDialog :name="question ? t('learning', 'Edit Question') : t('learning', 'Create Question')" @closing="$emit('close')" size="normal">
    <form @submit.prevent="save">
      <div class="form-group">
        <label for="question-text">{{ t('learning', 'Question *') }}</label>
        <textarea id="question-text" v-model="form.text" required rows="3" :placeholder="t('learning', 'Enter your question...')" class="nc-input"></textarea>
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
        <label>{{ t('learning', 'Answers (select the correct one) *') }}</label>
        <div class="answers-form">
          <div v-for="(answer, index) in form.answers" :key="index" class="answer-row">
            <NcCheckboxRadioSwitch :checked="correctAnswerIndex === index" @update:checked="correctAnswerIndex = index" type="radio" name="correct-answer" />
            <input type="text" v-model="answer.text" :placeholder="t('learning', 'Answer {n}', { n: index + 1 })" required class="nc-input" />
          </div>
        </div>
      </div>
      <div class="form-group">
        <label for="explanation">{{ t('learning', 'Explanation (optional)') }}</label>
        <textarea id="explanation" v-model="form.explanation" rows="2" :placeholder="t('learning', 'Explain why the answer is correct...')" class="nc-input"></textarea>
      </div>
      <div class="dialog-actions">
        <NcButton type="tertiary" @click="$emit('close')">{{ t('learning', 'Cancel') }}</NcButton>
        <NcButton type="primary" native-type="submit" :disabled="saving">{{ saving ? t('learning', 'Saving...') : t('learning', 'Save') }}</NcButton>
      </div>
    </form>
  </NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

export default {
  name: 'QuestionForm',
  components: { NcDialog, NcButton, NcCheckboxRadioSwitch },
  props: { question: { type: Object, default: null } },
  data() {
    return {
      saving: false,
      correctAnswerIndex: 0,
      imageFile: null,
      imagePreview: null,
      existingImagePath: null,
      removeExistingImage: false,
      form: {
        text: '', explanation: '', difficulty: '',
        answers: [
          { text: '', is_correct: false }, { text: '', is_correct: false },
          { text: '', is_correct: false }, { text: '', is_correct: false }
        ]
      }
    };
  },
  computed: {
    existingImageUrl() {
      if (!this.question || !this.existingImagePath) return '';
      return generateUrl('/apps/learning/api/questions/' + this.question.id + '/image');
    }
  },
  mounted() {
    if (this.question) {
      this.form.text = this.question.text;
      this.form.explanation = this.question.explanation || '';
      this.form.difficulty = this.question.difficulty || '';
      this.existingImagePath = this.question.image_path || null;
      if (this.question.answers && this.question.answers.length === 4) {
        this.form.answers = this.question.answers.map(a => ({ text: a.text, is_correct: a.is_correct }));
        this.correctAnswerIndex = this.form.answers.findIndex(a => a.is_correct);
        if (this.correctAnswerIndex < 0) this.correctAnswerIndex = 0;
      }
    }
  },
  methods: {
    onImageSelected(event) {
      const file = event.target.files[0];
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) {
        alert(t('learning', 'Image too large (max 5MB)'));
        return;
      }
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
    save() {
      this.saving = true;
      this.form.answers.forEach((answer, index) => { answer.is_correct = (index === this.correctAnswerIndex); });
      this.$emit('save', {
        text: this.form.text,
        explanation: this.form.explanation || null,
        difficulty: this.form.difficulty || null,
        answers: this.form.answers,
        imageFile: this.imageFile,
        removeImage: this.removeExistingImage,
      });
      this.saving = false;
    }
  },
  beforeDestroy() {
    if (this.imagePreview) {
      URL.revokeObjectURL(this.imagePreview);
    }
  }
};
</script>

<style scoped>
.form-group { margin-bottom: 18px; }
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
</style>
