<template>
  <div class="pbq-routing-config">
    <div class="pbq-routing-tabs">
      <button
        v-for="router in config.routers || []"
        :key="router.id"
        class="pbq-routing-tab"
        :class="{ 'pbq-routing-tab--active': activeRouterId === router.id }"
        @click="activeRouterId = router.id"
      >
        {{ router.label }}
      </button>
    </div>

    <div v-if="activeRouter" class="pbq-routing-panel">
      <div class="pbq-routing-panel__header">
        <h3>{{ activeRouter.label }}</h3>
        <p>{{ activeRouter.summary }}</p>
      </div>

      <div class="pbq-routing-panel__body">
        <div class="pbq-routing-table">
          <h4>Routing Table</h4>
          <pre>{{ activeRouter.routing_table }}</pre>
        </div>

        <div class="pbq-routing-form">
          <h4>Routing Configuration</h4>

          <label class="pbq-routing-field">
            <span>Was a problem found?</span>
            <select v-model="draft.problem_found" :disabled="disabled">
              <option v-for="option in ['Yes', 'No']" :key="option" :value="option">{{ option }}</option>
            </select>
          </label>

          <label class="pbq-routing-field">
            <span>Destination Prefix</span>
            <input v-model="draft.destination_prefix" :disabled="disabled" type="text" />
          </label>

          <label class="pbq-routing-field">
            <span>Destination Prefix Mask</span>
            <input v-model="draft.destination_prefix_mask" :disabled="disabled" type="text" />
          </label>

          <label class="pbq-routing-field">
            <span>Interface</span>
            <select v-model="draft.interface" :disabled="disabled">
              <option value="">Select interface</option>
              <option v-for="option in activeRouter.interface_options || []" :key="option" :value="option">{{ option }}</option>
            </select>
          </label>
        </div>
      </div>

      <div class="pbq-routing-actions">
        <button class="pbq-routing-action pbq-routing-action--secondary" @click="resetDraft" :disabled="disabled">Reset Router</button>
        <button class="pbq-routing-action" @click="saveDraft" :disabled="disabled">Save Router</button>
      </div>
    </div>
  </div>
</template>

<script>
function cloneRouterState(state) {
  return {
    problem_found: state.problem_found || 'No',
    destination_prefix: state.destination_prefix || '',
    destination_prefix_mask: state.destination_prefix_mask || '',
    interface: state.interface || '',
  }
}

export default {
  name: 'PbqRoutingConfig',
  props: {
    config: { type: Object, required: true },
    value: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
  },
  data() {
    const firstRouter = (this.config.routers || [])[0] || null
    return {
      activeRouterId: firstRouter ? firstRouter.id : null,
      draft: cloneRouterState(firstRouter ? (firstRouter.initial || {}) : {}),
    }
  },
  computed: {
    activeRouter() {
      return (this.config.routers || []).find(router => router.id === this.activeRouterId) || null
    },
  },
  watch: {
    activeRouterId() {
      this.resetDraft()
    },
  },
  methods: {
    currentState(router) {
      const fallback = cloneRouterState(router.initial || {})
      const answer = this.value[router.id]
      if (!answer) {
        return fallback
      }
      return {
        problem_found: answer.problem_found || fallback.problem_found,
        destination_prefix: answer.destination_prefix || fallback.destination_prefix,
        destination_prefix_mask: answer.destination_prefix_mask || fallback.destination_prefix_mask,
        interface: answer.interface || fallback.interface,
      }
    },
    resetDraft() {
      if (!this.activeRouter) return
      this.draft = cloneRouterState(this.currentState(this.activeRouter))
    },
    saveDraft() {
      if (!this.activeRouter) return
      this.$emit('update', this.activeRouter.id, cloneRouterState(this.draft))
    },
  },
}
</script>

<style scoped>
.pbq-routing-config {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.pbq-routing-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.pbq-routing-tab {
  border: 1px solid #bdd7f3;
  background: #fff;
  color: #16314e;
  border-radius: 999px;
  padding: 7px 14px;
  cursor: pointer;
  font-weight: 700;
}

.pbq-routing-tab--active {
  background: #0a7fe8;
  border-color: #0a7fe8;
  color: #fff;
}

.pbq-routing-panel {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  overflow: hidden;
  background: #f7fbff;
}

.pbq-routing-panel__header {
  background: #0a7fe8;
  color: #fff;
  padding: 14px 16px;
}

.pbq-routing-panel__header h3 {
  margin: 0;
}

.pbq-routing-panel__header p {
  margin: 6px 0 0;
  font-size: 13px;
  opacity: .92;
}

.pbq-routing-panel__body {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(280px, .9fr);
  gap: 16px;
  padding: 16px;
}

.pbq-routing-table,
.pbq-routing-form {
  border: 1px solid #d5e6f7;
  border-radius: 10px;
  background: #fff;
  padding: 14px;
}

.pbq-routing-table h4,
.pbq-routing-form h4 {
  margin: 0 0 12px;
  color: #16314e;
}

.pbq-routing-table pre {
  margin: 0;
  background: #14181e;
  color: #d6dee7;
  border-radius: 8px;
  padding: 14px;
  overflow: auto;
  font-size: 12px;
  line-height: 1.45;
}

.pbq-routing-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.pbq-routing-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.pbq-routing-field span {
  font-weight: 700;
  color: #16314e;
}

.pbq-routing-field input,
.pbq-routing-field select {
  border: 1px solid #bdd7f3;
  border-radius: 8px;
  padding: 8px 10px;
  background: #fff;
}

.pbq-routing-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 0 16px 16px;
}

.pbq-routing-action {
  border: none;
  border-radius: 999px;
  padding: 9px 16px;
  background: #0a7fe8;
  color: #fff;
  cursor: pointer;
  font-weight: 700;
}

.pbq-routing-action--secondary {
  background: #dae8f8;
  color: #16314e;
}

@media (max-width: 900px) {
  .pbq-routing-panel__body {
    grid-template-columns: 1fr;
  }
}
</style>
