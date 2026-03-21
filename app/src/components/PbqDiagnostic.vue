<template>
  <div class="pbq-diagnostic">
    <div v-for="finding in config.findings || []" :key="finding.id" class="pbq-diagnostic-card">
      <div class="pbq-diagnostic-card__header">
        <h3>{{ finding.label }}</h3>
        <p v-if="finding.hint">{{ finding.hint }}</p>
      </div>

      <div class="pbq-diagnostic-card__fields">
        <label class="pbq-diagnostic-field">
          <span>Component</span>
          <select :value="stateFor(finding).component" :disabled="disabled" @change="updateFinding(finding, 'component', $event.target.value)">
            <option value="">Select Device/Cable</option>
            <option v-for="option in finding.component_options || []" :key="option" :value="option">{{ option }}</option>
          </select>
        </label>

        <label class="pbq-diagnostic-field">
          <span>Problem</span>
          <select :value="stateFor(finding).problem" :disabled="disabled" @change="updateFinding(finding, 'problem', $event.target.value)">
            <option value="">Select a problem</option>
            <option v-for="option in finding.problem_options || []" :key="option" :value="option">{{ option }}</option>
          </select>
        </label>

        <label class="pbq-diagnostic-field">
          <span>Solution</span>
          <select :value="stateFor(finding).solution" :disabled="disabled" @change="updateFinding(finding, 'solution', $event.target.value)">
            <option value="">Select a solution</option>
            <option v-for="option in finding.solution_options || []" :key="option" :value="option">{{ option }}</option>
          </select>
        </label>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'PbqDiagnostic',
  props: {
    config: { type: Object, required: true },
    value: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
  },
  methods: {
    stateFor(finding) {
      return this.value[finding.id] || { component: '', problem: '', solution: '' }
    },
    updateFinding(finding, field, newValue) {
      const current = this.stateFor(finding)
      this.$emit('update', finding.id, {
        ...current,
        [field]: newValue,
      })
    },
  },
}
</script>

<style scoped>
.pbq-diagnostic {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.pbq-diagnostic-card {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  overflow: hidden;
  background: #f7fbff;
}

.pbq-diagnostic-card__header {
  padding: 12px 16px;
  background: #0a7fe8;
  color: #fff;
}

.pbq-diagnostic-card__header h3 {
  margin: 0;
  font-size: 16px;
}

.pbq-diagnostic-card__header p {
  margin: 6px 0 0;
  font-size: 13px;
  opacity: .92;
}

.pbq-diagnostic-card__fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  padding: 16px;
}

.pbq-diagnostic-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.pbq-diagnostic-field span {
  font-weight: 700;
  color: #16314e;
}

.pbq-diagnostic-field select {
  border: 1px solid #bdd7f3;
  border-radius: 8px;
  padding: 8px 10px;
  background: #fff;
}
</style>
