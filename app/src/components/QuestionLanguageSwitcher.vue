<template>
  <div
    v-if="isVisible"
    class="question-language-switcher"
    :aria-label="t('learning', 'Question language')"
    role="group"
  >
    <button
      v-for="option in normalizedOptions"
      :key="option.value"
      type="button"
      class="lang-btn"
      :class="{ active: modelValue === option.value }"
      :aria-pressed="modelValue === option.value ? 'true' : 'false'"
      @click="$emit('input', option.value)"
    >
      {{ option.label }}
    </button>
  </div>
</template>

<script>
export default {
  name: 'QuestionLanguageSwitcher',
  props: {
    value: {
      type: String,
      default: '',
    },
    question: {
      type: Object,
      default: null,
    },
    options: {
      type: Array,
      default: null,
    },
  },
  computed: {
    modelValue() {
      return this.value || '';
    },
    normalizedOptions() {
      if (Array.isArray(this.options) && this.options.length > 0) {
        return this.options;
      }

      const translations = this.question?.translations;
      if (!translations
        || (Array.isArray(translations) && translations.length === 0)
        || (typeof translations === 'object' && !Array.isArray(translations) && Object.keys(translations).length === 0)) {
        return [];
      }

      const labels = {
        '': 'DE',
        de: 'DE',
        en: 'EN',
        ru: 'RU',
        ar: 'AR',
      };
      const languages = new Set(['']);
      const addLanguage = (lang) => {
        if (typeof lang !== 'string') {
          return;
        }
        const normalized = lang.trim().toLowerCase();
        if (!normalized) {
          return;
        }
        languages.add(normalized === 'de' ? '' : normalized);
      };

      if (Array.isArray(translations)) {
        translations.forEach((entry) => {
          if (typeof entry === 'string') {
            addLanguage(entry);
            return;
          }
          if (entry && typeof entry === 'object') {
            addLanguage(entry.lang || entry.value || entry.code);
          }
        });
      } else if (typeof translations === 'object') {
        Object.keys(translations).forEach(addLanguage);
      }

      return Array.from(languages)
        .filter((lang) => lang === '' || labels[lang] || /^[a-z]{2}$/u.test(lang))
        .map((lang) => ({
          value: lang,
          label: labels[lang] || lang.toUpperCase(),
        }));
    },
    isVisible() {
      return this.normalizedOptions.length > 1;
    },
  },
};
</script>

<style scoped>
.question-language-switcher {
  position: absolute;
  top: 6px;
  right: 6px;
  display: inline-flex;
  gap: 2px;
  z-index: 3;
  padding: 0;
  background: transparent;
  backdrop-filter: none;
}

.lang-btn {
  min-width: 20px;
  border: 0;
  border-radius: 999px;
  padding: 1px 4px;
  font-size: 7px;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: #d7e7ff;
  background: rgba(59, 130, 246, 0.22);
  cursor: pointer;
}

.lang-btn.active {
  color: #08111f;
  background: #8cc9ff;
}

.lang-btn:focus-visible {
  outline: 2px solid #8cc9ff;
  outline-offset: 2px;
}
</style>
