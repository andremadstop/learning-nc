<template>
  <div class="pbq-switch-config">
    <div class="pbq-switch-columns">
      <section v-for="device in config.switches || []" :key="device.id" class="pbq-switch-card">
        <header class="pbq-switch-card__header">
          <div>
            <h3>{{ device.label }}</h3>
            <p v-if="device.subtitle">{{ device.subtitle }}</p>
          </div>
        </header>

        <div class="pbq-switch-port-grid">
          <button
            v-for="port in device.ports || []"
            :key="port.id"
            class="pbq-port-chip"
            :class="portClasses(port)"
            :disabled="disabled"
            @click="openPort(port, device.label)"
          >
            <span class="pbq-port-chip__label">{{ port.label }}</span>
            <span class="pbq-port-chip__meta">{{ port.role }}</span>
          </button>
        </div>
      </section>
    </div>

    <div v-if="activePort" class="pbq-port-editor">
      <div class="pbq-port-editor__header">
        <div>
          <h3>{{ activePort.label }} · {{ activePort.parentLabel }}</h3>
          <p>{{ activePort.role }}</p>
        </div>
      </div>

      <div class="pbq-port-editor__fields">
        <label class="pbq-port-field">
          <span>Status</span>
          <select v-model="draft.status" :disabled="disabled">
            <option v-for="option in config.status_options || ['Enabled', 'Disabled']" :key="option" :value="option">{{ option }}</option>
          </select>
        </label>

        <label class="pbq-port-field">
          <span>LACP</span>
          <select v-model="draft.lacp" :disabled="disabled">
            <option v-for="option in config.lacp_options || ['Enabled', 'Disabled']" :key="option" :value="option">{{ option }}</option>
          </select>
        </label>
      </div>

      <div class="pbq-port-vlan-block">
        <h4>VLAN Configuration</h4>
        <div class="pbq-port-vlan-grid">
          <label v-for="vlan in vlanCatalog" :key="vlan.id" class="pbq-port-field">
            <span>{{ vlan.id }} · {{ vlan.name }}</span>
            <select v-model="draft.vlans[vlan.id]" :disabled="disabled">
              <option value="none">Not included</option>
              <option value="untagged">Untagged</option>
              <option value="tagged">Tagged</option>
            </select>
          </label>
        </div>
      </div>

      <div v-if="activePort.notes" class="pbq-port-notes">{{ activePort.notes }}</div>

      <div class="pbq-port-editor__actions">
        <button class="pbq-port-action pbq-port-action--secondary" @click="resetDraft" :disabled="disabled">{{ t('learning', 'Port zurücksetzen') }}</button>
        <button class="pbq-port-action" @click="saveDraft" :disabled="disabled">{{ t('learning', 'Port speichern') }}</button>
      </div>
    </div>
  </div>
</template>

<script>
function cloneState(state) {
  return {
    status: state.status || 'Enabled',
    lacp: state.lacp || 'Disabled',
    vlans: { ...(state.vlans || {}) },
  }
}

export default {
  name: 'PbqSwitchConfig',
  props: {
    config: { type: Object, required: true },
    value: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
  },
  data() {
    return {
      activePort: null,
      draft: { status: 'Enabled', lacp: 'Disabled', vlans: {} },
    }
  },
  computed: {
    vlanCatalog() {
      return this.config.vlan_catalog || []
    },
  },
  methods: {
    currentState(port) {
      const fallback = cloneState(port.initial || {})
      const answer = this.value[port.id]
      if (!answer) {
        return fallback
      }
      return {
        status: answer.status || fallback.status,
        lacp: answer.lacp || fallback.lacp,
        vlans: { ...fallback.vlans, ...(answer.vlans || {}) },
      }
    },
    isCorrect(port) {
      if (!this.disabled || !port.correct) {
        return false
      }
      const state = this.currentState(port)
      if ((port.correct.status || 'Enabled') !== state.status) {
        return false
      }
      if ((port.correct.lacp || 'Disabled') !== state.lacp) {
        return false
      }
      const expectedVlans = port.correct.vlans || {}
      return Object.keys(expectedVlans).every(vlanId => (state.vlans[vlanId] || 'none') === expectedVlans[vlanId])
    },
    portClasses(port) {
      return {
        'pbq-port-chip--active': this.activePort && this.activePort.id === port.id,
        'pbq-port-chip--configured': !!this.value[port.id],
        'pbq-port-chip--correct': this.isCorrect(port),
        'pbq-port-chip--disabled': this.disabled && !this.isCorrect(port) && !!port.correct,
      }
    },
    openPort(port, parentLabel = null) {
      this.activePort = {
        ...port,
        parentLabel: parentLabel || port.parentLabel || '',
      }
      this.draft = cloneState(this.currentState(port))
    },
    resetDraft() {
      if (!this.activePort) return
      this.draft = cloneState(this.activePort.initial || {})
    },
    saveDraft() {
      if (!this.activePort) return
      this.$emit('update', this.activePort.id, cloneState(this.draft))
    },
  },
  created() {
    const firstSwitch = (this.config.switches || [])[0]
    const firstPort = firstSwitch && (firstSwitch.ports || [])[0]
    if (firstPort) {
      this.openPort(firstPort, firstSwitch.label)
    }
  },
}
</script>

<style scoped>
.pbq-switch-config {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.pbq-switch-columns {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 16px;
}

.pbq-switch-card,
.pbq-port-editor {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: #f7fbff;
  box-shadow: 0 6px 22px rgba(24, 67, 120, .08);
}

.pbq-switch-card__header,
.pbq-port-editor__header {
  padding: 14px 16px;
  background: #0a7fe8;
  color: #fff;
  border-radius: 12px 12px 0 0;
}

.pbq-switch-card__header h3,
.pbq-port-editor__header h3 {
  margin: 0;
  font-size: 18px;
}

.pbq-switch-card__header p,
.pbq-port-editor__header p {
  margin: 4px 0 0;
  opacity: .9;
  font-size: 13px;
}

.pbq-switch-port-grid {
  padding: 16px;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.pbq-port-chip {
  text-align: left;
  padding: 12px;
  border-radius: 10px;
  border: 1px solid #bdd7f3;
  background: #fff;
  cursor: pointer;
  transition: transform .12s ease, border-color .12s ease, box-shadow .12s ease;
}

.pbq-port-chip:hover {
  transform: translateY(-1px);
  border-color: #0a7fe8;
  box-shadow: 0 6px 16px rgba(10, 127, 232, .12);
}

.pbq-port-chip--active {
  border-color: #0a7fe8;
  box-shadow: 0 0 0 2px rgba(10, 127, 232, .16);
}

.pbq-port-chip--configured {
  border-color: #3aa26d;
}

.pbq-port-chip--correct {
  background: #edf9f1;
  border-color: #3aa26d;
}

.pbq-port-chip--disabled {
  background: #fff3f2;
  border-color: #df6854;
}

.pbq-port-chip__label {
  display: block;
  font-weight: 700;
  color: #16314e;
}

.pbq-port-chip__meta {
  display: block;
  margin-top: 4px;
  color: #4d6a86;
  font-size: 12px;
}

.pbq-port-editor__fields,
.pbq-port-vlan-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
}

.pbq-port-editor {
  overflow: hidden;
}

.pbq-port-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.pbq-port-field span,
.pbq-port-vlan-block h4 {
  color: #16314e;
  font-weight: 700;
}

.pbq-port-field select {
  border: 1px solid #bdd7f3;
  border-radius: 8px;
  padding: 8px 10px;
  background: #fff;
  color: var(--color-main-text);
}

.pbq-port-editor__fields,
.pbq-port-vlan-block,
.pbq-port-notes,
.pbq-port-editor__actions {
  padding: 16px;
}

.pbq-port-vlan-block {
  border-top: 1px solid #d5e6f7;
}

.pbq-port-vlan-block h4 {
  margin: 0 0 12px;
}

.pbq-port-notes {
  color: #38506c;
  font-size: 13px;
  border-top: 1px solid #d5e6f7;
}

.pbq-port-editor__actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  border-top: 1px solid #d5e6f7;
}

.pbq-port-action {
  border: none;
  border-radius: 999px;
  padding: 9px 16px;
  background: #0a7fe8;
  color: #fff;
  cursor: pointer;
  font-weight: 700;
}

.pbq-port-action--secondary {
  background: #dae8f8;
  color: #16314e;
}
</style>
