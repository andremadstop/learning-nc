<template>
  <div class="pool-import-messages">
    <!-- Native details/summary: expandable by keyboard and announced by screen readers. -->
    <details v-if="pool.errors.length > 0" class="pool-import-messages__errors">
      <summary>{{ t('learning', 'Errors: {n}', { n: pool.errors.length }) }}</summary>
      <ul>
        <li v-for="(message, i) in pool.errors" :key="'e' + i">{{ message }}</li>
      </ul>
    </details>
    <details v-if="pool.warnings.length > 0" class="pool-import-messages__warnings">
      <summary>{{ t('learning', 'Warnings: {n}', { n: pool.warnings.length }) }}</summary>
      <ul>
        <li v-for="(message, i) in pool.warnings" :key="'w' + i">{{ message }}</li>
      </ul>
    </details>
    <span v-if="pool.errors.length === 0 && pool.warnings.length === 0" class="pool-import-messages__none">—</span>
  </div>
</template>

<script>
/** Errors and warnings of one pool in the pool import (preview and result), collapsed. */
export default {
  name: 'PoolImportMessages',
  props: {
    pool: { type: Object, required: true },
  },
};
</script>

<style scoped>
.pool-import-messages details { margin-bottom: 4px; }
.pool-import-messages summary { cursor: pointer; }
.pool-import-messages ul { margin: 4px 0 0; padding-inline-start: 18px; }
.pool-import-messages__errors summary { color: var(--color-error-text); }
.pool-import-messages__warnings summary { color: var(--color-warning-text); }
.pool-import-messages__none { color: var(--color-text-maxcontrast); }
</style>
