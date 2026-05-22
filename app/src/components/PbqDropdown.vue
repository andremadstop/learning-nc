<template>
  <div class="pbq-dropdown">
    <section
      v-for="section in sections"
      :key="section.id"
      class="pbq-section"
      :class="{ 'pbq-section--cards': config.layout === 'cards' }"
    >
      <header v-if="section.title || section.note" class="pbq-section__header">
        <h3 v-if="section.title">{{ section.title }}</h3>
        <p v-if="section.note" :class="{ 'pbq-section__log': section.note_format === 'log' }">{{ section.note }}</p>
      </header>

      <div v-if="section.questions.length" class="pbq-section__body">
        <div v-for="q in section.questions" :key="q.id" class="pbq-dd-row" :class="rowClasses(q)">
          <div class="pbq-dd-copy">
            <label class="pbq-dd-label">{{ q.label }}</label>
            <p v-if="q.help_text" class="pbq-dd-help">{{ q.help_text }}</p>
            <p v-if="q.current_value !== undefined" class="pbq-dd-current">Current: {{ q.current_value }}</p>
          </div>

          <div class="pbq-dd-control">
            <span v-if="q.readonly_value !== undefined" class="pbq-dd-readonly">{{ q.readonly_value }}</span>
            <select
              v-else
              :value="currentValue(q)"
              :disabled="disabled"
              @change="$emit('update', String(q.id), $event.target.value)"
              class="pbq-dd-select"
            >
              <option value="" disabled>{{ q.placeholder || '— Select —' }}</option>
              <option v-for="opt in q.options || []" :key="opt" :value="opt">{{ opt }}</option>
            </select>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script>
export default {
  name: 'PbqDropdown',
  props: {
    config: { type: Object, required: true },
    value: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
  },
  computed: {
    sections() {
      const questions = this.config.questions || []
      const definedSections = this.config.sections || []

      if (!definedSections.length) {
        return [{ id: 'default', title: '', note: '', questions }]
      }

      return definedSections.map(section => ({
        ...section,
        questions: questions.filter(question => (question.section || 'default') === section.id),
      }))
    },
  },
  methods: {
    currentValue(question) {
      return this.value[question.id] || question.initial_value || ''
    },
    rowClasses(question) {
      if (!this.disabled || question.correct === undefined || question.scorable === false) {
        return {}
      }
      const actual = this.currentValue(question)
      return {
        'pbq-dd-row--correct': actual === question.correct,
        'pbq-dd-row--wrong': actual !== '' && actual !== question.correct,
      }
    },
  },
}
</script>

<style scoped>
.pbq-dropdown {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.pbq-section {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  overflow: hidden;
  background: var(--color-main-background);
}

.pbq-section--cards {
  background: color-mix(in srgb, var(--color-primary-element) 4%, var(--color-main-background));
}

.pbq-section__header {
  padding: 14px 16px;
  background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background));
  border-bottom: 1px solid var(--color-border);
}

.pbq-section__header h3 {
  margin: 0;
  font-size: 16px;
  color: var(--color-main-text);
}

.pbq-section__header p {
  margin: 6px 0 0;
  color: var(--color-main-text);
  font-size: 13px;
  white-space: pre-wrap;
  font-family: var(--font-face, inherit);
}

.pbq-section__header p.pbq-section__log {
  font-family: ui-monospace, "SF Mono", Consolas, monospace;
  font-size: 12px;
  line-height: 1.5;
  background: color-mix(in srgb, var(--color-main-text) 4%, transparent);
  padding: 8px 10px;
  border-radius: 4px;
  overflow-x: auto;
}

.pbq-section__body {
  padding: 14px 16px;
}

.pbq-dd-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 12px 0;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 55%, transparent);
}

.pbq-dd-row:last-child {
  border-bottom: none;
}

.pbq-dd-row--correct {
  background: color-mix(in srgb, var(--color-success) 8%, transparent);
}

.pbq-dd-row--wrong {
  background: color-mix(in srgb, var(--color-error) 8%, transparent);
}

.pbq-dd-copy {
  flex: 1;
  min-width: 220px;
}

.pbq-dd-control {
  min-width: 220px;
}

.pbq-dd-label {
  display: block;
  font-weight: 700;
  color: var(--color-main-text);
}

.pbq-dd-help,
.pbq-dd-current {
  margin: 6px 0 0;
  color: var(--color-text-maxcontrast);
  font-size: 13px;
}

.pbq-dd-current {
  font-family: monospace;
}

.pbq-dd-select,
.pbq-dd-readonly {
  display: block;
  width: 100%;
  box-sizing: border-box;
  padding: 8px 10px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-main-background);
  color: var(--color-main-text);
}

.pbq-dd-readonly {
  background: var(--color-background-hover);
  font-weight: 600;
}

@media (max-width: 700px) {
  .pbq-dd-row {
    flex-direction: column;
  }

  .pbq-dd-control {
    width: 100%;
    min-width: 0;
  }
}
</style>
