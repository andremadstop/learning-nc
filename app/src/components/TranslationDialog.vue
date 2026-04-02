<template>
  <AccessibleDialog :name="t('learning', 'Translate Question')" size="large" @closing="$emit('close')">
    <div v-if="loading" class="translation-loading">
      <NcLoadingIcon :size="32" />
      <span>{{ t('learning', 'Loading translations...') }}</span>
    </div>

    <div v-else class="translation-dialog">
      <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

      <div class="original-block">
        <h4>{{ t('learning', 'Original Content') }}</h4>
        <div class="original-question">{{ question.text }}</div>
        <div v-if="question.explanation" class="original-explanation">
          <strong>{{ t('learning', 'Explanation:') }}</strong> {{ question.explanation }}
        </div>
        <div v-if="question.answers && question.answers.length > 0" class="original-answers">
          <div v-for="answer in question.answers" :key="'orig-' + answer.id" class="original-answer">
            <span>{{ answer.text }}</span>
          </div>
        </div>
      </div>

      <div v-for="language in languages" :key="language.key" class="translation-section">
        <h4>{{ language.label }}</h4>

        <div class="form-group">
          <label :for="'question-text-' + language.key">{{ t('learning', 'Question') }}</label>
          <textarea
            :id="'question-text-' + language.key"
            v-model="questionTranslations[language.key].text"
            rows="3"
            class="nc-input"
            :placeholder="t('learning', 'Leave empty to use original text')"
          />
        </div>

        <div class="form-group">
          <label :for="'question-explanation-' + language.key">{{ t('learning', 'Explanation (optional)') }}</label>
          <textarea
            :id="'question-explanation-' + language.key"
            v-model="questionTranslations[language.key].explanation"
            rows="2"
            class="nc-input"
            :placeholder="t('learning', 'Leave empty to use original explanation')"
          />
        </div>

        <div v-if="question.answers && question.answers.length > 0" class="answer-translation-list">
          <div v-for="answer in question.answers" :key="answer.id" class="answer-translation-item">
            <label :for="'answer-' + answer.id + '-' + language.key">
              {{ t('learning', 'Answer') }}: {{ answer.text }}
            </label>
            <input
              :id="'answer-' + answer.id + '-' + language.key"
              v-model="answerTranslations[answer.id][language.key]"
              type="text"
              class="nc-input"
              :placeholder="t('learning', 'Leave empty to use original answer')"
            />
          </div>
        </div>
      </div>
    </div>

    <template #actions>
      <NcButton type="tertiary" @click="$emit('close')">{{ t('learning', 'Cancel') }}</NcButton>
      <NcButton type="primary" :disabled="saving" @click="save">
        {{ saving ? t('learning', 'Saving...') : t('learning', 'Save') }}
      </NcButton>
    </template>
  </AccessibleDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import AccessibleDialog from './AccessibleDialog.vue'

export default {
  name: 'TranslationDialog',
  components: { AccessibleDialog, NcButton, NcLoadingIcon, NcNoteCard },
  props: {
    question: {
      type: Object,
      required: true,
    },
  },
  data() {
    return {
      loading: true,
      saving: false,
      error: '',
      languages: [
        { key: 'de', label: t('learning', 'German (Deutsch)') },
        { key: 'en', label: t('learning', 'English') },
        { key: 'ru', label: t('learning', 'Russian') },
      ],
      questionTranslations: {
        de: { text: '', explanation: '' },
        en: { text: '', explanation: '' },
        ru: { text: '', explanation: '' },
      },
      answerTranslations: {},
      existingQuestionTranslations: {},
      existingAnswerTranslations: {},
    }
  },
  mounted() {
    this.initializeAnswerState()
    this.loadTranslations()
  },
  methods: {
    initializeAnswerState() {
      const answers = Array.isArray(this.question.answers) ? this.question.answers : []
      answers.forEach(answer => {
        this.$set(this.answerTranslations, answer.id, { de: '', en: '', ru: '' })
        this.$set(this.existingAnswerTranslations, answer.id, {})
      })
    },
    async loadTranslations() {
      this.loading = true
      this.error = ''
      try {
        const questionResponse = await axios.get(generateUrl('/apps/learning/api/questions/' + this.question.id + '/translations'))
        for (const translation of questionResponse.data || []) {
          if (!this.questionTranslations[translation.lang]) {
            continue
          }
          this.questionTranslations[translation.lang] = {
            text: translation.text || '',
            explanation: translation.explanation || '',
          }
          this.existingQuestionTranslations[translation.lang] = true
        }

        const answers = Array.isArray(this.question.answers) ? this.question.answers : []
        await Promise.all(answers.map(async answer => {
          const response = await axios.get(generateUrl('/apps/learning/api/answers/' + answer.id + '/translations'))
          for (const translation of response.data || []) {
            if (!this.answerTranslations[answer.id] || this.answerTranslations[answer.id][translation.lang] === undefined) {
              continue
            }
            this.answerTranslations[answer.id][translation.lang] = translation.text || ''
            this.existingAnswerTranslations[answer.id][translation.lang] = true
          }
        }))
      } catch (e) {
        this.error = t('learning', 'Failed to load translations')
      } finally {
        this.loading = false
      }
    },
    async save() {
      this.saving = true
      this.error = ''

      try {
        const requests = []

        for (const language of this.languages) {
          const lang = language.key
          const questionTranslation = this.questionTranslations[lang]
          const questionText = (questionTranslation.text || '').trim()
          const explanation = (questionTranslation.explanation || '').trim()

          if (explanation && !questionText) {
            throw new Error(t('learning', 'Question translation text is required when explanation is set'))
          }

          if (questionText) {
            requests.push(
              axios.put(
                generateUrl('/apps/learning/api/questions/' + this.question.id + '/translations/' + lang),
                { text: questionText, explanation: explanation || null }
              )
            )
          } else if (this.existingQuestionTranslations[lang]) {
            requests.push(
              axios.delete(generateUrl('/apps/learning/api/questions/' + this.question.id + '/translations/' + lang))
            )
          }

          const answers = Array.isArray(this.question.answers) ? this.question.answers : []
          answers.forEach(answer => {
            const translatedText = (this.answerTranslations[answer.id][lang] || '').trim()
            if (translatedText) {
              requests.push(
                axios.put(
                  generateUrl('/apps/learning/api/answers/' + answer.id + '/translations/' + lang),
                  { text: translatedText }
                )
              )
            } else if (this.existingAnswerTranslations[answer.id][lang]) {
              requests.push(
                axios.delete(generateUrl('/apps/learning/api/answers/' + answer.id + '/translations/' + lang))
              )
            }
          })
        }

        await Promise.all(requests)
        showSuccess(t('learning', 'Translations saved'))
        this.$emit('saved')
      } catch (e) {
        const message = e?.response?.data?.error || e?.message || t('learning', 'Failed to save translations')
        this.error = message
        showError(message)
      } finally {
        this.saving = false
      }
    },
  },
}
</script>

<style scoped>
.translation-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--color-text-maxcontrast);
  min-height: 140px;
}

.translation-dialog {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-height: 70vh;
  overflow-y: auto;
  padding-right: 8px;
}

.original-block,
.translation-section {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 16px;
  background: var(--color-main-background);
}

.original-block h4,
.translation-section h4 {
  margin: 0 0 12px;
  color: var(--color-main-text);
}

.original-question {
  font-size: 15px;
  line-height: 1.6;
  margin-bottom: 10px;
  color: var(--color-main-text);
}

.original-explanation {
  font-size: 14px;
  line-height: 1.5;
  margin-bottom: 10px;
}

.original-answers,
.answer-translation-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.original-answer {
  padding: 10px 12px;
  border-radius: 8px;
  background: var(--color-background-hover);
  color: var(--color-main-text);
}

.form-group,
.answer-translation-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 12px;
}

.nc-input {
  width: 100%;
  box-sizing: border-box;
}
</style>
