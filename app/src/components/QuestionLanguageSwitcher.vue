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
  top: 12px;
  right: 12px;
  display: inline-flex;
  gap: 6px;
  z-index: 3;
  padding: 6px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.82);
  backdrop-filter: blur(8px);
}

.lang-btn {
  min-width: 38px;
  border: 0;
  border-radius: 999px;
  padding: 6px 10px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: #d7e7ff;
  background: rgba(59, 130, 246, 0.18);
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
