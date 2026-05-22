<template>
  <div class="pbq-ranking">
    <div v-if="config.intro_note" class="pbq-ranking__intro">{{ config.intro_note }}</div>

    <ol class="pbq-ranking__list">
      <li
        v-for="(item, idx) in orderedItems"
        :key="item.id"
        class="pbq-ranking__item"
        :class="rowClasses(item, idx)"
        :draggable="!disabled"
        @dragstart="onDragStart($event, idx)"
        @dragover.prevent="onDragOver($event, idx)"
        @drop.prevent="onDrop($event, idx)"
        @dragend="onDragEnd"
      >
        <span class="pbq-ranking__pos">{{ idx + 1 }}</span>
        <span class="pbq-ranking__label">{{ item.label }}</span>
        <span v-if="!disabled" class="pbq-ranking__grip" aria-hidden="true">⋮⋮</span>
        <span v-if="disabled && resultIcon(item, idx)" class="pbq-ranking__result">{{ resultIcon(item, idx) }}</span>
      </li>
    </ol>

    <div v-if="!disabled" class="pbq-ranking__controls">
      <button class="pbq-ranking__btn" @click="shuffleItems">Shuffle</button>
      <span class="pbq-ranking__hint">Tipp: Items per Drag &amp; Drop in die richtige Reihenfolge ziehen.</span>
    </div>

    <div v-if="disabled && scoringSummary" class="pbq-ranking__summary">{{ scoringSummary }}</div>
  </div>
</template>

<script>
export default {
  name: 'PbqRanking',
  props: {
    config: { type: Object, required: true },
    value: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
  },
  data() {
    return {
      order: this.computeInitialOrder(),
      dragIdx: null,
    }
  },
  computed: {
    items() {
      return (this.config.items || [])
    },
    orderedItems() {
      const map = Object.fromEntries(this.items.map(i => [i.id, i]))
      return this.order.map(id => map[id]).filter(Boolean)
    },
    scoringSummary() {
      if (!this.disabled) return ''
      const total = this.items.length
      let correct = 0
      this.orderedItems.forEach((item, idx) => {
        if ((this.value[item.id] ?? (idx + 1)) === item.correct_position) correct++
      })
      return `${correct}/${total} an korrekter Position`
    },
  },
  watch: {
    value: {
      deep: true,
      handler() {
        // External value reset → re-derive order from value
        if (Object.keys(this.value || {}).length > 0) {
          this.order = this.deriveOrderFromValue()
        }
      },
    },
  },
  methods: {
    computeInitialOrder() {
      const items = this.config.items || []
      if (this.value && Object.keys(this.value).length === items.length) {
        return this.deriveOrderFromValue()
      }
      // Initial state: keep author-supplied order (which should be shuffled in pbq_config)
      return items.map(i => i.id)
    },
    deriveOrderFromValue() {
      const items = this.config.items || []
      // value is {itemId: position1based}
      const sorted = [...items].sort((a, b) => {
        const pa = this.value[a.id] ?? 999
        const pb = this.value[b.id] ?? 999
        return pa - pb
      })
      return sorted.map(i => i.id)
    },
    emitOrder() {
      const update = {}
      this.order.forEach((id, idx) => {
        update[id] = idx + 1
      })
      // Emit one event per item (matches dropdown convention)
      Object.entries(update).forEach(([id, pos]) => {
        this.$emit('update', id, pos)
      })
    },
    onDragStart(ev, idx) {
      if (this.disabled) return
      this.dragIdx = idx
      ev.dataTransfer.effectAllowed = 'move'
      try { ev.dataTransfer.setData('text/plain', String(idx)) } catch (e) { /* Safari */ }
    },
    onDragOver(ev, idx) {
      if (this.disabled) return
      ev.dataTransfer.dropEffect = 'move'
    },
    onDrop(ev, dropIdx) {
      if (this.disabled || this.dragIdx === null) return
      const from = this.dragIdx
      const to = dropIdx
      if (from === to) { this.dragIdx = null; return }
      const newOrder = [...this.order]
      const [moved] = newOrder.splice(from, 1)
      newOrder.splice(to, 0, moved)
      this.order = newOrder
      this.dragIdx = null
      this.emitOrder()
    },
    onDragEnd() {
      this.dragIdx = null
    },
    shuffleItems() {
      const arr = [...this.order]
      for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1))
        ;[arr[i], arr[j]] = [arr[j], arr[i]]
      }
      this.order = arr
      this.emitOrder()
    },
    rowClasses(item, idx) {
      if (!this.disabled) return {}
      const correct = item.correct_position === (idx + 1)
      return {
        'pbq-ranking__item--correct': correct,
        'pbq-ranking__item--wrong': !correct,
      }
    },
    resultIcon(item, idx) {
      if (!this.disabled) return ''
      return item.correct_position === (idx + 1) ? '✓' : `✗ (→ ${item.correct_position})`
    },
  },
}
</script>

<style scoped>
.pbq-ranking {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.pbq-ranking__intro {
  color: var(--color-text-maxcontrast);
  font-size: 13px;
  padding: 0 4px;
}

.pbq-ranking__list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.pbq-ranking__item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-main-background);
  cursor: grab;
  user-select: none;
  transition: background 0.12s, border-color 0.12s;
}

.pbq-ranking__item:active {
  cursor: grabbing;
}

.pbq-ranking__item:hover:not(.pbq-ranking__item--correct):not(.pbq-ranking__item--wrong) {
  background: color-mix(in srgb, var(--color-primary-element) 6%, var(--color-main-background));
}

.pbq-ranking__item--correct {
  background: color-mix(in srgb, var(--color-success) 12%, var(--color-main-background));
  border-color: var(--color-success);
  cursor: default;
}

.pbq-ranking__item--wrong {
  background: color-mix(in srgb, var(--color-error) 8%, var(--color-main-background));
  border-color: var(--color-error);
  cursor: default;
}

.pbq-ranking__pos {
  flex: 0 0 28px;
  height: 28px;
  border-radius: 14px;
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 13px;
}

.pbq-ranking__label {
  flex: 1;
  font-size: 14px;
  color: var(--color-main-text);
}

.pbq-ranking__grip {
  color: var(--color-text-maxcontrast);
  font-size: 18px;
  letter-spacing: -3px;
  flex: 0 0 auto;
}

.pbq-ranking__result {
  font-weight: 700;
  font-size: 13px;
  flex: 0 0 auto;
}

.pbq-ranking__item--correct .pbq-ranking__result {
  color: var(--color-success);
}

.pbq-ranking__item--wrong .pbq-ranking__result {
  color: var(--color-error);
}

.pbq-ranking__controls {
  display: flex;
  gap: 12px;
  align-items: center;
  padding-top: 4px;
}

.pbq-ranking__btn {
  padding: 6px 14px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-main-background);
  color: var(--color-main-text);
  cursor: pointer;
  font-size: 13px;
}

.pbq-ranking__btn:hover {
  background: color-mix(in srgb, var(--color-primary-element) 8%, var(--color-main-background));
}

.pbq-ranking__hint {
  color: var(--color-text-maxcontrast);
  font-size: 12px;
}

.pbq-ranking__summary {
  margin-top: 8px;
  padding: 8px 14px;
  border-radius: 6px;
  background: color-mix(in srgb, var(--color-primary-element) 8%, var(--color-main-background));
  font-size: 13px;
  color: var(--color-main-text);
}
</style>
