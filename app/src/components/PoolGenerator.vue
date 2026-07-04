<template>
  <div class="pool-generator-overlay" @click.self="$emit('close')">
    <div class="pool-generator" role="dialog" :aria-label="t('learning', 'Pool Generator')">
      <div class="pg-header">
        <h3>{{ t('learning', 'Generate Question Pool') }}</h3>
        <button class="close-btn" @click="$emit('close')">&times;</button>
      </div>

      <!-- Provider info -->
      <div v-if="providerInfo" class="pg-provider-info">
        <span class="pg-provider-label">
          {{ t('learning', 'AI Provider:') }}
          <strong>{{ providerLabel }}</strong>
        </span>
        <NcNoteCard v-if="providerInfo.provider === 'ollama'" type="warning" class="pg-quality-hint">
          {{ t('learning', 'Local AI generates simpler questions. For best results use the cloud variant.') }}
        </NcNoteCard>
        <NcNoteCard v-if="!providerInfo.available" type="error" class="pg-quality-hint">
          {{ t('learning', 'No AI provider is configured. Ask your admin to set up Gemini or Ollama in the admin settings.') }}
        </NcNoteCard>
      </div>

      <!-- Tabs -->
      <div class="pg-tabs" role="tablist">
        <button
          @click="activeTab = 'text'"
          :class="['pg-tab', { active: activeTab === 'text' }]"
          role="tab"
          :aria-selected="activeTab === 'text' ? 'true' : 'false'"
        >
          {{ t('learning', 'Paste Text') }}
        </button>
        <button
          @click="activeTab = 'file'"
          :class="['pg-tab', { active: activeTab === 'file' }]"
          role="tab"
          :aria-selected="activeTab === 'file' ? 'true' : 'false'"
        >
          {{ t('learning', 'Upload File') }}
        </button>
        <button
          @click="activeTab = 'import'"
          :class="['pg-tab', { active: activeTab === 'import' }]"
          role="tab"
          :aria-selected="activeTab === 'import' ? 'true' : 'false'"
        >
          {{ t('learning', 'CSV/JSON Import') }}
        </button>
      </div>

      <!-- Step: Input -->
      <template v-if="step === 'input'">
        <!-- Tab: Paste Text -->
        <div v-if="activeTab === 'text'" class="pg-tab-content">
          <div class="form-group">
            <label>{{ t('learning', 'Source Text') }}</label>
            <textarea
              v-model="inputText"
              rows="10"
              class="nc-input pg-textarea"
              :placeholder="t('learning', 'Paste your learning material here (lecture notes, textbook excerpt, etc.)...')"
              :maxlength="100000"
            />
            <span class="char-count">{{ inputText.length }} / 100,000</span>
          </div>
          <div class="pg-options">
            <div class="form-group">
              <label>{{ t('learning', 'Number of Questions') }}</label>
              <input type="range" v-model.number="questionCount" min="5" max="30" step="1" class="pg-slider" />
              <span class="slider-value">{{ questionCount }}</span>
            </div>
          </div>
          <div class="pg-actions">
            <NcButton type="tertiary" @click="$emit('close')">{{ t('learning', 'Cancel') }}</NcButton>
            <NcButton
              type="primary"
              :disabled="inputText.trim().length < 50 || generating || !providerAvailable"
              @click="generateFromText"
            >
              {{ generating ? t('learning', 'Generating...') : t('learning', 'Generate Questions') }}
            </NcButton>
          </div>
        </div>

        <!-- Tab: File Upload -->
        <div v-if="activeTab === 'file'" class="pg-tab-content">
          <div class="form-group">
            <label>{{ t('learning', 'Nextcloud File Path') }}</label>
            <input
              v-model="filePath"
              type="text"
              class="nc-input"
              :placeholder="t('learning', 'e.g., Documents/CompTIA/chapter3.pdf')"
            />
            <p class="pg-file-hint">{{ t('learning', 'Enter the path to a PDF, Markdown, or text file in your Nextcloud files.') }}</p>
          </div>
          <div class="pg-options">
            <div class="form-group">
              <label>{{ t('learning', 'Number of Questions') }}</label>
              <input type="range" v-model.number="questionCount" min="5" max="30" step="1" class="pg-slider" />
              <span class="slider-value">{{ questionCount }}</span>
            </div>
          </div>
          <div class="pg-actions">
            <NcButton type="tertiary" @click="$emit('close')">{{ t('learning', 'Cancel') }}</NcButton>
            <NcButton
              type="primary"
              :disabled="!filePath.trim() || generating || !providerAvailable"
              @click="generateFromFile"
            >
              {{ generating ? t('learning', 'Generating...') : t('learning', 'Generate Questions') }}
            </NcButton>
          </div>
        </div>

        <!-- Tab: CSV/JSON Import -->
        <div v-if="activeTab === 'import'" class="pg-tab-content">
          <NcNoteCard type="info">
            {{ t('learning', 'To import questions from CSV or JSON, create a pool first, then use the import function inside the pool view.') }}
          </NcNoteCard>
          <div class="pg-actions">
            <NcButton type="tertiary" @click="$emit('close')">{{ t('learning', 'Close') }}</NcButton>
          </div>
        </div>
      </template>

      <!-- Step: Generating -->
      <div v-if="step === 'generating'" class="pg-generating">
        <NcLoadingIcon :size="44" />
        <p class="pg-generating-text">
          {{ t('learning', 'AI is generating {n} questions...', { n: questionCount }) }}
        </p>
        <p class="pg-generating-hint">
          {{ t('learning', 'This may take 15-60 seconds depending on the AI provider and text length.') }}
        </p>
      </div>

      <!-- Step: Review -->
      <div v-if="step === 'review'" class="pg-review-step">
        <div class="form-group">
          <label for="pg-pool-title">{{ t('learning', 'Pool Title') }} *</label>
          <input
            id="pg-pool-title"
            v-model="poolTitle"
            type="text"
            class="nc-input"
            :placeholder="t('learning', 'e.g., CompTIA Network+ Chapter 3')"
          />
        </div>

        <PoolDraftReview
          :questions="draftQuestions"
          @update:questions="draftQuestions = $event"
        />

        <div class="pg-actions">
          <NcButton type="tertiary" @click="backToInput">{{ t('learning', 'Back') }}</NcButton>
          <NcButton
            type="primary"
            :disabled="acceptedCount === 0 || !poolTitle.trim() || creating"
            @click="createPool"
          >
            {{ creating
              ? t('learning', 'Creating...')
              : t('learning', 'Create Pool ({n} questions)', { n: acceptedCount })
            }}
          </NcButton>
        </div>
      </div>

      <!-- Error -->
      <NcNoteCard v-if="error" type="error" class="pg-error">{{ error }}</NcNoteCard>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon';
import PoolDraftReview from './PoolDraftReview.vue';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess } from '@nextcloud/dialogs';

export default {
  name: 'PoolGenerator',
  components: { NcButton, NcNoteCard, NcLoadingIcon, PoolDraftReview },
  emits: ['close', 'created'],
  data() {
    return {
      activeTab: 'text',
      step: 'input',
      // Provider
      providerInfo: null,
      // Text input
      inputText: '',
      filePath: '',
      questionCount: 15,
      // Generation
      generating: false,
      draftQuestions: [],
      // Pool creation
      poolTitle: '',
      creating: false,
      // UI
      error: '',
    };
  },
  computed: {
    providerAvailable() {
      return this.providerInfo?.available === true;
    },
    providerLabel() {
      if (!this.providerInfo) return '...';
      const labels = {
        gemini: 'Gemini Cloud',
        ollama: t('learning', 'Local (Ollama)'),
        disabled: t('learning', 'Disabled'),
      };
      return labels[this.providerInfo.provider] || this.providerInfo.provider;
    },
    acceptedCount() {
      return this.draftQuestions.filter(q => q._status === 'accepted').length;
    },
  },
  mounted() {
    this.loadProviderInfo();
  },
  methods: {
    async loadProviderInfo() {
      try {
        const r = await axios.get(generateUrl('/apps/learning/api/pool-generator/provider-info'));
        this.providerInfo = r.data;
      } catch (e) {
        this.providerInfo = { provider: 'unknown', available: false };
      }
    },

    async generateFromText() {
      this.generating = true;
      this.error = '';
      this.step = 'generating';
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/pool-generator/from-text'), {
          text: this.inputText,
          questionCount: this.questionCount,
        });
        this.handleGenerationResult(r.data);
      } catch (e) {
        this.error = e.response?.data?.consent_required
          ? t('learning', 'AI consent required. Open the VirtuProf assistant (bottom right) and agree to AI use, then try again.')
          : (e.response?.data?.error || t('learning', 'Generation failed'));
        this.step = 'input';
      } finally {
        this.generating = false;
      }
    },

    async generateFromFile() {
      this.generating = true;
      this.error = '';
      this.step = 'generating';
      try {
        const r = await axios.post(generateUrl('/apps/learning/api/pool-generator/from-file'), {
          filePath: this.filePath,
          questionCount: this.questionCount,
        });
        this.handleGenerationResult(r.data);
      } catch (e) {
        this.error = e.response?.data?.consent_required
          ? t('learning', 'AI consent required. Open the VirtuProf assistant (bottom right) and agree to AI use, then try again.')
          : (e.response?.data?.error || t('learning', 'Generation failed'));
        this.step = 'input';
      } finally {
        this.generating = false;
      }
    },

    handleGenerationResult(data) {
      const questions = (data.questions || []).map(q => ({
        ...q,
        _status: 'accepted',
      }));

      if (questions.length === 0) {
        this.error = t('learning', 'AI returned no valid questions. Try different text or fewer questions.');
        this.step = 'input';
        return;
      }

      this.draftQuestions = questions;
      this.step = 'review';
    },

    backToInput() {
      this.step = 'input';
      this.error = '';
    },

    async createPool() {
      this.creating = true;
      this.error = '';
      try {
        const accepted = this.draftQuestions
          .filter(q => q._status === 'accepted')
          .map(({ _status, ...q }) => q);

        const r = await axios.post(generateUrl('/apps/learning/api/pool-generator/create-pool'), {
          title: this.poolTitle,
          questions: accepted,
        });

        showSuccess(t('learning', 'Pool created with {n} questions', { n: r.data.imported }));
        this.$emit('created');
        this.$emit('close');
      } catch (e) {
        this.error = e.response?.data?.error || t('learning', 'Failed to create pool');
      } finally {
        this.creating = false;
      }
    },
  },
};
</script>

<style scoped>
.pool-generator-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.pool-generator {
  background: var(--color-main-background);
  border-radius: 16px;
  padding: 24px 28px;
  max-width: 860px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
}

.pg-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}
.pg-header h3 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: var(--color-main-text);
}
.close-btn {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  color: var(--color-text-maxcontrast);
  padding: 4px 8px;
}
.close-btn:hover {
  color: var(--color-main-text);
}

.pg-provider-info {
  margin-bottom: 16px;
}
.pg-provider-label {
  font-size: 13px;
  color: var(--color-text-maxcontrast);
}
.pg-quality-hint {
  margin-top: 8px;
}

.pg-tabs {
  display: flex;
  gap: 2px;
  margin-bottom: 20px;
  border-bottom: 2px solid var(--color-border);
}
.pg-tab {
  background: none;
  border: none;
  padding: 10px 18px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  color: var(--color-text-maxcontrast);
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color 0.15s, border-color 0.15s;
}
.pg-tab:hover {
  color: var(--color-main-text);
}
.pg-tab.active {
  color: var(--color-primary-element);
  border-bottom-color: var(--color-primary-element);
}

.pg-tab-content {
  padding-top: 4px;
}

.form-group {
  margin-bottom: 16px;
}
.form-group label {
  display: block;
  margin-bottom: 6px;
  font-weight: 500;
  font-size: 14px;
  color: var(--color-main-text);
}
.pg-textarea {
  min-height: 200px;
  resize: vertical;
  font-family: inherit;
}
.char-count {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  text-align: right;
  display: block;
}
.pg-file-hint {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
  margin-top: 4px;
}

.pg-options {
  display: flex;
  gap: 24px;
  flex-wrap: wrap;
}
.pg-slider {
  width: 200px;
}
.slider-value {
  font-weight: 700;
  font-size: 16px;
  color: var(--color-primary-element);
  margin-left: 8px;
}

.pg-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 20px;
}

.pg-generating {
  text-align: center;
  padding: 60px 20px;
}
.pg-generating-text {
  font-size: 18px;
  font-weight: 600;
  margin-top: 16px;
  color: var(--color-main-text);
}
.pg-generating-hint {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
}

.pg-review-step {
  padding-top: 4px;
}

.pg-error {
  margin-top: 16px;
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

@media (max-width: 768px) {
  .pool-generator {
    padding: 16px 18px;
  }
  .pg-options {
    flex-direction: column;
  }
}
</style>
