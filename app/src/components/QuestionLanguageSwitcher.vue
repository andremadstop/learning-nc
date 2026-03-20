<template>
  <div class="question-language-switcher" :aria-label="t('learning', 'Question language')" role="group">
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
    options: {
      type: Array,
      default: () => ([
        { value: '', label: 'DE' },
        { value: 'en', label: 'EN' },
        { value: 'ru', label: 'RU' },
        { value: 'ar', label: 'AR' },
      ]),
    },
  },
  computed: {
    modelValue() {
      return this.value || '';
    },
    normalizedOptions() {
      return Array.isArray(this.options) ? this.options : [];
    },
  },
};
</script>

<style scoped>
.question-language-switcher {
  position: absolute;
  top: 8px;
  right: 8px;
  display: inline-flex;
  gap: 3px;
  z-index: 3;
  padding: 0;
  background: transparent;
  backdrop-filter: none;
}

.lang-btn {
  min-width: 28px;
  border: 0;
  border-radius: 999px;
  padding: 4px 7px;
  font-size: 9px;
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
