<template>
  <div class="pbq-dropdown">
    <div v-for="q in config.questions" :key="q.id" class="pbq-dd-row">
      <label class="pbq-dd-label">{{ q.label }}</label>
      <select
        :value="value[q.id] || ''"
        :disabled="disabled"
        @change="$emit('update', String(q.id), $event.target.value)"
        class="pbq-dd-select"
      >
        <option value="" disabled>— Select —</option>
        <option v-for="opt in q.options" :key="opt" :value="opt">{{ opt }}</option>
      </select>
    </div>
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
}
</script>

<style scoped>
.pbq-dropdown { margin-top: 16px; }
.pbq-dd-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.pbq-dd-label { flex: 1; min-width: 200px; font-weight: 500; }
.pbq-dd-select { flex: 1; min-width: 160px; padding: 6px 10px; border: 1px solid var(--color-border); border-radius: 6px; background: var(--color-main-background); color: var(--color-main-text); }
</style>
